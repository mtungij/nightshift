<?php

namespace App\Services;

use App\Domain\BusinessUnitContext;
use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class BusinessUnitService
{
    /** @param array<string, mixed> $data */
    public function save(array $data): BusinessUnit
    {
        Gate::authorize('manage-business-units');

        return app(TransactionService::class)->run(function () use ($data) {
            $branch = Branch::whereKey($data['branch_id'])->firstOrFail();
            $unit = empty($data['id']) ? new BusinessUnit : BusinessUnit::lockForUpdate()->whereKey($data['id'])->firstOrFail();
            $old = $unit->toArray();
            app(StockService::class)->require(! $unit->exists || $unit->branch_id === $branch->id, __('ui.Business Unit branch cannot be changed.'));
            if ($unit->exists && ! $data['active']) {
                $this->idle($unit->id);
            }
            app(StockService::class)->require(! BusinessUnit::where('branch_id', $branch->id)->where(fn ($q) => $q->where('code', $data['code'])->orWhere('name', $data['name']))->when($unit->exists, fn ($q) => $q->where('id', '!=', $unit->id))->exists(), __('ui.Business Unit name or code already exists.'));
            app(StockService::class)->require(! $unit->exists || $unit->code !== 'GENERAL' || $data['code'] === 'GENERAL', __('ui.Keep the General code for legacy access.'));
            if (array_key_exists('advanced_locations_enabled', $data) && $unit->exists && (bool) $data['advanced_locations_enabled'] !== $unit->advanced_locations_enabled) {
                $this->idle($unit->id);
                foreach (StockLocation::where('business_unit_id', $unit->id)->where('id', '!=', $unit->default_stock_location_id)->get() as $legacy) {
                    $this->idle($unit->id, $legacy->id);
                    app(StockService::class)->require((bool) $data['advanced_locations_enabled'] || ! DB::table('stocks')->where('company_id', $unit->company_id)->where('stock_location_id', $legacy->id)->where('quantity', '!=', 0)->exists(), __('ui.Transfer remaining legacy stock before enabling simple mode.'));
                }
            }
            $unit->fill(['branch_id' => $branch->id, 'name' => $data['name'], 'code' => $data['code'], 'active' => $data['active']]);
            if (! $unit->exists) {
                $unit->created_by = app(TenantContext::class)->user()->id;
            }
            if (array_key_exists('advanced_locations_enabled', $data)) {
                $unit->advanced_locations_enabled = (bool) $data['advanced_locations_enabled'];
            }
            $unit->save();
            $unit->refresh();
            if ($unit->active) {
                app(UnitOperationalService::class)->ensureDefault($unit);
            }
            if (array_key_exists('max_active_cashiers', $data)) {
                app(UnitOperationalService::class)->updateLimit($unit, (int) $data['max_active_cashiers']);
                $unit->refresh();
            }
            if (isset($old['advanced_locations_enabled']) && $old['advanced_locations_enabled'] !== $unit->advanced_locations_enabled) {
                app(AuditService::class)->record('UNIT_LOCATION_MODE_UPDATED', 'Business Units', 'BusinessUnit', $unit->id, ['advanced_locations_enabled' => $old['advanced_locations_enabled']], ['advanced_locations_enabled' => $unit->advanced_locations_enabled], $unit->branch_id);
            }
            app(AuditService::class)->record('BUSINESS_UNIT_SAVED', 'Business Units', 'BusinessUnit', $unit->id, $old, $unit->toArray(), $branch->id);

            return $unit;
        });
    }

    public function idle(int $unit, ?int $locationId = null): void
    {
        $locations = $locationId ? collect([$locationId]) : StockLocation::where('business_unit_id', $unit)->pluck('id');
        $busy = Shift::where('status', 'OPEN')->whereIn('stock_location_id', $locations)->exists()
            || Order::whereIn('stock_location_id', $locations)->whereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE', 'ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED'])->exists()
            || User::where('company_id', app(TenantContext::class)->companyId())->where('active', true)->whereIn('terminal_stock_location_id', $locations)->exists()
            || DB::table('waiter_ledger_entries')->where('company_id', app(TenantContext::class)->companyId())->whereIn('order_id', DB::table('orders')->where('company_id', app(TenantContext::class)->companyId())->whereIn('stock_location_id', $locations)->select('id'))->select('waiter_id')->groupBy('waiter_id')->havingRaw('SUM(debit) - SUM(credit) > 0')->exists()
            || DB::table('stock_adjustments')->where('company_id', app(TenantContext::class)->companyId())->whereIn('stock_location_id', $locations)->where('status', 'PENDING')->exists()
            || DB::table('stock_transfers')->where('company_id', app(TenantContext::class)->companyId())->whereIn('status', ['DRAFT', 'IN_TRANSIT'])->where(fn ($q) => $q->whereIn('source_location_id', $locations)->orWhereIn('destination_location_id', $locations))->exists()
            || DB::table('cash_handovers')->where('company_id', app(TenantContext::class)->companyId())->whereIn('stock_location_id', $locations)->whereExists(function ($q) {
                $q->selectRaw('1')->from('cash_handover_events as e')->whereColumn('e.handover_id', 'cash_handovers.id')->whereIn('e.status', ['PENDING', 'DISPUTED', 'RECEIVED', 'APPROVED'])->where(fn ($q) => $q->whereIn('e.status', ['PENDING', 'DISPUTED'])->orWhere('cash_handovers.destination', 'NEXT_CASHIER'))->whereRaw('e.id = (SELECT MAX(e2.id) FROM cash_handover_events e2 WHERE e2.handover_id = e.handover_id)');
            })->exists();
        app(StockService::class)->require(! $busy, __('ui.Finish active shifts, terminals and unresolved work before changing this Business Unit.'));
    }

    /** @param array<string,mixed> $data */
    public function location(array $data): StockLocation
    {
        Gate::authorize('manage-business-units');

        return app(TransactionService::class)->run(function () use ($data) {
            $location = StockLocation::lockForUpdate()->whereKey($data['id'])->firstOrFail();
            app(StockService::class)->require(! BusinessUnit::where('default_stock_location_id', $location->id)->exists() || (! $data['is_shared'] && $data['unit_ids'] === [$location->business_unit_id]), __('ui.Default operational locations cannot be reassigned.'));
            if ($location->business_unit_id) {
                $this->idle($location->business_unit_id, $location->id);
            }
            if (! $location->business_unit_id) {
                $this->idle(0, $location->id);
            }
            $units = BusinessUnit::whereIn('id', $data['unit_ids'])->where('branch_id', $location->branch_id)->where('active', true)->get();
            app(StockService::class)->require($units->count() === count($data['unit_ids']) && $units->isNotEmpty(), __('ui.Choose Business Units in the same branch.'));
            $shared = (bool) $data['is_shared'];
            app(StockService::class)->require(! $shared || $location->type === 'STORE', __('ui.Only stores can be shared.'));
            app(StockService::class)->require($shared || $units->count() === 1, __('ui.Counters require one Business Unit.'));
            $old = $location->toArray();
            $location->update(['business_unit_id' => $shared ? null : $units->first()->id, 'is_shared' => $shared]);
            DB::table('business_unit_location')->where('stock_location_id', $location->id)->where('company_id', $location->company_id)->delete();
            if ($shared) {
                foreach ($units as $unit) {
                    DB::table('business_unit_location')->insert(['company_id' => $location->company_id, 'branch_id' => $location->branch_id, 'business_unit_id' => $unit->id, 'stock_location_id' => $location->id]);
                }
            }
            app(AuditService::class)->record('LOCATION_UNIT_ASSIGNED', 'Business Units', 'StockLocation', $location->id, $old, [...$location->toArray(), 'unit_ids' => $data['unit_ids']], $location->branch_id);

            return $location;
        });
    }

    /** @param list<int> $ids */
    public function branches(User $user, array $ids): void
    {
        Gate::authorize('manage-business-units');
        $this->assertManagedUser($user);
        app(StockService::class)->require(Branch::whereIn('id', $ids)->count() === count($ids) && $ids !== [], __('ui.Choose authorized Business Units.'));
        $company = app(TenantContext::class)->companyId();
        $open = DB::table('shifts')->where('company_id', $company)->where('user_id', $user->id)->where('status', 'OPEN')->whereNotIn('branch_id', $ids)->exists();
        $pending = DB::table('cash_handovers')->where('company_id', $company)->whereNotIn('branch_id', $ids)->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))->whereExists(function ($q) {
            $q->selectRaw('1')->from('cash_handover_events as e')->whereColumn('e.handover_id', 'cash_handovers.id')->whereIn('e.status', ['PENDING', 'DISPUTED', 'RECEIVED', 'APPROVED'])->where(fn ($q) => $q->whereIn('e.status', ['PENDING', 'DISPUTED'])->orWhere('cash_handovers.destination', 'NEXT_CASHIER'))->whereRaw('e.id = (SELECT MAX(e2.id) FROM cash_handover_events e2 WHERE e2.handover_id = e.handover_id)');
        })->exists();
        $cash = DB::table('treasury_entries')->where('company_id', $company)->where('custodian_id', $user->id)->whereNotIn('branch_id', $ids)->select('branch_id')->groupBy('branch_id')->havingRaw("SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END) <> 0")->exists();
        app(StockService::class)->require(! $open && ! $pending && ! $cash, __('ui.Resolve shifts, handovers and treasury cash before deactivating staff.'));
        if ($user->isPosTerminal()) {
            $location = StockLocation::whereKey($user->terminal_stock_location_id)->firstOrFail();
            app(StockService::class)->require(in_array($location->branch_id, $ids, true), __('ui.Terminal and Business Unit must match.'));
        }
        $before = $user->branches()->withoutGlobalScopes()->pluck('branches.id')->all();
        $user->branches()->sync(array_fill_keys($ids, ['company_id' => $company]));
        app(AuditService::class)->record('USER_BRANCHES_ASSIGNED', 'Business Units', 'User', $user->id, ['branch_ids' => $before], ['branch_ids' => $ids]);
    }

    public function status(User $user, bool $active): void
    {
        Gate::authorize('manage-business-units');
        $this->assertManagedUser($user);
        app(StockService::class)->require(in_array($user->role->name, [...config('business.staff_roles'), 'POS Terminal'], true), __('ui.Choose authorized Business Units.'));
        if ($active && $user->isPosTerminal()) {
            $location = app(StockService::class)->location((int) $user->terminal_stock_location_id);
            app(StockService::class)->require((bool) Branch::whereKey($location->branch_id)->firstOrFail()->active, 'Branch is inactive.');
        }
        if ((bool) $user->active === $active) {
            return;
        }
        if (! $active) {
            $company = app(TenantContext::class)->companyId();
            $open = DB::table('shifts')->where('company_id', $company)->where('user_id', $user->id)->where('status', 'OPEN')->exists();
            $pending = DB::table('cash_handovers')->where('company_id', $company)->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))->whereExists(function ($q) {
                $q->selectRaw('1')->from('cash_handover_events as e')->whereColumn('e.handover_id', 'cash_handovers.id')->whereIn('e.status', ['PENDING', 'DISPUTED', 'RECEIVED', 'APPROVED'])->where(fn ($q) => $q->whereIn('e.status', ['PENDING', 'DISPUTED'])->orWhere('cash_handovers.destination', 'NEXT_CASHIER'))->whereRaw('e.id = (SELECT MAX(e2.id) FROM cash_handover_events e2 WHERE e2.handover_id = e.handover_id)');
            })->exists();
            $cash = DB::table('treasury_entries')->where('company_id', $company)->where('custodian_id', $user->id)->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END),0) AS cash")->first()->cash;
            app(StockService::class)->require(! $open && ! $pending && D::cmp((string) $cash, '0') === 0, __('ui.Resolve shifts, handovers and treasury cash before deactivating staff.'));
        }
        $before = (bool) $user->active;
        $user->forceFill(['active' => $active])->save();
        app(AuditService::class)->record('USER_STATUS_CHANGED', 'Business Units', 'User', $user->id, ['active' => $before], ['active' => $active]);
    }

    private function assertManagedUser(User $user): void
    {
        $actor = app(TenantContext::class)->user();
        abort_unless($user->company_id === $actor->company_id, 403);
        $allowed = app(BusinessUnitContext::class)->ids($actor);
        if ($allowed === null || $user->wasRecentlyCreated) {
            return;
        }
        $existing = DB::table('business_unit_user')->where('company_id', $user->company_id)->where('user_id', $user->id)->pluck('business_unit_id')->all();
        if (! $user->business_units_configured) {
            $legacy = DB::table('business_units')->where('company_id', $user->company_id)->where('code', 'GENERAL')->whereIn('branch_id', DB::table('branch_user')->where('company_id', $user->company_id)->where('user_id', $user->id)->select('branch_id'))->pluck('id')->all();
            $existing = array_unique([...$existing, ...$legacy]);
        }
        abort_unless($existing !== [] && array_diff($existing, $allowed) === [], 403);
    }

    /** @param list<int> $ids */
    public function assign(User $user, array $ids): void
    {
        Gate::authorize('manage-users');
        $this->assertManagedUser($user);
        abort_unless($user->company_id === app(TenantContext::class)->companyId(), 403);
        $units = BusinessUnit::whereIn('id', $ids)->get();
        app(StockService::class)->require($units->count() === count($ids), __('ui.Choose authorized Business Units.'));
        foreach ($units as $unit) {
            app(StockService::class)->require($user->canAccessBranch($unit->branch_id), __('ui.Assign the branch before its Business Units.'));
        }
        $current = $user->businessUnits()->withoutGlobalScopes()->pluck('business_units.id')->all();
        $pending = DB::table('cash_handovers')->where('company_id', $user->company_id)->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
            ->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', $user->company_id)->where('record_type', 'cash_handovers')->whereNotIn('business_unit_id', $ids)->select('record_id'))
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('cash_handover_events as e')->whereColumn('e.handover_id', 'cash_handovers.id')->whereIn('e.status', ['PENDING', 'DISPUTED', 'RECEIVED', 'APPROVED'])->where(fn ($q) => $q->whereIn('e.status', ['PENDING', 'DISPUTED'])->orWhere('cash_handovers.destination', 'NEXT_CASHIER'))->whereRaw('e.id = (SELECT MAX(e2.id) FROM cash_handover_events e2 WHERE e2.handover_id = e.handover_id)');
            })->exists();
        app(StockService::class)->require(! $pending, __('ui.Resolve pending cash handovers before removing unit access.'));

        $open = Shift::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->where('status', 'OPEN')->get();
        foreach ($open as $shift) {
            app(StockService::class)->require(in_array($shift->business_unit_id, $ids, true), __('ui.Close active shifts before removing unit access.'));
        }
        if ($user->isPosTerminal()) {
            $location = StockLocation::findOrFail($user->terminal_stock_location_id);
            app(StockService::class)->require(count($ids) === 1 && $location->business_unit_id === $ids[0], __('ui.Terminal and Business Unit must match.'));
            $user->forceFill(['terminal_business_unit_id' => $ids[0], 'terminal_stock_location_id' => $location->id])->save();
        }
        $user->businessUnits()->sync(array_fill_keys($ids, ['company_id' => $user->company_id]));
        $user->forceFill(['business_units_configured' => true])->save();
        app(AuditService::class)->record('USER_UNITS_ASSIGNED', 'Business Units', 'User', $user->id, ['unit_ids' => $current], ['unit_ids' => $ids]);
    }

    /** @param list<int> $ids */
    public function counters(User $user, array $ids): void
    {
        Gate::authorize('manage-users');
        $this->assertManagedUser($user);
        abort_unless($user->company_id === app(TenantContext::class)->companyId(), 403);
        $locations = StockLocation::where('type', 'COUNTER')->whereIn('id', $ids)->get();
        app(StockService::class)->require($locations->count() === count($ids), __('ui.Choose authorized counters.'));
        foreach ($locations as $location) {
            app(StockService::class)->require($user->canAccessBusinessUnit($location->business_unit_id), __('ui.Terminal and Business Unit must match.'));
        }
        app(StockService::class)->require(! Shift::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->where('status', 'OPEN')->whereNotIn('stock_location_id', $ids)->exists(), __('ui.Close active shifts before removing unit access.'));
        DB::table('counter_user')->where('company_id', $user->company_id)->where('user_id', $user->id)->delete();
        foreach ($ids as $id) {
            DB::table('counter_user')->insert(['company_id' => $user->company_id, 'user_id' => $user->id, 'stock_location_id' => $id]);
        }
        $user->forceFill(['counters_configured' => true])->save();
        app(AuditService::class)->record('USER_COUNTERS_ASSIGNED', 'Business Units', 'User', $user->id, [], ['counter_ids' => $ids]);
    }

    /** Apply the same explicit availability rule before search/limits. Shared stores
     * accept the union of their explicitly associated units, never company-wide stock.
     *
     * @param  Builder<Product>  $query
     * @param  list<int>  $unitIds
     * @return Builder<Product>
     */
    public function filterProducts(Builder $query, array $unitIds): Builder
    {
        return $query->whereIn('products.id', DB::table('business_unit_products')->where('company_id', app(TenantContext::class)->companyId())->whereIn('business_unit_id', $unitIds)->where('available', true)->select('product_id'));
    }

    /** @return list<int> */
    public function locationUnits(StockLocation $location): array
    {
        if (! $location->is_shared) {
            return $location->business_unit_id ? [(int) $location->business_unit_id] : [];
        }

        return array_values(DB::table('business_unit_location')->where('company_id', $location->company_id)->where('stock_location_id', $location->id)->pluck('business_unit_id')->map(fn ($id) => (int) $id)->all());
    }

    public function availableAtLocation(int $product, StockLocation $location): bool
    {
        return DB::table('business_unit_products')->where('company_id', $location->company_id)->where('product_id', $product)->whereIn('business_unit_id', $this->locationUnits($location))->where('available', true)->exists();
    }

    /** @param list<array<string,mixed>> $settings */
    public function configureProduct(Product $product, array $settings, bool $creating = false): void
    {
        Gate::authorize('manage-products');
        Validator::make(['settings' => $settings], [
            'settings' => ['array', 'max:100'], 'settings.*.business_unit_id' => ['required', 'integer', 'min:1', 'distinct'],
            'settings.*.available' => ['required', 'boolean'], 'settings.*.prices' => ['present', 'array', 'max:100'],
            'settings.*.prices.*.unit_id' => ['required', 'integer', 'min:1'],
            'settings.*.prices.*.selling_price' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/D'],
        ])->validate();
        app(StockService::class)->require(! $creating || collect($settings)->contains(fn ($s) => (bool) $s['available']), __('ui.Select at least one Business Unit.'));
        foreach ($settings as $setting) {
            $ids = array_column($setting['prices'], 'unit_id');
            app(StockService::class)->require(count($ids) === count(array_unique($ids)), __('ui.Duplicate selling unit price.'));
            $prices = [];
            foreach ($setting['prices'] as $price) {
                $selling = ProductUnit::where('product_id', $product->id)->where('unit_id', $price['unit_id'])->firstOrFail();
                $prices[] = ['product_unit_id' => $selling->id, 'selling_price' => $price['selling_price']];
            }
            $this->product(['product_id' => $product->id, 'business_unit_id' => $setting['business_unit_id'], 'available' => $setting['available'], 'prices' => $prices]);
        }
    }

    public function available(int $product, int $unit): bool
    {
        $value = DB::table('business_unit_products')->where('company_id', app(TenantContext::class)->companyId())->where('business_unit_id', $unit)->where('product_id', $product)->value('available');

        return $value !== null && (bool) $value;
    }

    public function price(ProductUnit $productUnit, int $unit): string
    {
        app(StockService::class)->require($this->available($productUnit->product_id, $unit), __('ui.Product is unavailable in this Business Unit.'));

        return D::money((string) (DB::table('business_unit_prices')->where('company_id', app(TenantContext::class)->companyId())->where('business_unit_id', $unit)->where('product_unit_id', $productUnit->id)->value('selling_price') ?? $productUnit->selling_price));
    }

    /** @param array<string,mixed> $data */
    public function product(array $data): void
    {
        Gate::authorize('manage-products');
        Validator::make($data, ['available' => ['required', 'boolean'], 'prices' => ['array'], 'prices.*.selling_price' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/D']])->validate();
        $unit = BusinessUnit::whereKey($data['business_unit_id'])->firstOrFail();
        $product = Product::whereKey($data['product_id'])->firstOrFail();
        $old = DB::table('business_unit_products')->where('company_id', $product->company_id)->where('business_unit_id', $unit->id)->where('product_id', $product->id)->first();
        DB::table('business_unit_products')->updateOrInsert(['company_id' => $product->company_id, 'business_unit_id' => $unit->id, 'product_id' => $product->id], ['available' => $data['available'], 'created_at' => now(), 'updated_at' => now()]);
        $prices = [];
        foreach ($data['prices'] ?? [] as $line) {
            $selling = ProductUnit::where('product_id', $product->id)->whereKey($line['product_unit_id'])->firstOrFail();
            $key = ['company_id' => $product->company_id, 'business_unit_id' => $unit->id, 'product_unit_id' => $selling->id];
            $prices[] = ['id' => $selling->id, 'before' => DB::table('business_unit_prices')->where($key)->value('selling_price'), 'after' => $line['selling_price']];
            if ($line['selling_price'] === null || $line['selling_price'] === '') {
                DB::table('business_unit_prices')->where($key)->delete();
            } else {
                DB::table('business_unit_prices')->updateOrInsert($key, ['selling_price' => D::money($line['selling_price']), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if ((bool) ($old->available ?? false) !== (bool) $data['available']) {
            app(AuditService::class)->record($data['available'] ? 'PRODUCT_UNIT_ENABLED' : 'PRODUCT_UNIT_DISABLED', 'Business Units', 'Product', $product->id, ['business_unit_id' => $unit->id, 'available' => (bool) ($old->available ?? false)], ['business_unit_id' => $unit->id, 'available' => (bool) $data['available']], $unit->branch_id);
        }
        foreach ($prices as $price) {
            if ($price['before'] !== null || $price['after'] !== null) {
                $after = $price['after'] === null ? null : D::money($price['after']);
                if (($price['before'] === null) !== ($after === null) || ($after !== null && $price['before'] !== null && D::cmp($price['before'], $after) !== 0)) {
                    app(AuditService::class)->record('BUSINESS_UNIT_PRICE_CHANGED', 'Business Units', 'Product', $product->id, ['business_unit_id' => $unit->id, 'product_unit_id' => $price['id'], 'selling_price' => $price['before']], ['business_unit_id' => $unit->id, 'product_unit_id' => $price['id'], 'selling_price' => $after], $unit->branch_id);
                }
            }
        }
        app(AuditService::class)->record('PRODUCT_UNIT_CONFIGURATION', 'Business Units', 'Product', $product->id, ['configuration' => (array) $old], ['business_unit_id' => $unit->id, 'available' => $data['available'], 'prices' => $prices], $unit->branch_id);
    }
}
