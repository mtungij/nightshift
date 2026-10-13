<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BusinessNotification;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\ServiceTable;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\VoidRequest;
use App\Models\WaiterLedgerEntry;
use App\Support\StockMovementPresenter;
use Illuminate\Database\Eloquent\Builder;

final class WorkspaceService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function snapshot(array $filters = []): array
    {
        $actor = app(TenantContext::class)->user();
        abort_unless($actor->active && $actor->company_id, 403, 'No active company workspace.');
        $actor->load('company');
        $visibility = app(StaffVisibility::class);
        $visibility->assertFilters($filters);
        $counterIds = $visibility->settlementOnly() ? $visibility->counters() : [];
        $branches = Branch::when($counterIds !== [], fn ($q) => $q->whereIn('id', StockLocation::whereIn('id', $counterIds)->select('branch_id')))->orderBy('id')->get();
        $branchId = $filters['branch_id'] ?? null;
        if ($branchId) {
            Branch::whereKey($branchId)->firstOrFail();
            abort_unless($branches->contains('id', $branchId), 403);
        } elseif (! filter_var($filters['all_branches'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $branchId = $branches->first()?->id;
        }
        $locations = $this->scoped($branchId, StockLocation::query())->when($visibility->settlementOnly(), fn ($q) => $q->where('type', 'COUNTER')->when($counterIds !== [], fn ($q) => $q->whereIn('id', $counterIds)))->orderBy('id')->get();
        $activeLocation = $this->scoped($branchId, Shift::where('user_id', $actor->id)->where('status', 'OPEN'))->value('stock_location_id');
        $location = $filters['stock_location_id'] ?? ($locations->contains('id', $activeLocation) ? $activeLocation : $locations->firstWhere('type', 'COUNTER')?->id);
        if ($location) {
            $selected = StockLocation::whereKey($location)->firstOrFail();
            abort_if($branchId && $selected->branch_id != $branchId, 422, 'Location is outside selected branch.');
            $location = $selected->id;
        }
        $canInventory = $actor->hasPermission('manage-inventory') || $actor->hasPermission('create-orders');
        $products = $canInventory ? Product::with(['category', 'baseUnit', 'sellingUnits.unit'])->when(! empty($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))->orderBy('name')->limit(200)->get() : collect();
        $stocks = $location ? Stock::where('stock_location_id', $location)->pluck('quantity', 'product_id') : collect();
        $servingShifts = $visibility->atCounter($this->scoped($branchId, Shift::where('type', 'WAITER')->where('status', 'OPEN')))->when(! $visibility->broad() && ! $visibility->settlementOnly(), fn ($q) => $q->where('user_id', $actor->id));
        $servingWaiters = $actor->hasPermission('create-orders') ? User::where('company_id', $actor->company_id)->where('active', true)->whereHas('role', fn ($q) => $q->where('name', 'Waiter'))->whereIn('id', (clone $servingShifts)->pluck('user_id'))->get()->filter(fn ($w) => $branchId ? $w->canAccessBranch($branchId) : true)->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'initials' => $w->initials(), 'pin_configured' => (bool) $w->operational_pin, 'counter_ids' => (clone $servingShifts)->where('user_id', $w->id)->pluck('stock_location_id')->all()])->values()->all() : [];
        $canWaiters = $actor->hasPermission('view-waiter-balances');
        $waiterIds = $this->scoped($branchId, Shift::where('type', 'WAITER'))->when(! $visibility->broad() && ! $visibility->settlementOnly(), fn ($q) => $q->where('user_id', $actor->id))->pluck('user_id')->unique();
        $waiters = $canWaiters ? $visibility->waiters(User::query())->where(function ($q) use ($waiterIds, $branchId) {
            $q->whereIn('id', $waiterIds)->orWhereHas('branches', fn ($q) => $q->when($branchId, fn ($q) => $q->where('branches.id', $branchId)));
        })->get()->map(function ($w) use ($branchId, $location, $visibility) {
            $ledger = $visibility->ledger(WaiterLedgerEntry::where('waiter_id', $w->id));
            if ($branchId) {
                $ledger->where('branch_id', $branchId);
            }
            $issued = (clone $ledger)->where('entry_type', 'ISSUE')->sum('debit');
            $paid = (clone $ledger)->where('entry_type', 'PAYMENT')->sum('credit');
            $returns = (clone $ledger)->where('entry_type', 'RETURN')->sum('credit');
            $shift = $visibility->atCounter($this->scoped($branchId, Shift::where('user_id', $w->id)->where('type', 'WAITER')->where('status', 'OPEN')))->first();
            if ($visibility->settlementOnly()) {
                $invoices = $visibility->atCounter($this->scoped($branchId, Order::where('waiter_id', $w->id)->whereIn('status', ['ISSUED', 'PARTIALLY_PAID'])));
                $due = D::money((string) (clone $invoices)->sum('balance'));

                return ['id' => $w->id, 'name' => $w->name, 'short' => explode(' ', $w->name)[0], 'branch' => Branch::whereKey($shift->branch_id ?? $branchId)->value('name') ?? 'All branches', 'active' => (bool) $shift, 'settlement_balance' => $due, 'outstanding' => $due, 'open_orders' => $invoices->count()];
            }

            return ['id' => $w->id, 'name' => $w->name, 'short' => explode(' ', $w->name)[0], 'branch' => Branch::whereKey($shift->branch_id ?? $branchId)->value('name') ?? 'All branches', 'active' => (bool) $shift, 'orders' => Order::where('waiter_id', $w->id)->whereNotNull('issued_at')->count(), 'issued' => D::money((string) $issued), 'paid' => D::money((string) $paid), 'returns' => D::money((string) $returns), 'settlement_balance' => D::money((string) Order::where('waiter_id', $w->id)->where('stock_location_id', $location)->whereIn('status', ['ISSUED', 'PARTIALLY_PAID'])->sum('balance')), 'outstanding' => app(WaiterLedgerService::class)->visibleBalance($w->id), 'adjustments' => D::sub((string) (clone $ledger)->whereIn('entry_type', ['REVERSAL', 'ADJUSTMENT'])->sum('debit'), (string) (clone $ledger)->whereIn('entry_type', ['REVERSAL', 'ADJUSTMENT'])->sum('credit')), 'shift_id' => $shift?->id, 'shift_number' => $shift?->number, 'open_orders' => Order::where('waiter_id', $w->id)->whereIn('status', ['ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED'])->count(), 'ledger' => (clone $ledger)->latest()->limit(50)->get(['id', 'entry_type', 'debit', 'credit', 'reference_type', 'reference_id', 'description', 'created_at'])->toArray()];
        }) : collect();
        $orderQuery = $visibility->orders($this->scoped($branchId, Order::query()));
        $orders = $actor->hasPermission('view-orders') ? (clone $orderQuery)->with('items')->latest()->limit(100)->get() : collect();
        $shift = $this->scoped($branchId, Shift::where('user_id', $actor->id)->where('type', 'CASHIER'))->latest()->first();
        $cash = fn ($method) => $shift ? D::money((string) Payment::where('shift_id', $shift->id)->whereIn('method', (array) $method)->where('is_credit', false)->sum('amount')) : '0.00';
        $payments = $actor->hasPermission('receive-payments') || $actor->hasPermission('view-reports') || $actor->hasPermission('view-waiter-balances') ? $visibility->payments($this->scoped($branchId, Payment::query()))->latest()->limit(100)->get() : collect();
        $allocations = PaymentAllocation::whereIn('payment_id', $payments->pluck('id'))->get()->keyBy('payment_id');
        $canApprove = $actor->hasPermission('approve-void') || $actor->hasPermission('approve-adjustments');
        $voids = $canApprove ? $this->scoped($branchId, VoidRequest::query())->latest()->limit(100)->get() : collect();
        $adjustments = $canApprove || $actor->hasPermission('adjust-stock') ? $this->scoped($branchId, StockAdjustment::with('items'))->latest()->limit(100)->get() : collect();
        $audit = $actor->hasPermission('view-audit') ? AuditLog::when($branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id')))->when(! $actor->hasPermission('all-branches'), fn ($q) => $q->whereIn('branch_id', app(TenantContext::class)->branches()))->latest()->limit(100)->get() : collect();

        $shiftSummary = ['id' => $shift?->id, 'type' => 'CASHIER', 'active' => $shift?->status === 'OPEN', 'opening' => $shift->opening_cash ?? '0', 'cashSales' => $cash('Cash'), 'mobile' => $cash(['M-Pesa', 'Airtel Money', 'Mixx by Yas']), 'card' => $cash('Card'), 'actual' => $shift?->status === 'CLOSED' ? $shift->actual_cash : null, 'cashier' => $actor->name];
        if (! $visibility->broad() && ! $visibility->settlementOnly()) {
            $ownShift = $this->scoped($branchId, Shift::where('user_id', $actor->id)->where('type', 'WAITER'))->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 ELSE 1 END")->latest()->first();
            $shiftSummary = ['id' => $ownShift?->id, 'type' => 'WAITER', 'active' => $ownShift?->status === 'OPEN'];
        }

        return ['company' => $actor->company->name, 'companySettings' => ['email' => $actor->company->email, 'phone' => $actor->company->phone], 'currency' => $actor->company->currency, 'timezone' => $actor->company->timezone, 'onboarding' => $actor->hasPermission('manage-company') ? ($actor->company->settings['onboarding'] ?? []) : [], 'role' => $actor->role->name, 'permissions' => $actor->role->permissions()->pluck('name')->all(), 'visibility' => ['staff_operations' => $visibility->broad(), 'settlement_only' => $visibility->settlementOnly()], 'user' => ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email], 'branch' => $branchId ? Branch::whereKey($branchId)->first()?->name : 'All branches', 'branch_id' => $branchId, 'location_id' => $location, 'branches' => $branches->toArray(), 'locations' => $locations->toArray(), 'categories' => Category::where('active', true)->orderBy('sort_order')->orderBy('name')->pluck('name')->all(), 'categoryRecords' => Category::withCount('products')->orderBy('sort_order')->orderBy('name')->get()->toArray(), 'supplierCategories' => config('suppliers.categories'), 'unitRecords' => Unit::get(['id', 'name'])->toArray(),
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'category' => $p->category->name, 'brand' => $p->brand, 'price' => $p->sellingUnits->first()->selling_price ?? '0', 'cost' => $actor->hasPermission('manage-inventory') || $actor->hasPermission('view-reports') ? $p->purchase_cost : '0', 'stock' => D::quantity((string) ($stocks[$p->id] ?? 0)), 'min' => $p->minimum_stock, 'active' => $p->active, 'baseUnit' => $p->baseUnit->name, 'style' => $p->visual_style, 'image' => $p->image_path ? route('nightshift.product-image', ['id' => $p->id], false).'?v='.$p->updated_at?->timestamp : $p->image_url, 'units' => $p->sellingUnits->map(fn ($u) => [$u->unit->name, $u->conversion_quantity, $u->selling_price, $u->id])->all()])->all(),
            'shiftLocations' => $visibility->settlementOnly() && $actor->hasPermission('open-shifts') ? StockLocation::where('type', 'COUNTER')->get(['id', 'branch_id', 'name', 'type'])->toArray() : [],
            'servingWaiters' => $servingWaiters, 'quickLock' => ['enabled' => (bool) $actor->operational_pin, 'minutes' => config('session.quick_lock_minutes')], 'waiters' => $waiters->all(), 'orders' => $orders->map(fn ($o) => ['id' => $o->id, 'invoice' => $o->invoice_number, 'waiter' => $o->waiter_id, 'table' => ServiceTable::whereKey($o->service_table_id)->first()->name ?? 'Takeaway', 'table_id' => $o->service_table_id, 'branch' => Branch::whereKey($o->branch_id)->first()?->name, 'counter' => StockLocation::whereKey($o->stock_location_id)->first()?->name, 'counter_id' => $o->stock_location_id, 'shift_id' => $o->shift_id, 'status' => str_replace('_', ' ', ucfirst(strtolower($o->status))), 'items' => $o->items->map(function ($it) use ($products) {
                $p = $products->firstWhere('id', $it->product_id);
                $units = $p->sellingUnits ?? collect();

                return ['id' => $it->id, 'product' => $it->product_id, 'name' => $it->product_name, 'unitName' => $it->unit_name, 'unit' => $units->search(fn ($u) => $u->id === $it->product_unit_id) ?: 0, 'unit_id' => $it->product_unit_id, 'qty' => $it->quantity, 'returned_qty' => $it->returned_quantity, 'price' => $it->unit_price];
            })->all(), 'total' => $o->total, 'subtotal' => $o->subtotal, 'discount' => $o->discount, 'paid' => $o->amount_paid, 'balance' => $o->balance, 'time' => $o->created_at->format('H:i'), 'created_at' => $o->created_at->toIso8601String()])->all(),
            'payments' => $payments->map(function ($p) use ($allocations) {
                $order = Order::whereKey($p->order_id ?? $allocations->get($p->id)?->order_id)->first();

                return ['id' => $p->number, 'invoice' => $order?->id, 'waiter' => $order?->waiter_id, 'method' => $p->method, 'is_credit' => (bool) $p->is_credit, 'amount' => $p->amount, 'time' => $p->created_at->format('H:i'), 'branch' => Branch::whereKey($p->branch_id)->first()?->name];
            })->all(),
            'shifts' => $visibility->shifts($this->scoped($branchId, Shift::query()))->latest()->limit(100)->get()->map(function ($s) use ($visibility) {
                return ! $visibility->broad() && $s->type === 'WAITER' ? $s->only(['id', 'branch_id', 'user_id', 'stock_location_id', 'number', 'type', 'status', 'opened_at', 'closed_at', 'closing_notes']) : $s->toArray();
            })->all(), 'shift' => $shiftSummary,
            'movements' => ($actor->hasPermission('manage-inventory') ? $this->scoped($branchId, StockMovement::query())->latest()->limit(100)->get() : collect())->map(StockMovementPresenter::present(...))->all(),
            'transfers' => ($actor->hasPermission('transfer-stock') ? $this->scoped($branchId, StockTransfer::query())->latest()->limit(100)->get() : collect())->toArray(),
            'approvals' => [...$voids->map(fn ($v) => ['id' => 'void-'.$v->id, 'record_id' => $v->id, 'type' => 'Void request', 'invoice' => $v->order_id, 'amount' => Order::whereKey($v->order_id)->first()?->balance, 'user' => User::where('company_id', $actor->company_id)->whereKey($v->requested_by)->first()?->name, 'reason' => $v->reason, 'status' => ucfirst(strtolower($v->status)), 'time' => $v->created_at->format('H:i')])->all(), ...$adjustments->map(fn ($a) => ['id' => 'adjustment-'.$a->id, 'record_id' => $a->id, 'type' => $a->reason === 'COMPLIMENTARY' ? 'Complimentary' : 'Stock adjustment', 'product' => $a->items->first()?->product_id, 'qty' => $a->items->sum('base_quantity'), 'amount' => $a->items->sum('cost'), 'user' => User::where('company_id', $actor->company_id)->whereKey($a->requested_by)->first()?->name, 'reason' => $a->reason.' — '.$a->notes, 'status' => ucfirst(strtolower($a->status)), 'time' => $a->created_at->format('H:i')])->all()],
            'audit' => $audit->map(fn ($a) => ['user' => $a->user_name, 'action' => $a->action, 'module' => $a->module, 'ref' => $a->reference_type.' #'.$a->reference_id, 'previous' => json_encode($actor->hasPermission('all-branches') ? $a->old_values : array_diff_key($a->old_values ?? [], ['outstanding' => true])), 'next' => json_encode($a->new_values), 'time' => $a->created_at->format('d M Y H:i'), 'device' => $a->ip.' · '.$a->user_agent])->all(),
            'suppliers' => $actor->hasPermission('manage-inventory') ? Supplier::get()->toArray() : [], 'team' => $actor->hasPermission('manage-users') ? User::where('company_id', $actor->company_id)->whereHas('branches', fn ($q) => $q->when($branchId, fn ($q) => $q->where('branches.id', $branchId)))->get(['id', 'name', 'username', 'email', 'phone', 'active', 'role_id', 'operational_pin'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'phone' => $u->phone, 'active' => (bool) $u->active, 'pin_configured' => (bool) $u->operational_pin, 'role' => $u->role->name, 'branch' => $u->branches()->pluck('name')->implode(', ')])->all() : [],
            'tables' => $this->scoped($branchId, ServiceTable::query())->get()->toArray(), 'reservations' => $this->scoped($branchId, ServiceTable::whereNotNull('reservation'))->get()->map(fn ($t) => ['table' => $t->name, ...($t->reservation ?? [])])->all(),
            'notifications' => $visibility->notifications($this->scoped($branchId, BusinessNotification::query()))->latest()->limit(50)->get()->map(function ($n) use ($actor) {
                $row = $n->toArray();
                $row['read_by'] = in_array($actor->id, $row['read_by'] ?? [], true) ? [$actor->id] : [];

                return $row;
            })->all(), 'metrics' => $actor->hasPermission('view-dashboard') ? app(DashboardService::class)->metrics($branchId, $filters['period'] ?? 'Today') : null,
            'order_count' => (clone $orderQuery)->count(), 'date' => now()->toDateString(), 'period' => $filters['period'] ?? 'Today',
        ];
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    private function scoped(?int $branch, Builder $query): Builder
    {
        return $branch ? $query->where('branch_id', $branch) : $query;
    }
}
