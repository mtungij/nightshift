<?php

namespace App\Services;

use App\Domain\BusinessUnitContext;
use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\GoodsReceipt;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ServiceTable;
use App\Models\Shift;
use App\Models\ShiftStockSnapshot;
use App\Models\StockAdjustment;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\VoidRequest;
use App\Models\WaiterLedgerEntry;
use App\Support\QuantityFormatter;
use App\Support\StockMovementPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(string $name, array $filters = []): array
    {
        app(BusinessUnitContext::class)->select(! empty($filters['business_unit_id']) ? (int) $filters['business_unit_id'] : null);
        if (! empty($filters['business_unit_id']) && ! empty($filters['branch_id'])) {
            app(StockService::class)->require((int) BusinessUnit::whereKey($filters['business_unit_id'])->value('branch_id') === (int) $filters['branch_id'], __('ui.Business Unit and branch must match.'));
        }
        $selectedShift = app(ReportShiftService::class)->resolve($filters, $name);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $result = $this->build($name, $filters, ($page - 1) * 100);
        foreach ($result['headers'] as $column => $header) {
            if (in_array($header, ['Quantity', 'Base quantity', 'Balance after', 'Units sold'], true)) {
                foreach ($result['rows'] as &$row) {
                    $row[$column] = QuantityFormatter::format($row[$column]);
                }
                unset($row);
            }
        }
        $result['has_more'] = count($result['rows']) > 100;
        $result['rows'] = array_slice($result['rows'], 0, 100);
        if (isset($result['values'])) {
            $result['values'] = array_slice($result['values'], 0, 100);
        }

        if ($selectedShift) {
            $result['shift_context'] = app(ReportShiftService::class)->context($selectedShift);
        }

        return [...$result, 'page' => $page, 'per_page' => 100];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function build(string $name, array $filters, int $offset): array
    {
        $name = $name === 'Salaries/Wages' ? 'Salaries and Wages' : $name;
        if (in_array($name, config('treasury.reports'), true)) {
            return app(CashCustodyReportService::class)->build($name, $filters, $offset);
        }
        Gate::authorize('view-reports');
        $visibility = app(StaffVisibility::class);
        $visibility->assertFilters($filters);
        if (in_array($name, ['Cashier Shift', 'Cash Shortage / Excess'], true)) {
            abort_unless($visibility->broad() || $visibility->settlementOnly(), 403);
        }
        if ($name === 'Waiter Settlement' && $visibility->settlementOnly()) {
            abort(403);
        }
        $branch = $filters['branch_id'] ?? null;
        if ($branch) {
            Branch::whereKey($branch)->firstOrFail();
        }
        $selectedShift = app(ReportShiftService::class)->resolve($filters, $name);
        $from = CarbonImmutable::parse($selectedShift->opened_at ?? $filters['from'] ?? now()->toDateString(), $filters['timezone'] ?? config('app.timezone'))->startOfDay()->timezone(config('app.timezone'));
        $to = CarbonImmutable::parse(($selectedShift ? ($selectedShift->closed_at ?? now()) : null) ?? $filters['to'] ?? now()->toDateString(), $filters['timezone'] ?? config('app.timezone'))->endOfDay()->timezone(config('app.timezone'));
        if (! $selectedShift && ($from > $to || $from->diffInDays($to) > 366)) {
            throw ValidationException::withMessages(['date' => 'Choose a date interval up to one year.']);
        }
        $orders = $visibility->orders(Order::query())->when(! $selectedShift, fn ($q) => $q->whereBetween('issued_at', [$from, $to]))->whereNotIn('status', ['VOIDED', 'CANCELLED', 'DRAFT', 'WAITING_FOR_ISSUE']);
        if ($selectedShift) {
            app(ReportShiftService::class)->orders($orders, $selectedShift);
        }
        if ($visibility->settlementOnly()) {
            $orders->where('issued_by', app(TenantContext::class)->user()->id);
        }
        if ($branch) {
            $orders->where('branch_id', $branch);
        }
        if ($name === 'Business Unit Performance') {
            $units = BusinessUnit::when($selectedShift, fn ($q) => $q->whereKey($selectedShift->business_unit_id))->when(! empty($filters['business_unit_id']), fn ($q) => $q->whereKey($filters['business_unit_id']))->when($branch, fn ($q) => $q->where('branch_id', $branch))->orderBy('id')->offset($offset)->limit(101)->get();

            return ['headers' => ['Business Unit', 'Sales', 'Cash collected', 'Outstanding', 'Orders', 'Stock movement', 'Active cashier shifts', 'Active waiter shifts'], 'rows' => $units->map(function ($unit) use ($orders, $from, $to, $filters, $selectedShift) {
                $contexts = fn ($type) => DB::table('operational_unit_contexts')->where('company_id', $unit->company_id)->where('business_unit_id', $unit->id)->where('record_type', $type)->select('record_id');
                $q = (clone $orders)->whereIn('orders.id', $contexts('orders'));
                foreach (['stock_location_id', 'waiter_id', 'issued_by'] as $field) {
                    if (! empty($filters[$field])) {
                        $q->where($field, $filters[$field]);
                    }
                }
                $cash = Payment::whereIn('id', $contexts('payments'))->when(! $selectedShift, fn ($q) => $q->whereBetween('created_at', [$from, $to]))->where('method', 'Cash')->where('is_credit', false);
                if ($selectedShift) {
                    app(ReportShiftService::class)->payments($cash, $selectedShift);
                }
                $shifts = Shift::whereIn('id', $contexts('shifts'))->where('status', 'OPEN')->when($selectedShift, fn ($q) => $q->whereKey($selectedShift->id));
                $moves = StockMovement::whereIn('id', $contexts('stock_movements'))->when(! $selectedShift, fn ($q) => $q->whereBetween('created_at', [$from, $to]));
                if ($selectedShift) {
                    $moves->where('shift_id', $selectedShift->id)->where('created_at', '<=', $selectedShift->closed_at ?? now());
                }
                if (! empty($filters['stock_location_id'])) {
                    $shifts->where('stock_location_id', $filters['stock_location_id']);
                    $moves->where('stock_location_id', $filters['stock_location_id']);
                    $cash->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', $unit->company_id)->where('record_type', 'payments')->where('stock_location_id', $filters['stock_location_id'])->select('record_id'));
                }

                return [$unit->name, (string) (clone $q)->sum('total'), (string) $cash->sum('amount'), (string) (clone $q)->sum('balance'), $q->count(), (string) $moves->sum('quantity'), (clone $shifts)->where('type', 'CASHIER')->count(), (clone $shifts)->where('type', 'WAITER')->count()];
            })->all()];
        }
        foreach (['waiter_id', 'stock_location_id', 'issued_by'] as $key) {
            if (! empty($filters[$key])) {
                $orders->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['product_id']) || ! empty($filters['category_id'])) {
            $matching = OrderItem::query();
            if (! empty($filters['product_id'])) {
                $matching->where('product_id', $filters['product_id']);
            }
            if (! empty($filters['category_id'])) {
                $matching->whereIn('product_id', Product::where('category_id', $filters['category_id'])->select('id'));
            }
            $orders->whereIn('id', $matching->select('order_id'));
        }
        if (in_array($name, ['Sales by Product', 'Sales by Category', 'Best Selling Products', 'Slow Moving Products', 'Profit Report'], true)) {
            $items = OrderItem::query()->whereIn('order_id', (clone $orders)->select('orders.id'));
            if (! empty($filters['product_id'])) {
                $items->where('product_id', $filters['product_id']);
            }
            $items->join('products', 'order_items.product_id', '=', 'products.id')->join('categories', 'products.category_id', '=', 'categories.id');
            if (! empty($filters['category_id'])) {
                $items->where('products.category_id', $filters['category_id']);
            }
            $returnedQuantity = 'order_items.returned_quantity';
            $returnedValue = 'order_items.returned_value';
            if ($selectedShift?->status === 'CLOSED') {
                $returns = DB::table('return_items')->where('company_id', $selectedShift->company_id)->where('created_at', '<=', $selectedShift->closed_at)->selectRaw('order_item_id, SUM(quantity) AS quantity, SUM(value) AS value')->groupBy('order_item_id');
                $items->leftJoinSub($returns, 'shift_returns', 'shift_returns.order_item_id', '=', 'order_items.id');
                $returnedQuantity = 'COALESCE(shift_returns.quantity,0)';
                $returnedValue = 'COALESCE(shift_returns.value,0)';
            }
            $key = $name === 'Sales by Category' ? 'categories.name' : 'order_items.product_name';
            $rows = $items->selectRaw("{$key} as label, SUM(order_items.quantity-{$returnedQuantity}) as quantity, SUM(order_items.total-order_items.discount_share-{$returnedValue}) as sales, SUM((order_items.base_quantity-{$returnedQuantity}*order_items.conversion_quantity)*order_items.purchase_cost) as cost")->groupBy($key)->orderBy('sales', $name === 'Slow Moving Products' ? 'asc' : 'desc')->orderBy($key)->offset($offset)->limit(101)->toBase()->get();

            return ['headers' => ['Product / Category', 'Units sold', 'Sales', 'Gross profit'], 'rows' => $rows->map(fn ($r) => [$r->label, (string) $r->quantity, D::money((string) $r->sales), D::sub((string) $r->sales, (string) $r->cost)])->all(), 'values' => $rows->map(fn ($r) => [$r->label, D::money((string) $r->sales)])->all()];
        }
        if (in_array($name, ['Waiter Outstanding', 'Waiter Settlement'], true)) {
            $q = $visibility->ledger(WaiterLedgerEntry::query());
            if (! empty($filters['stock_location_id'])) {
                $q->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', 'waiter_ledger_entries')->where('stock_location_id', $filters['stock_location_id'])->select('record_id'));
            }
            if ($branch) {
                $q->where('branch_id', $branch);
            }
            if ($selectedShift) {
                $q->where('created_at', '<=', $selectedShift->closed_at ?? now());
                if ($selectedShift->type === 'CASHIER' && $name === 'Waiter Outstanding') {
                    $q->whereIn('order_id', Order::where('issued_shift_id', $selectedShift->id)->select('id'));
                } elseif ($selectedShift->type === 'WAITER') {
                    $q->where('shift_id', $selectedShift->id);
                } else {
                    $q->where(fn ($q) => $q->whereIn('payment_id', Payment::where('shift_id', $selectedShift->id)->select('id'))->orWhere(fn ($q) => $q->whereNull('payment_id')->whereIn('order_id', Order::where('issued_shift_id', $selectedShift->id)->select('id'))));
                }
            }
            if (! $selectedShift && $name !== 'Waiter Outstanding') {
                $q->whereBetween('created_at', [$from, $to]);
            }
            if (! empty($filters['waiter_id'])) {
                $q->where('waiter_id', $filters['waiter_id']);
            }
            $rows = $q->selectRaw('waiter_id, SUM(debit) as debit, SUM(credit) as credit')->groupBy('waiter_id')->orderBy('waiter_id')->offset($offset)->limit(101)->get();

            if ($visibility->settlementOnly()) {
                return ['headers' => ['Waiter', 'Outstanding'], 'rows' => $rows->map(fn ($r) => [User::where('company_id', app(TenantContext::class)->companyId())->find($r->waiter_id)?->name, D::sub((string) $r->debit, (string) $r->credit)])->all()];
            }

            return ['headers' => ['Waiter', 'Debits', 'Credits', 'Outstanding'], 'rows' => $rows->map(fn ($r) => [User::where('company_id', app(TenantContext::class)->companyId())->find($r->waiter_id)?->name, (string) $r->debit, (string) $r->credit, D::sub((string) $r->debit, (string) $r->credit)])->all(), 'values' => $rows->map(fn ($r) => [User::whereKey($r->waiter_id)->first()?->name, D::sub((string) $r->debit, (string) $r->credit)])->all()];
        }
        if (in_array($name, ['Payment Method Report', 'Payments'], true)) {
            $q = $visibility->payments(Payment::when(! $selectedShift, fn ($q) => $q->whereBetween('created_at', [$from, $to]))->where('is_credit', false));
            if ($branch) {
                $q->where('branch_id', $branch);
            }
            if (! empty($filters['stock_location_id'])) {
                $q->whereIn('payments.id', DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', 'payments')->where('stock_location_id', $filters['stock_location_id'])->select('record_id'));
            }
            if (! empty($filters['issued_by'])) {
                $q->where('received_by', $filters['issued_by']);
            }
            if ($selectedShift) {
                app(ReportShiftService::class)->payments($q, $selectedShift);
            }
            $rows = $q->selectRaw('method, SUM(amount) as total')->groupBy('method')->orderBy('method')->offset($offset)->limit(101)->toBase()->get();

            return ['headers' => ['Method', 'Collected'], 'rows' => $rows->map(fn ($r) => [$r->method, D::money((string) $r->total)])->all(), 'values' => $rows->map(fn ($r) => [$r->method, D::money((string) $r->total)])->all()];
        }
        if (in_array($name, ['Opening Stock', 'Closing Stock', 'Stock Movement'], true)) {
            $q = $name === 'Stock Movement' ? StockMovement::query() : StockMovement::withoutGlobalScopes()->where('company_id', app(TenantContext::class)->companyId())->whereIn('stock_location_id', StockLocation::select('id'));
            if ($branch) {
                $q->where('branch_id', $branch);
            }
            foreach (['product_id', 'stock_location_id'] as $key) {
                if (! empty($filters[$key])) {
                    $q->where($key, $filters[$key]);
                }
            }
            if (! empty($filters['category_id'])) {
                $q->whereIn('product_id', Product::where('category_id', $filters['category_id'])->select('id'));
            }
            if ($selectedShift && $name !== 'Stock Movement') {
                $report = app(ShiftStockService::class)->report($selectedShift->id);
                $snapshot = ShiftStockSnapshot::where('shift_id', $selectedShift->id)->where('kind', 'OPENING')->firstOrFail();
                $lines = $name === 'Opening Stock' ? $snapshot->data['lines'] : $report['rows'];
                $rows = collect(is_array($lines) ? $lines : [])->filter(fn ($r) => (empty($filters['product_id']) || $r['product_id'] == $filters['product_id']) && (empty($filters['category_id']) || Product::whereKey($r['product_id'])->where('category_id', $filters['category_id'])->exists()))->values()->slice($offset, 101);

                return ['headers' => ['Product', 'Location', 'Base quantity'], 'rows' => $rows->map(fn ($r) => [$r['product'], $report['counter'], $r[$name === 'Opening Stock' ? 'opening' : 'expected']])->values()->all()];
            }
            if ($name === 'Stock Movement') {
                if ($selectedShift) {
                    $q->where('shift_id', $selectedShift->id)->where('created_at', '<=', $selectedShift->closed_at ?? now());
                }
                $rows = $q->when(! $selectedShift, fn ($q) => $q->whereBetween('created_at', [$from, $to]))->orderBy('id')->offset($offset)->limit(101)->get();

                return ['headers' => ['Date/Time', 'Product', 'Movement Type', 'From', 'To', 'Quantity', 'Balance after', 'Reference', 'User'], 'rows' => $rows->map(function ($r) {
                    $m = StockMovementPresenter::present($r);

                    return [$m['time'], $m['product'], $m['type'], $m['from'], $m['to'], $m['qty'], $m['balance'], $m['ref'], $m['user']];
                })->all()];
            }
            $rows = $q->where('created_at', $name === 'Opening Stock' ? '<' : '<=', $name === 'Opening Stock' ? $from : $to)->selectRaw('product_id, stock_location_id, SUM(quantity) as quantity')->groupBy(['product_id', 'stock_location_id'])->orderBy('product_id')->orderBy('stock_location_id')->offset($offset)->limit(101)->get();

            return ['headers' => ['Product', 'Location', 'Base quantity'], 'rows' => $rows->map(fn ($r) => [Product::whereKey($r->product_id)->first()?->name, StockLocation::withoutGlobalScopes()->where('company_id', app(TenantContext::class)->companyId())->whereKey($r->stock_location_id)->first()?->name, (string) $r->quantity])->all()];
        }
        if (in_array($name, ['Shift Report', 'Cashier Shift', 'Waiter Shift', 'Cash Shortage / Excess'], true)) {
            $q = $visibility->shifts(Shift::query());
            if (! empty($filters['shift_id'])) {
                $selected = Shift::findOrFail((int) $filters['shift_id']);
                Gate::authorize('view', $selected);
                $q->whereKey($selected->id);
            } else {
                $q->whereBetween('opened_at', [$from, $to]);
            }
            if ($branch) {
                $q->where('branch_id', $branch);
            }
            if ($name === 'Cashier Shift' || $name === 'Cash Shortage / Excess') {
                $q->where('type', 'CASHIER');
            }
            if ($name === 'Waiter Shift') {
                $q->where('type', 'WAITER');
            }
            if (! empty($filters['waiter_id'])) {
                $q->where('user_id', $filters['waiter_id']);
            }
            if (! empty($filters['issued_by'])) {
                $q->where('user_id', $filters['issued_by']);
            }
            if (! empty($filters['stock_location_id'])) {
                $q->where('stock_location_id', $filters['stock_location_id']);
            }
            $rows = $q->orderBy('id')->offset($offset)->limit(101)->get();

            if (! $visibility->broad() && ! $visibility->settlementOnly()) {
                return ['headers' => ['Shift', 'Employee', 'Type', 'Opened', 'Closed', 'Status', 'Operating date'], 'rows' => $rows->map(fn ($s) => [$s->number, app(TenantContext::class)->user()->name, $s->type, $s->opened_at->toDateTimeString(), $s->closed_at?->toDateTimeString(), $s->status, $s->operating_date])->all()];
            }

            return ['headers' => ['Shift', 'Employee', 'Type', 'Opening cash', 'Expected cash', 'Actual cash', 'Difference', 'Status', 'Operating date', 'Opened', 'Closed', 'Counter'], 'rows' => $rows->map(fn ($s) => [$s->number, User::where('company_id', app(TenantContext::class)->companyId())->find($s->user_id)?->name, $s->type, $s->opening_cash, app(ShiftService::class)->expected($s), $s->status === 'OPEN' ? 'Open' : $s->actual_cash, $s->cash_difference, $s->status, $s->operating_date, $s->opened_at->toDateTimeString(), $s->closed_at?->toDateTimeString(), StockLocation::withoutGlobalScopes()->where('company_id', $s->company_id)->findOrFail($s->stock_location_id)->name])->all()];
        }
        $docs = ['Goods Received' => [GoodsReceipt::class, 'total_cost'], 'Stock Transfer' => [StockTransfer::class, null], 'Stock Transfers' => [StockTransfer::class, null], 'Returns' => [OrderReturn::class, 'total'], 'Voided Sales' => [VoidRequest::class, null], 'Voids' => [VoidRequest::class, null], 'Breakages' => [StockAdjustment::class, null], 'Breakage' => [StockAdjustment::class, null], 'Complimentary Drinks' => [StockAdjustment::class, null], 'Complimentary' => [StockAdjustment::class, null], 'Staff Consumption' => [StockAdjustment::class, null]];
        if (isset($docs[$name])) {
            [$model,$amount] = $docs[$name];
            $q = $model::when(! $selectedShift, fn ($q) => $q->whereBetween('created_at', [$from, $to]));
            if ($selectedShift && $model === OrderReturn::class) {
                $q->when($selectedShift->type === 'CASHIER', fn ($q) => $q->where('shift_id', $selectedShift->id), fn ($q) => $q->whereIn('order_id', Order::where('shift_id', $selectedShift->id)->select('id')))->where('created_at', '<=', $selectedShift->closed_at ?? now());
            }
            if ($branch) {
                $q->where('branch_id', $branch);
            }
            if ($model === StockAdjustment::class) {
                $q->where('status', 'APPROVED')->where('reason', match ($name) {
                    'Breakages','Breakage' => 'BREAKAGE','Staff Consumption' => 'STAFF_CONSUMPTION',default => 'COMPLIMENTARY'
                });
            }
            if (! $visibility->broad() && in_array($model, [OrderReturn::class, VoidRequest::class], true)) {
                $q->whereIn('order_id', $visibility->orders(Order::query())->select('id'));
            }
            if ($model === VoidRequest::class) {
                $q->where('status', 'APPROVED');
            }
            $rows = $q->orderBy('id')->offset($offset)->limit(101)->get();

            return ['headers' => ['Reference', 'Branch', 'Amount', 'Status', 'Date'], 'rows' => $rows->map(fn ($r) => [$r->number, Branch::whereKey($r->branch_id)->first()?->name, $amount ? $r->getAttribute($amount) : '—', $r->status ?? 'POSTED', $r->created_at->toDateTimeString()])->all()];
        }
        if (in_array($name, ['Sales by Branch', 'Sales by Counter', 'Sales by Table', 'Sales by Waiter', 'Sales by Cashier'], true)) {
            $key = match ($name) {
                'Sales by Branch' => 'branch_id','Sales by Counter' => 'stock_location_id', 'Sales by Waiter' => 'waiter_id', 'Sales by Cashier' => 'issued_by',default => 'service_table_id'
            };
            $rows = $orders->selectRaw("{$key} as location, SUM(total) as total")->groupBy($key)->orderBy($key)->offset($offset)->limit(101)->toBase()->get();
            $labels = fn ($r) => match ($name) {
                'Sales by Branch' => Branch::whereKey($r->location)->first()?->name,'Sales by Counter' => StockLocation::withoutGlobalScopes()->where('company_id', app(TenantContext::class)->companyId())->whereKey($r->location)->first()?->name, 'Sales by Waiter', 'Sales by Cashier' => User::where('company_id', app(TenantContext::class)->companyId())->whereKey($r->location)->first()?->name,default => ServiceTable::whereKey($r->location)->first()->name ?? 'Takeaway'
            };

            return ['headers' => ['Location', 'Sales'], 'rows' => $rows->map(fn ($r) => [$labels($r), $r->total])->all(), 'values' => $rows->map(fn ($r) => [$labels($r), $r->total])->all()];
        }
        if ($name === 'Paid Orders') {
            $orders->where('status', 'PAID');
        }
        if ($name === 'Unpaid Orders') {
            $orders->whereIn('status', ['ISSUED', 'PARTIALLY_PAID']);
        }
        abort_unless(in_array($name, ['Daily Sales', 'Order Report', 'Paid Orders', 'Unpaid Orders'], true), 422, 'Unknown report.');

        return ['headers' => ['Invoice', 'Waiter', 'Table', 'Total', 'Paid', 'Balance', 'Status'], 'rows' => $orders->latest('issued_at')->orderByDesc('id')->offset($offset)->limit(101)->get()->map(fn ($o) => [$o->invoice_number, User::where('company_id', app(TenantContext::class)->companyId())->find($o->waiter_id)?->name, ServiceTable::whereKey($o->service_table_id)->first()->name ?? 'Takeaway', $o->total, $o->amount_paid, $o->balance, $o->status])->all()];
    }
}
