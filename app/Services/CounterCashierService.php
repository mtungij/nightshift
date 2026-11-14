<?php

namespace App\Services;

use App\Domain\TenantContext;
use App\Models\BusinessUnit;
use App\Models\Shift;
use App\Models\StockLocation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class CounterCashierService
{
    public function update(int $id, int $limit): StockLocation
    {
        Gate::authorize('manage-counters');
        abort_unless(app(TenantContext::class)->user()->company->active, 403);
        Validator::make(['max_active_cashiers' => $limit], ['max_active_cashiers' => ['required', 'integer', 'min:1', 'max:65535']])->validate();

        return app(TransactionService::class)->run(function () use ($id, $limit) {
            $location = StockLocation::lockForUpdate()->findOrFail($id);
            app(StockService::class)->require($location->type === 'COUNTER', __('ui.Cashier limits apply only to operational counters.'));
            $unit = BusinessUnit::whereKey($location->business_unit_id)->firstOrFail();
            if (! $unit->advanced_locations_enabled) {
                app(UnitOperationalService::class)->updateLimit($unit, $limit);

                return $location->fresh();
            }
            $active = $location->active_cashiers;
            $message = $limit === 1 && $active === 2 ? __('ui.Close one active cashier shift before reducing the limit to 1.') : __('ui.Close :count active cashier shifts before reducing the limit to :limit.', ['count' => max(0, $active - $limit), 'limit' => $limit]);
            app(StockService::class)->require($limit >= $active, $message);
            $old = $location->max_active_cashiers;
            if ($old !== $limit) {
                $location->update(['max_active_cashiers' => $limit]);
                app(AuditService::class)->record('CASHIER_LIMIT_UPDATED', 'Counters', 'StockLocation', $id, ['max_active_cashiers' => $old], ['max_active_cashiers' => $limit], $location->branch_id);
            }

            return $location;
        });
    }

    // Preserve the handover freeze for the entire shared physical counter.
    public function assertOperational(int $counter): void
    {
        $shifts = Shift::withoutGlobalScopes()->where('company_id', app(TenantContext::class)->companyId())->where('stock_location_id', $counter)->where('type', 'CASHIER')->where('status', 'OPEN')->orderBy('id')->lockForUpdate()->get();
        $location = StockLocation::findOrFail($counter);
        app(StockService::class)->require(app(UnitOperationalService::class)->activeCashiers($location->business_unit_id) <= app(UnitOperationalService::class)->limit($location), __('ui.This Business Unit exceeds its cashier limit. Complete supervised cutover.'));
        app(StockService::class)->require($shifts->count() <= app(UnitOperationalService::class)->physicalLimit($location), __('ui.This counter has overlapping legacy cashier shifts. Ask a manager to complete supervised cutover.'));
        foreach ($shifts as $shift) {
            app(StockService::class)->require(app(CashHandoverService::class)->active($shift) === null, __('ui.Cash handover is in progress. Finish closing the cashier shift first.'));
        }
    }
}
