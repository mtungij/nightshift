<?php

namespace App\Services;

use App\Domain\BusinessUnitContext;
use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessNotification;
use App\Models\BusinessUnit;
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
use App\Support\CounterLocationPresenter;
use App\Support\StockMovementPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class WorkspaceService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function snapshot(array $filters = []): array
    {
        app(BusinessUnitContext::class)->select(! empty($filters['business_unit_id']) ? (int) $filters['business_unit_id'] : null);
        $actor = app(TenantContext::class)->user();
        abort_unless($actor->active && $actor->company_id, 403, 'No active company workspace.');
        $actor->load('company');
        $inventoryMode = filter_var($filters['unit_context'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $posMode = filter_var($filters['pos_context'] ?? false, FILTER_VALIDATE_BOOLEAN) || $inventoryMode;
        $broadPosUnits = ! $actor->isPosTerminal() && in_array($actor->role->name, ['Company Admin / Owner', 'Super Admin'], true) && $actor->hasPermission('all-business-units');
        $staffShiftLocked = in_array($actor->role->name, ['Cashier', 'Waiter'], true) || (! $broadPosUnits && ! $actor->hasPermission('view-staff-operations'));
        $restrictPosCounters = ! ($posMode && $broadPosUnits);
        $operationalShift = Shift::withoutGlobalScopes()->where('company_id', $actor->company_id)->where('user_id', $actor->id)->where('status', 'OPEN')->latest('id')->first();
        $fixedCounter = $actor->isPosTerminal() ? $actor->terminal_stock_location_id : ($staffShiftLocked ? $operationalShift?->stock_location_id : null);
        $fixed = $fixedCounter ? StockLocation::withoutGlobalScopes()->where('company_id', $actor->company_id)->whereKey($fixedCounter)->first() : null;
        abort_if($posMode && $fixedCounter && (! $fixed || ! $fixed->active || ($operationalShift && $operationalShift->business_unit_id !== $fixed->business_unit_id)), 403, 'The assigned POS context is unavailable.');
        if ($posMode && $fixed) {
            foreach (['branch_id' => $fixed->branch_id, 'business_unit_id' => $fixed->business_unit_id, 'stock_location_id' => $fixed->id] as $key => $id) {
                abort_if(! empty($filters[$key]) && (int) $filters[$key] !== (int) $id, 403, 'POS context is fixed by the active shift or terminal.');
                $filters[$key] = $id;
            }
            app(BusinessUnitContext::class)->select($fixed->business_unit_id);
        }
        $fixedUnit = $fixed ? BusinessUnit::withoutGlobalScopes()->where('company_id', $actor->company_id)->find($fixed->business_unit_id) : null;
        $closeOnly = $posMode && $fixed && $operationalShift && ! $actor->isPosTerminal() && $fixedUnit && ! $fixedUnit->active;
        $visibility = app(StaffVisibility::class);
        // Unit-wide POS inspection does not grant broader staff/report visibility.
        $visibility->assertFilters($posMode && $broadPosUnits ? array_diff_key($filters, ['stock_location_id' => true]) : $filters);
        if (! $posMode && empty($filters['business_unit_id'])) {
            $permittedUnits = BusinessUnit::pluck('id');
            if ($permittedUnits->count() === 1) {
                app(BusinessUnitContext::class)->select((int) $permittedUnits->first());
            }
        }
        $terminal = $actor->isPosTerminal();
        $counterIds = $terminal ? [(int) $actor->terminal_stock_location_id] : ($restrictPosCounters && $visibility->settlementOnly() ? $visibility->counters() : []);
        $branches = Branch::when($counterIds !== [], fn ($q) => $q->whereIn('id', StockLocation::whereIn('id', $counterIds)->select('branch_id')))->orderBy('id')->get();
        $unitBranch = ! empty($filters['business_unit_id']) ? BusinessUnit::whereKey($filters['business_unit_id'])->value('branch_id') : null;
        if (! empty($filters['branch_id']) && $unitBranch) {
            app(StockService::class)->require((int) $filters['branch_id'] === (int) $unitBranch, __('ui.Business Unit and branch must match.'));
        }
        $branchId = $filters['branch_id'] ?? $unitBranch ?? ($terminal ? $branches->first()?->id : null);
        $branchId = $branchId ? (int) $branchId : null;
        if ($branchId) {
            Branch::whereKey($branchId)->firstOrFail();
            abort_unless($branches->contains('id', $branchId), 403);
        } elseif ($posMode || ! filter_var($filters['all_branches'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $branchId = $branches->first()?->id;
        }
        // Options are authorized by company/branch and unit permission, never by
        // the currently selected counter, selected unit or existence of counters.
        $posUnitOptions = BusinessUnit::where('active', true)->where('branch_id', $branchId)->orderBy('id')->get(['id', 'branch_id', 'name', 'active', 'default_stock_location_id', 'advanced_locations_enabled', 'max_active_cashiers', 'location_cutover_note']);
        if ($posMode) {
            $units = $posUnitOptions;
            $unitId = app(BusinessUnitContext::class)->selected();
            if (! $unitId && ! empty($filters['stock_location_id'])) {
                $unitId = StockLocation::whereKey($filters['stock_location_id'])->value('business_unit_id');
            }
            if (! $inventoryMode) {
                $unitId ??= $operationalShift && (! $branchId || $operationalShift->branch_id === (int) $branchId) && $units->contains('id', $operationalShift->business_unit_id) ? $operationalShift->business_unit_id : null;
                $unitId ??= $units->first()?->id;
            }
            abort_if(! $closeOnly && $unitId && ! $units->contains('id', $unitId), 422, 'Business Unit is inactive or outside the selected branch.');
            app(BusinessUnitContext::class)->select($unitId);
        }
        $modeUnit = app(BusinessUnitContext::class)->selected() ? BusinessUnit::find(app(BusinessUnitContext::class)->selected()) : null;
        $simple = ! $modeUnit || ! $modeUnit->advanced_locations_enabled;
        $default = null;
        if ($posMode && $modeUnit && $modeUnit->active) {
            $default = app(UnitOperationalService::class)->ensureDefault($modeUnit);
            $modeUnit->refresh();
            $simple = ! $modeUnit->advanced_locations_enabled;
        }
        $locationQuery = $this->scoped($branchId, StockLocation::query())->where(fn ($q) => $q->where('active', true)->orWhere('system_managed', false));
        if ($posMode) {
            $locationQuery->where('active', true);
            if (! $inventoryMode || $simple || $closeOnly) {
                $locationQuery->where('type', 'COUNTER')->where('business_unit_id', $modeUnit?->id);
            }
            if ($simple) {
                $target = $fixed ? $fixed->id : $default?->id;
                $locationQuery->whereKey($target ?? 0);
                if (! $fixed && ! empty($filters['stock_location_id'])) {
                    app(StockService::class)->require((int) $filters['stock_location_id'] === $target, __('ui.Use the Business Unit operational stock context.'));
                }
            }
        }
        $locations = $locationQuery->when($terminal || ($restrictPosCounters && $visibility->settlementOnly()), fn ($q) => $q->where('type', 'COUNTER')->when($counterIds !== [], fn ($q) => $q->whereIn('id', $counterIds)))->orderBy('id')->get();
        if ($posMode) {
            $locations = $locations->filter(fn ($l) => $l->type !== 'COUNTER' || $actor->canAccessCounter($l->id))->values();
        }
        $activeLocation = $this->scoped($branchId, Shift::where('user_id', $actor->id)->where('status', 'OPEN'))->value('stock_location_id');
        $location = $filters['stock_location_id'] ?? ($locations->contains('id', $activeLocation) ? $activeLocation : ($simple && $posMode ? $default?->id : ($posMode && $locations->where('type', 'COUNTER')->count() > 1 ? null : $locations->firstWhere('type', 'COUNTER')?->id)));
        if ($closeOnly) {
            $location = $fixed->id;
        }
        if ($location) {
            $selected = StockLocation::whereKey($location)->firstOrFail();
            abort_if($branchId && $selected->branch_id != $branchId, 422, 'Location is outside selected branch.');
            abort_if($posMode && ! $locations->contains('id', $selected->id), 403, 'Location is outside your authorized operational context.');
            $location = $selected->id;
        }
        $canInventory = $actor->hasPermission('manage-inventory') || $actor->hasPermission('create-orders');
        $selectedUnit = app(BusinessUnitContext::class)->selected();
        if ($terminal) {
            $selectedUnit = $location ? StockLocation::findOrFail($location)->business_unit_id : null;
        }
        $allowedUnits = array_values(BusinessUnit::pluck('id')->map(fn ($id) => (int) $id)->all());
        $query = Product::query();
        if ($closeOnly || ($posMode && ! $inventoryMode && ! $selectedUnit)) {
            $query->whereRaw('1 = 0');
        } elseif ($selectedUnit) {
            app(BusinessUnitService::class)->filterProducts($query, [$selectedUnit]);
        } elseif (! $actor->hasPermission('manage-products') || ! $actor->hasPermission('all-business-units')) {
            app(BusinessUnitService::class)->filterProducts($query, $allowedUnits);
        }
        $products = $canInventory ? $query->when($terminal, fn ($q) => $q->where('active', true)->whereHas('sellingUnits'))->with(['category', 'baseUnit', 'sellingUnits.unit'])->when(! empty($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))->orderBy('name')->limit(200)->get() : collect();
        $availability = DB::table('business_unit_products')->where('company_id', $actor->company_id)->whereIn('product_id', $products->pluck('id'))->whereIn('business_unit_id', $allowedUnits)->where('available', true)->get()->groupBy('product_id');
        $pricingUnit = $selectedUnit ?: ($location ? StockLocation::findOrFail($location)->business_unit_id : null);
        foreach ($products as $p) {
            if ($pricingUnit && app(BusinessUnitService::class)->available($p->id, $pricingUnit)) {
                foreach ($p->sellingUnits as $selling) {
                    $selling->selling_price = app(BusinessUnitService::class)->price($selling, $pricingUnit);
                }
            }
        }
        $stocks = $location ? Stock::where('stock_location_id', $location)->pluck('quantity', 'product_id') : collect();
        $servingShifts = $visibility->atCounter($this->scoped($branchId, Shift::where('type', 'WAITER')->where('status', 'OPEN')))->when($terminal, fn ($q) => $q->where('stock_location_id', $actor->terminal_stock_location_id))->when(! $terminal && ! $visibility->broad() && ! $visibility->settlementOnly(), fn ($q) => $q->where('user_id', $actor->id));
        $servingWaiters = $actor->hasPermission('create-orders') ? User::where('company_id', $actor->company_id)->where('active', true)->whereHas('role', fn ($q) => $q->where('name', 'Waiter'))->whereIn('id', (clone $servingShifts)->pluck('user_id'))->get()->filter(fn ($w) => (! $terminal || $w->hasPermission('create-orders')) && ($branchId ? $w->canAccessBranch($branchId) : true) && (! $selectedUnit || $w->canAccessBusinessUnit($selectedUnit)))->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'initials' => $w->initials(), 'pin_configured' => (bool) $w->operational_pin, 'counter_ids' => (clone $servingShifts)->where('user_id', $w->id)->pluck('stock_location_id')->all()])->values()->all() : [];
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
        $orders = $actor->hasPermission('view-orders') ? (clone $orderQuery)->with(['items', 'waiter'])->latest()->limit(100)->get() : collect();
        $shift = $this->scoped($branchId, Shift::where('user_id', $actor->id)->where('type', 'CASHIER'))->latest()->first();
        $cash = fn ($method) => $shift ? D::money((string) Payment::where('shift_id', $shift->id)->whereIn('method', (array) $method)->where('is_credit', false)->sum('amount')) : '0.00';
        $payments = $actor->hasPermission('receive-payments') || $actor->hasPermission('view-reports') || $actor->hasPermission('view-waiter-balances') ? $visibility->payments($this->scoped($branchId, Payment::query()))->latest()->limit(100)->get() : collect();
        $allocations = PaymentAllocation::whereIn('payment_id', $payments->pluck('id'))->get()->keyBy('payment_id');
        $canApprove = $actor->hasPermission('approve-void') || $actor->hasPermission('approve-adjustments');
        $voids = $canApprove ? $this->scoped($branchId, VoidRequest::query())->latest()->limit(100)->get() : collect();
        $adjustments = $canApprove || $actor->hasPermission('adjust-stock') ? $this->scoped($branchId, StockAdjustment::with('items'))->latest()->limit(100)->get() : collect();

        $shiftSummary = ['id' => $shift?->id, 'type' => 'CASHIER', 'active' => $shift?->status === 'OPEN', 'opening' => $shift->opening_cash ?? '0', 'cashSales' => $cash('Cash'), 'mobile' => $cash(['M-Pesa', 'Airtel Money', 'Mixx by Yas']), 'card' => $cash('Card'), 'actual' => $shift?->status === 'CLOSED' ? $shift->actual_cash : null, 'cashier' => $actor->name];
        if (! $visibility->broad() && ! $visibility->settlementOnly()) {
            $ownShift = $this->scoped($branchId, Shift::where('user_id', $actor->id)->where('type', 'WAITER'))->orderByRaw("CASE WHEN status = 'OPEN' THEN 0 ELSE 1 END")->latest()->first();
            $shiftSummary = ['id' => $ownShift?->id, 'type' => 'WAITER', 'active' => $ownShift?->status === 'OPEN'];
        }

        if ($terminal) {
            $shiftSummary = ['id' => null, 'type' => null, 'active' => false];
        }

        // Report navigation spans the actor's authorized workspace, independently of the POS selection.
        $context = app(BusinessUnitContext::class);
        $context->select(null);
        try {
            $reportCounters = $actor->hasPermission('view-reports') && ! $terminal
                ? StockLocation::where('type', 'COUNTER')->when($actor->counters_configured, fn ($q) => $q->whereIn('id', DB::table('counter_user')->where('company_id', $actor->company_id)->where('user_id', $actor->id)->select('stock_location_id')))->get(['id', 'name', 'branch_id', 'business_unit_id'])->toArray()
                : [];
        } finally {
            $context->select($selectedUnit);
        }

        return ['operational_context' => ['simple_mode' => $simple, 'advanced_locations_enabled' => ! $simple, 'close_only' => (bool) $closeOnly, 'business_unit_id' => $modeUnit?->id, 'business_unit_name' => $modeUnit?->name, 'default_stock_location_id' => $modeUnit?->default_stock_location_id, 'max_active_cashiers' => $modeUnit?->max_active_cashiers, 'active_cashiers' => $modeUnit ? app(UnitOperationalService::class)->activeCashiers($modeUnit->id) : 0], 'advanced_locations_available' => BusinessUnit::where('active', true)->where('advanced_locations_enabled', true)->exists(), 'pos_unit_options' => $posUnitOptions->toArray(), 'pos_context' => ['broad_unit_access' => $broadPosUnits, 'can_switch_unit' => ! $fixed && $posUnitOptions->count() > 1, 'locked' => (bool) $fixed, 'branch_id' => $fixed?->branch_id, 'business_unit_id' => $fixed?->business_unit_id, 'counter_id' => $fixed?->id], 'report_counters' => $reportCounters, 'business_units' => BusinessUnit::where('active', true)->get(['id', 'branch_id', 'name', 'active', 'default_stock_location_id', 'advanced_locations_enabled', 'max_active_cashiers', 'location_cutover_note'])->map(fn ($unit) => [...$unit->toArray(), 'active_cashiers' => app(UnitOperationalService::class)->activeCashiers($unit->id)])->all(), 'business_unit_id' => app(BusinessUnitContext::class)->selected(), 'company' => $actor->company->name, 'printSettings' => app(SalesTicketService::class)->preferences(), 'companySettings' => ['email' => $actor->company->email, 'phone' => $actor->company->phone], 'currency' => $actor->company->currency, 'timezone' => $actor->company->timezone, 'onboarding' => $actor->hasPermission('manage-company') ? ($actor->company->settings['onboarding'] ?? []) : [], 'role' => $actor->role->name, 'permissions' => $actor->role->permissions()->pluck('name')->filter(fn ($name) => ! $terminal || $actor->hasPermission($name))->values()->all(), 'visibility' => ['staff_operations' => $visibility->broad(), 'settlement_only' => $visibility->settlementOnly()], 'user' => ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email], 'branch' => $branchId ? Branch::whereKey($branchId)->first()?->name : 'All branches', 'branch_id' => $branchId, 'location_id' => $location, 'branches' => $branches->toArray(), 'locations' => $locations->map(fn ($location) => app(CounterLocationPresenter::class)->present($location))->all(), 'categories' => Category::where('active', true)->orderBy('sort_order')->orderBy('name')->pluck('name')->all(), 'categoryRecords' => $terminal ? [] : Category::withCount('products')->orderBy('sort_order')->orderBy('name')->get()->toArray(), 'supplierCategories' => $terminal ? [] : config('suppliers.categories'), 'unitRecords' => $terminal ? [] : Unit::get(['id', 'name'])->toArray(),
            'products' => $products->map(fn ($p) => ['available_unit_ids' => ($availability->get($p->id) ?? collect())->pluck('business_unit_id')->map(fn ($id) => (int) $id)->all(), 'default_price' => D::money((string) ($p->sellingUnits->first()?->getRawOriginal('selling_price') ?? '0')), 'default_units' => $p->sellingUnits->map(fn ($u) => [$u->unit->name, $u->conversion_quantity, D::money((string) $u->getRawOriginal('selling_price')), $u->id])->all(), 'available' => ! $selectedUnit || app(BusinessUnitService::class)->available($p->id, $selectedUnit), 'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'category' => $p->category->name, 'brand' => $p->brand, 'price' => $p->sellingUnits->first()->selling_price ?? '0', 'cost' => $actor->hasPermission('manage-inventory') || $actor->hasPermission('view-reports') ? $p->purchase_cost : '0', 'stock' => D::quantity((string) ($stocks[$p->id] ?? 0)), 'min' => $p->minimum_stock, 'active' => $p->active, 'baseUnit' => $p->baseUnit->name, 'style' => $p->visual_style, 'image' => $p->image_path ? route('nightshift.product-image', ['id' => $p->id], false).'?v='.$p->updated_at?->timestamp : $p->image_url, 'units' => $p->sellingUnits->map(fn ($u) => [$u->unit->name, $u->conversion_quantity, $u->selling_price, $u->id])->all()])->all(),
            'shiftLocations' => $visibility->settlementOnly() && $actor->hasPermission('open-shifts') ? StockLocation::where('type', 'COUNTER')->get(['id', 'company_id', 'branch_id', 'business_unit_id', 'name', 'type', 'max_active_cashiers'])->toArray() : [],
            'terminalLocations' => in_array($actor->role->name, ['Company Admin / Owner', 'Super Admin'], true) ? StockLocation::where('type', 'COUNTER')->where('active', true)->get(['id', 'branch_id', 'business_unit_id', 'name'])->toArray() : [],
            'servingWaiters' => $servingWaiters, 'waiters' => $waiters->all(), 'orders' => $orders->map(fn ($o) => ['business_unit_id' => $o->business_unit_id, 'id' => $o->id, 'invoice' => $o->invoice_number, 'order_note' => $o->order_note, 'waiter' => $o->waiter_id, 'waiter_name' => $o->waiter->name, 'table' => ServiceTable::whereKey($o->service_table_id)->first()->name ?? 'Takeaway', 'table_id' => $o->service_table_id, 'branch' => Branch::whereKey($o->branch_id)->first()?->name, 'counter' => StockLocation::withoutGlobalScopes()->where('company_id', $o->company_id)->whereKey($o->stock_location_id)->first()?->name, 'counter_id' => $o->stock_location_id, 'shift_id' => $o->shift_id, 'status' => str_replace('_', ' ', ucfirst(strtolower($o->status))), 'items' => $o->items->map(function ($it) use ($products) {
                $p = $products->firstWhere('id', $it->product_id);
                $units = $p->sellingUnits ?? collect();

                return ['id' => $it->id, 'product' => $it->product_id, 'name' => $it->product_name, 'unitName' => $it->unit_name, 'unit' => $units->search(fn ($u) => $u->id === $it->product_unit_id) ?: 0, 'unit_id' => $it->product_unit_id, 'qty' => $it->quantity, 'returned_qty' => $it->returned_quantity, 'price' => $it->unit_price];
            })->all(), 'total' => $o->total, 'subtotal' => $o->subtotal, 'discount' => $o->discount, 'paid' => $o->amount_paid, 'balance' => $o->balance, 'time' => $o->created_at->format('H:i'), 'created_at' => $o->created_at->toIso8601String()])->all(),
            'payments' => $payments->map(function ($p) use ($allocations) {
                $order = Order::whereKey($p->order_id ?? $allocations->get($p->id)?->order_id)->first();

                return ['id' => $p->number, 'invoice' => $order?->id, 'waiter' => $order?->waiter_id, 'method' => $p->method, 'is_credit' => (bool) $p->is_credit, 'amount' => $p->amount, 'time' => $p->created_at->format('H:i'), 'branch' => Branch::whereKey($p->branch_id)->first()?->name];
            })->all(),
            'shifts' => $terminal ? [] : $visibility->shifts($this->scoped($branchId, Shift::query()))->latest()->limit(100)->get()->map(function ($s) use ($visibility) {
                return [...(! $visibility->broad() && $s->type === 'WAITER' ? $s->only(['id', 'branch_id', 'user_id', 'stock_location_id', 'number', 'type', 'status', 'operating_date', 'opened_at', 'closed_at', 'closing_notes']) : $s->toArray()), 'business_unit_id' => $s->business_unit_id, 'business_unit' => BusinessUnit::withoutGlobalScopes()->where('company_id', $s->company_id)->whereKey($s->business_unit_id)->value('name'), 'show_location' => app(UnitOperationalService::class)->showLocation($s->company_id, $s->business_unit_id, $s->stock_location_id)];
            })->all(), 'shift' => $shiftSummary,
            'movements' => ($actor->hasPermission('manage-inventory') ? $this->scoped($branchId, StockMovement::query())->latest()->limit(100)->get() : collect())->map(StockMovementPresenter::present(...))->all(),
            'transfers' => ($actor->hasPermission('transfer-stock') ? $this->scoped($branchId, StockTransfer::query())->latest()->limit(100)->get() : collect())->toArray(),
            'approvals' => [...$voids->map(fn ($v) => ['id' => 'void-'.$v->id, 'record_id' => $v->id, 'type' => 'Void request', 'invoice' => $v->order_id, 'amount' => Order::whereKey($v->order_id)->first()?->balance, 'user' => User::where('company_id', $actor->company_id)->whereKey($v->requested_by)->first()?->name, 'reason' => $v->reason, 'status' => ucfirst(strtolower($v->status)), 'time' => $v->created_at->format('H:i')])->all(), ...$adjustments->map(fn ($a) => ['id' => 'adjustment-'.$a->id, 'record_id' => $a->id, 'type' => $a->reason === 'COMPLIMENTARY' ? 'Complimentary' : 'Stock adjustment', 'product' => $a->items->first()?->product_id, 'qty' => $a->items->sum('base_quantity'), 'amount' => $a->items->sum('cost'), 'user' => User::where('company_id', $actor->company_id)->whereKey($a->requested_by)->first()?->name, 'reason' => $a->reason.' — '.$a->notes, 'status' => ucfirst(strtolower($a->status)), 'time' => $a->created_at->format('H:i')])->all()],
            'report_filters' => config('report_pdf.filters'), 'report_default_filters' => config('report_pdf.default_filters'), 'audit' => [], // History is paginated and redacted by AuditTrailController.
            'suppliers' => $actor->hasPermission('manage-inventory') ? Supplier::get()->toArray() : [], 'team' => $actor->hasPermission('manage-users') ? $visibility->unitUsers(User::query())->where('company_id', $actor->company_id)->whereHas('branches', fn ($q) => $q->when($branchId, fn ($q) => $q->where('branches.id', $branchId)))->get(['id', 'name', 'username', 'email', 'phone', 'active', 'role_id', 'operational_pin'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'phone' => $u->phone, 'active' => (bool) $u->active, 'pin_configured' => (bool) $u->operational_pin, 'role' => $u->role->name, 'branch' => $u->branches()->pluck('name')->implode(', ')])->all() : [],
            'tables' => $this->scoped($branchId, ServiceTable::query())->get()->map(fn ($table) => $terminal ? $table->only(['id', 'name', 'branch_id']) : $table->toArray())->all(), 'reservations' => $terminal ? [] : $this->scoped($branchId, ServiceTable::whereNotNull('reservation'))->get()->map(fn ($t) => ['table' => $t->name, ...($t->reservation ?? [])])->all(),
            'notifications' => $terminal ? [] : $visibility->notifications($this->scoped($branchId, BusinessNotification::query()))->latest()->limit(50)->get()->map(function ($n) use ($actor) {
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
