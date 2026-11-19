<?php

namespace App\Services;

use App\Domain\OrderCollectionMode;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Support\QuantityFormatter;
use App\Support\UiText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Facades\DB;

final class SalesProfitabilityService
{
    public const REPORTS = ['Daily Sales', 'Sales by Product'];

    public const DAILY_HEADERS = ['Product', 'Quantity', 'Unit', 'Unit Price', 'Sales', 'Cost', 'Gross Profit', 'Margin %', 'Date/Time', 'Cashier', 'Sale type', 'Invoice'];

    public const PRODUCT_HEADERS = ['Product', 'Category', 'Quantity sold', 'Sales', 'Cost', 'Gross Profit', 'Margin %'];

    /** @param Builder<Order> $orders */
    public function report(string $name, Builder $orders, array $filters, ?Shift $shift, int $offset, bool $withSummary): array
    {
        $lines = $this->lines($orders, $filters, $shift);
        $result = [
            'profitability' => true,
            'headers' => $name === 'Daily Sales' ? self::DAILY_HEADERS : self::PRODUCT_HEADERS,
        ];

        if ($name === 'Daily Sales') {
            $rows = (clone $lines)->orderByDesc('issued_at')->orderByDesc('order_id')->orderBy('line_id')
                ->offset($offset)->limit(101)->get();
            $result['rows'] = $rows->map(fn ($line) => [
                ...$this->itemCells($line),
                $line->issued_at, $line->cashier, UiText::get($line->order_source === 'CASHIER_DIRECT' ? 'Counter Sale' : 'Waiter Sale'), $line->invoice_number,
            ])->all();
            $result['invoice_ids'] = $rows->pluck('order_id')->all();
        } else {
            // Keep the recorded selling units distinct even if the catalog changed its base unit later.
            $rows = (clone $lines)->selectRaw('product_id, MAX(COALESCE(catalog_name, product_name)) AS name, MAX(category_name) AS category, SUM(quantity) AS quantity, '.$this->aggregateColumns())
                ->groupBy('product_id')->orderByDesc('sales')->orderBy('product_id')->offset($offset)->limit(101)->get();
            $units = (clone $lines)->whereIn('product_id', $rows->pluck('product_id')->filter()->all())
                ->selectRaw('product_id, unit_name, SUM(quantity) AS quantity')
                ->groupBy('product_id', 'unit_name')->orderBy('unit_name')->get()->groupBy('product_id');
            $result['rows'] = $rows->map(fn ($row) => [
                $row->name ?? UiText::get('Product unavailable'), $row->category,
                $units->get($row->product_id)?->map(fn ($unit) => $this->quantity($unit->quantity).' '.$unit->unit_name)->implode(' · '),
                ...$this->financials($row),
            ])->all();
            // Scheduled reports still use their existing count of selling units, independent of display.
            $result['selling_unit_counts'] = $rows->map(fn ($row) => [$row->name, $this->quantity($row->quantity)])->all();
        }

        if ($withSummary) {
            $result['summary'] = array_combine(['Sales', 'Cost', 'Gross Profit', 'Margin %'], $this->financials($lines->selectRaw($this->aggregateColumns())->first()));
        }

        return $result;
    }

    /** @param Builder<Order> $orders */
    public function filterOptions(Builder $orders, array $filters): array
    {
        $actor = app(TenantContext::class)->user();
        $visibility = app(StaffVisibility::class);
        $cashiers = User::where('company_id', $actor->company_id)->whereIn('id', (clone $orders)->select('issued_by'));
        if ($visibility->settlementOnly()) {
            $cashiers->whereKey($actor->id);
        } else {
            $cashiers->where(fn ($q) => $q->whereKey($actor->id)->orWhereIn('id', $visibility->unitUsers(User::where('company_id', $actor->company_id))->select('id')));
        }

        $items = OrderItem::whereIn('order_id', $orders->select('orders.id'));

        return [
            'product' => Product::whereIn('id', $items->select('product_id'))->orderBy('name')->get(['id', 'name'])->all(),
            'cashier' => $cashiers->orderBy('name')->get()->filter(fn ($user) => (empty($filters['branch_id']) || $user->canAccessBranch((int) $filters['branch_id']))
                && (empty($filters['business_unit_id']) || $user->canAccessBusinessUnit((int) $filters['business_unit_id']))
            )->values()->map->only(['id', 'name'])->all(),
        ];
    }

    /** @param Builder<Order> $orders */
    public function invoice(Builder $orders, ?Shift $shift, array $filters): array
    {
        abort_unless($this->lines($orders, $filters, $shift)->exists(), 404);
        $order = (clone $orders)->firstOrFail();
        $lines = $this->lines($orders, [], $shift)->orderBy('line_id')->get();
        $first = $lines->first();
        $company = $order->company_id;
        $counter = DB::table('stock_locations')->where('company_id', $company)->where('branch_id', $order->branch_id)->where('id', $order->stock_location_id)->value('name');
        $unit = DB::table('business_units')->where('company_id', $company)->where('branch_id', $order->branch_id)->where('id', $order->business_unit_id)->value('name');
        $cutoff = $shift && $shift->status === 'CLOSED' ? $shift->closed_at : null;
        // Use allocated amounts for shared payments. Historical direct payments may have no allocation.
        $payments = DB::table('payments')->where('payments.company_id', $company)->where('payments.branch_id', $order->branch_id)
            ->leftJoin('payment_allocations as allocation', function ($join) use ($order) {
                $join->on('allocation.payment_id', '=', 'payments.id')->on('allocation.company_id', '=', 'payments.company_id')
                    ->on('allocation.branch_id', '=', 'payments.branch_id')->where('allocation.order_id', $order->id);
            })->where(fn ($query) => $query->whereNotNull('allocation.id')->orWhere('payments.order_id', $order->id))
            ->where('payments.is_credit', false)->when($cutoff, fn ($query) => $query->where('payments.created_at', '<=', $cutoff))
            ->orderBy('payments.id')->get(['payments.method', DB::raw('COALESCE(allocation.amount, payments.amount) AS amount')])
            ->map(fn ($payment) => [UiText::get($payment->method), $this->decimal($payment->amount)])->all();
        if ($order->collection_mode === OrderCollectionMode::PaidTicket) {
            $collected = DB::table('ticket_collections')->where('company_id', $company)->where('branch_id', $order->branch_id)
                ->where('order_id', $order->id)->where('status', 'COLLECTED')
                ->when($cutoff, fn ($query) => $query->where('collected_at', '<=', $cutoff))->value('amount');
            if ($collected !== null) {
                array_unshift($payments, [UiText::get('Cash'), $this->decimal($collected)]);
            }
        }

        return [
            'invoice' => $order->invoice_number,
            'details' => [
                'Date/Time' => $first->issued_at, 'Cashier' => $first->cashier,
                ...($order->isCounterSale() ? [] : ['Waiter' => $first->waiter]),
                'Counter' => $counter, 'Business Unit' => $unit,
                'Sale type' => UiText::get($order->isCounterSale() ? 'Counter Sale' : 'Waiter Sale'),
            ],
            'payments' => $payments,
            'headers' => ['Product', 'Quantity', 'Unit', 'Unit Price', 'Line total', 'Cost', 'Gross Profit', 'Margin %'],
            'rows' => $lines->map($this->itemCells(...))->all(),
        ];
    }

    /** @param Builder<Order> $orders */
    private function lines(Builder $orders, array $filters, ?Shift $shift): Query
    {
        $company = app(TenantContext::class)->companyId();
        $cutoff = $shift && $shift->status === 'CLOSED' ? $shift->closed_at : null;
        $orderIds = (clone $orders)->select('orders.id');
        $issues = DB::table('stock_movements')->where('company_id', $company)->where('reference_type', 'Order')
            ->where('movement_type', 'ISSUE')->where('quantity', '<', 0)->whereIn('reference_id', $orderIds)
            ->selectRaw('reference_id, product_id, branch_id, stock_location_id, MIN(unit_cost) AS low_cost, MAX(unit_cost) AS high_cost')
            ->groupBy('reference_id', 'product_id', 'branch_id', 'stock_location_id');
        $itemIds = OrderItem::whereIn('order_id', $orderIds)->select('id');
        $returns = DB::table('return_items')->where('company_id', $company)->whereIn('order_item_id', $itemIds)
            ->when($cutoff, fn ($q) => $q->where('created_at', '<=', $cutoff))
            ->selectRaw('order_item_id, branch_id, SUM(quantity) AS quantity, SUM(base_quantity) AS base_quantity, SUM(value) AS value')
            ->groupBy('order_item_id', 'branch_id');
        $returnedQuantity = $cutoff ? 'COALESCE(ret.quantity, 0)' : 'COALESCE(ret.quantity, item.returned_quantity, 0)';
        $returnedValue = $cutoff ? 'COALESCE(ret.value, 0)' : 'COALESCE(ret.value, item.returned_value, 0)';
        $returnedBase = "COALESCE(ret.base_quantity, {$returnedQuantity} * item.conversion_quantity)";
        $netBase = "ROUND(item.base_quantity - {$returnedBase}, 6)";
        // ISSUE is the base-unit cost at posting. Same-product issues normally share a cost.
        // A conflicting issue cost cannot be attributed to individual lines by the movement's order reference.
        // Older rows without ISSUE can use their captured item cost. A default zero there is ambiguous.
        $cost = "CASE WHEN item.id IS NULL THEN NULL WHEN {$netBase} = 0 THEN 0
            WHEN issue.low_cost = issue.high_cost AND issue.low_cost >= 0 THEN ROUND({$netBase} * issue.low_cost, 2)
            WHEN issue.reference_id IS NULL AND item.purchase_cost > 0 THEN ROUND({$netBase} * item.purchase_cost, 2) END";

        $data = DB::query()->fromSub($orders, 'sale')
            ->leftJoin('order_items as item', function ($join) {
                $join->on('item.order_id', '=', 'sale.id')->on('item.company_id', '=', 'sale.company_id')->on('item.branch_id', '=', 'sale.branch_id');
            })->leftJoinSub($issues, 'issue', function ($join) {
                $join->on('issue.reference_id', '=', 'sale.id')->on('issue.branch_id', '=', 'sale.branch_id')
                    ->on('issue.stock_location_id', '=', 'sale.stock_location_id')->on('issue.product_id', '=', 'item.product_id');
            })->leftJoinSub($returns, 'ret', fn ($join) => $join->on('ret.order_item_id', '=', 'item.id')->on('ret.branch_id', '=', 'sale.branch_id'))
            ->leftJoin('products as product', fn ($join) => $join->on('product.id', '=', 'item.product_id')->on('product.company_id', '=', 'sale.company_id'))
            ->leftJoin('categories as category', fn ($join) => $join->on('category.id', '=', 'product.category_id')->on('category.company_id', '=', 'sale.company_id'))
            ->leftJoin('users as cashier', fn ($join) => $join->on('cashier.id', '=', 'sale.issued_by')->on('cashier.company_id', '=', 'sale.company_id'))
            ->leftJoin('users as waiter', fn ($join) => $join->on('waiter.id', '=', 'sale.waiter_id')->on('waiter.company_id', '=', 'sale.company_id'))
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('item.product_id', $filters['product_id']))
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('product.category_id', $filters['category_id']))
            ->select('sale.id as order_id', 'sale.issued_at', 'sale.order_source', 'sale.invoice_number', 'cashier.name as cashier', 'waiter.name as waiter',
                'item.id as line_id', 'item.product_id', 'item.product_name', 'item.unit_name', 'item.unit_price', 'product.name as catalog_name',
                'category.name as category_name')
            // Posting already allocates proportionally and assigns the leftover cents by line order.
            // Reuse each saved discount share and posted return value to reconcile to recognized revenue.
            ->selectRaw("item.quantity - {$returnedQuantity} AS quantity,
                COALESCE(ROUND(item.total - item.discount_share - {$returnedValue}, 2), sale.total) AS sales, {$cost} AS cost");

        return DB::query()->fromSub($data, 'sale_lines');
    }

    private function itemCells(object $line): array
    {
        return [
            $line->product_name ?? UiText::get('Product unavailable'),
            $this->quantity($line->quantity),
            $line->unit_name, $line->unit_price === null ? null : $this->decimal($line->unit_price),
            ...$this->financials($line),
        ];
    }

    private function aggregateColumns(): string
    {
        return 'COALESCE(SUM(sales), 0) AS sales, CASE WHEN COUNT(cost) < COUNT(*) THEN NULL ELSE COALESCE(SUM(cost), 0) END AS cost';
    }

    private function financials(object $row): array
    {
        $sales = $this->decimal($row->sales);
        $cost = $row->cost === null ? null : $this->decimal($row->cost);
        $profit = $cost === null ? null : bcsub($sales, $cost, 2);
        $margin = match (true) {
            bccomp($sales, '0', 2) === 0 => '0.00',
            $profit === null => null,
            default => bcround(bcdiv(bcmul($profit, '100', 2), $sales, 6), 2),
        };

        return [$sales, $cost, $profit, $margin];
    }

    private function quantity(float|string|int|null $value): ?string
    {
        return $value === null ? null : QuantityFormatter::format($this->decimal($value, 6));
    }

    private function decimal(float|string|int $value, int $scale = 2): string
    {
        // SQLite returns numeric aggregates as floats. MySQL DECIMAL results retain their precision.
        return is_float($value) ? number_format($value, $scale, '.', '') : bcadd((string) $value, '0', $scale);
    }
}
