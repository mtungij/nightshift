<?php

namespace App\Services;

use App\Domain\BusinessUnitContext;
use App\Domain\TenantContext;
use App\Models\BusinessUnit;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Resolves configuration; never copies balances or rewrites operational history. */
final class UnitOperationalService
{
    public function ensureDefault(BusinessUnit $unit): StockLocation
    {
        abort_unless($unit->company_id === app(TenantContext::class)->companyId() && (app(TenantContext::class)->user()->canAccessBusinessUnit($unit->id) || (app(TenantContext::class)->user()->hasPermission('manage-business-units') && app(TenantContext::class)->user()->canAccessBranch($unit->branch_id))), 403);

        return $this->configuredDefault($unit);
    }

    /** Model creation has already enforced the tenant; branch assignment follows creation. */
    public function initializeCreatedUnit(BusinessUnit $unit): StockLocation
    {
        abort_unless($unit->wasRecentlyCreated && $unit->company_id === app(TenantContext::class)->companyId(), 403);

        return $this->configuredDefault($unit);
    }

    private function configuredDefault(BusinessUnit $unit): StockLocation
    {
        app(StockService::class)->require($unit->active, __('ui.Business Unit is inactive.'));
        if ($unit->default_stock_location_id) {
            $location = StockLocation::withoutGlobalScopes()->where('company_id', $unit->company_id)->findOrFail($unit->default_stock_location_id);
            app(StockService::class)->require($location->branch_id === $unit->branch_id && $location->business_unit_id === $unit->id && $location->type === 'COUNTER' && $location->active && ! $location->is_shared, __('ui.This Business Unit has no operational stock location configured.'));

            return $location;
        }

        return app(TransactionService::class)->run(function () use ($unit) {
            $unit = BusinessUnit::withoutGlobalScopes()->where('company_id', app(TenantContext::class)->companyId())->lockForUpdate()->findOrFail($unit->id);
            app(StockService::class)->require($unit->active, __('ui.Business Unit is inactive.'));
            if ($unit->default_stock_location_id) {
                $location = StockLocation::withoutGlobalScopes()->where('company_id', $unit->company_id)->findOrFail($unit->default_stock_location_id);
                app(StockService::class)->require($location->branch_id === $unit->branch_id && $location->business_unit_id === $unit->id && $location->type === 'COUNTER' && $location->active && ! $location->is_shared, __('ui.This Business Unit has no operational stock location configured.'));

                return $location;
            }
            $candidates = StockLocation::withoutGlobalScopes()->where('company_id', $unit->company_id)->where('branch_id', $unit->branch_id)->where('business_unit_id', $unit->id)->where('active', true)->where('type', 'COUNTER')->where('is_shared', false)->orderBy('id')->get();
            $created = $candidates->count() !== 1;
            $location = $created ? StockLocation::create(['branch_id' => $unit->branch_id, 'business_unit_id' => $unit->id, 'name' => $unit->name.' Stock', 'type' => 'COUNTER', 'active' => true, 'system_managed' => true, 'max_active_cashiers' => $unit->max_active_cashiers]) : $candidates->first();
            $unit->update(['default_stock_location_id' => $location->id, 'advanced_locations_enabled' => $unit->advanced_locations_enabled || $candidates->count() > 1, 'location_cutover_note' => $candidates->count() > 1 ? 'Multiple legacy locations require supervised cutover. No stock was moved.' : $unit->location_cutover_note]);
            app(AuditService::class)->record($created ? 'UNIT_DEFAULT_LOCATION_CREATED' : 'UNIT_DEFAULT_LOCATION_ADOPTED', 'Business Units', 'BusinessUnit', $unit->id, [], ['stock_location_id' => $location->id], $unit->branch_id);

            return $location;
        });
    }

    /** Supervised configuration change; existing balances and historical references stay untouched. */
    public function adoptDefault(BusinessUnit $unit, StockLocation $location): void
    {
        Gate::authorize('manage-business-units');
        app(TransactionService::class)->run(function () use ($unit, $location) {
            $unit = BusinessUnit::lockForUpdate()->findOrFail($unit->id);
            $location = StockLocation::lockForUpdate()->findOrFail($location->id);
            app(StockService::class)->require($unit->active && $location->active && $location->company_id === $unit->company_id && $location->branch_id === $unit->branch_id && $location->business_unit_id === $unit->id && $location->type === 'COUNTER' && ! $location->is_shared, __('ui.Business Unit and branch must match.'));
            if ($unit->default_stock_location_id === $location->id) {
                return;
            }
            app(BusinessUnitService::class)->idle($unit->id);
            $previous = $unit->default_stock_location_id;
            app(StockService::class)->require(! $previous || ! DB::table('stocks')->where('company_id', $unit->company_id)->where('stock_location_id', $previous)->where('quantity', '!=', 0)->exists(), __('ui.Transfer remaining legacy stock before enabling simple mode.'));
            $unit->update(['default_stock_location_id' => $location->id]);
            if (! $unit->advanced_locations_enabled) {
                $location->update(['max_active_cashiers' => $unit->max_active_cashiers]);
            }
            // An unused generated default may be retired, never deleted.
            if ($previous) {
                $retired = StockLocation::whereKey($previous)->where('system_managed', true)->lockForUpdate()->first();
                $retired?->update(['active' => false]);
            }
            app(AuditService::class)->record('UNIT_DEFAULT_LOCATION_ADOPTED', 'Business Units', 'BusinessUnit', $unit->id, ['stock_location_id' => $previous], ['stock_location_id' => $location->id], $unit->branch_id);
        });
    }

    /** @param array<string,mixed> $data */
    public function resolve(array $data, string $unitKey = 'business_unit_id', string $locationKey = 'stock_location_id', ?User $staff = null): StockLocation
    {
        $actor = app(TenantContext::class)->user();
        $unitId = $data[$unitKey] ?? null;
        if (! $unitId && ! empty($data[$locationKey])) {
            // Backwards-compatible internal callers retain an explicit stored location.
            return app(StockService::class)->location((int) $data[$locationKey]);
        }
        $unit = BusinessUnit::whereKey($unitId)->firstOrFail();
        abort_unless($actor->canAccessBusinessUnit($unit->id) && (! $staff || $staff->canAccessBusinessUnit($unit->id)), 403);
        app(StockService::class)->require(! isset($data['branch_id']) || (int) $data['branch_id'] === $unit->branch_id, __('ui.Business Unit and branch must match.'));
        app(BusinessUnitContext::class)->select($unit->id);
        $location = $this->ensureDefault($unit);
        if ($unit->advanced_locations_enabled && ! empty($data[$locationKey])) {
            $location = app(StockService::class)->location((int) $data[$locationKey]);
        }
        app(StockService::class)->require(($location->business_unit_id === $unit->id || ($unit->advanced_locations_enabled && $location->is_shared && $location->type === 'STORE' && DB::table('business_unit_location')->where('company_id', $unit->company_id)->where('business_unit_id', $unit->id)->where('stock_location_id', $location->id)->exists())) && $location->branch_id === $unit->branch_id, __('ui.Business Unit and branch must match.'));
        if (! $unit->advanced_locations_enabled && ! empty($data[$locationKey])) {
            // An existing open shift is allowed to finish on its saved legacy location.
            $legacy = Shift::withoutGlobalScopes()->where('company_id', $unit->company_id)->where('user_id', ($staff ?? $actor)->id)->where('status', 'OPEN')->where('stock_location_id', $data[$locationKey])->first();
            if ($legacy && $legacy->business_unit_id === $unit->id) {
                $location = app(StockService::class)->location((int) $legacy->stock_location_id);
            } else {
                app(StockService::class)->require((int) $data[$locationKey] === $location->id, __('ui.Use the Business Unit operational stock context.'));
            }
        }
        abort_unless(($location->type !== 'COUNTER' || $actor->canAccessCounter($location->id)) && (! $staff || $staff->canAccessCounter($location->id)), 403);

        return $location;
    }

    public function showLocation(int $company, ?int $unitId, int $locationId): bool
    {
        abort_unless($company === app(TenantContext::class)->companyId(), 403);
        $unit = BusinessUnit::withoutGlobalScopes()->where('company_id', $company)->whereKey($unitId)->first();

        return ! $unit || $unit->advanced_locations_enabled || $unit->default_stock_location_id !== $locationId;
    }

    public function activeCashiers(int $unitId): int
    {
        return DB::table('shifts as s')->join('operational_unit_contexts as c', fn ($q) => $q->on('c.company_id', '=', 's.company_id')->on('c.record_id', '=', 's.id')->where('c.record_type', 'shifts'))->where('s.company_id', app(TenantContext::class)->companyId())->where('c.business_unit_id', $unitId)->where('s.type', 'CASHIER')->where('s.status', 'OPEN')->count();
    }

    public function limit(StockLocation $location): int
    {
        $unit = BusinessUnit::withoutGlobalScopes()->where('company_id', $location->company_id)->find($location->business_unit_id);

        return $unit ? $unit->max_active_cashiers : $location->max_active_cashiers;
    }

    public function physicalLimit(StockLocation $location): int
    {
        $unit = BusinessUnit::withoutGlobalScopes()->where('company_id', $location->company_id)->find($location->business_unit_id);

        return $unit && ! $unit->advanced_locations_enabled ? $unit->max_active_cashiers : $location->max_active_cashiers;
    }

    public function updateLimit(BusinessUnit $unit, int $limit): void
    {
        abort_unless(Gate::allows('manage-business-units') || Gate::allows('manage-counters'), 403);
        Validator::make(['max_active_cashiers' => $limit], ['max_active_cashiers' => ['required', 'integer', 'min:1', 'max:65535']])->validate();
        app(TransactionService::class)->run(function () use ($unit, $limit) {
            $unit = BusinessUnit::lockForUpdate()->findOrFail($unit->id);
            app(StockService::class)->require($this->activeCashiers($unit->id) <= $limit, __('ui.Close active cashier shifts before reducing this Business Unit limit.'));
            $old = $unit->max_active_cashiers;
            $unit->update(['max_active_cashiers' => $limit]);
            if (! $unit->advanced_locations_enabled && $unit->default_stock_location_id) {
                $default = StockLocation::whereKey($unit->default_stock_location_id)->lockForUpdate()->firstOrFail();
                $default->update(['max_active_cashiers' => $limit]);
            }
            if ($old !== $limit) {
                app(AuditService::class)->record('UNIT_CASHIER_LIMIT_UPDATED', 'Business Units', 'BusinessUnit', $unit->id, ['max_active_cashiers' => $old], ['max_active_cashiers' => $limit], $unit->branch_id);
            }
        });
    }
}
