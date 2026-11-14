<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\OperationalShift;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ShiftService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private WaiterLedgerService $ledger, private AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function open(array $data): Shift
    {
        Gate::authorize('open-shifts');

        return $this->tx->run(function () use ($data) {
            $actor = app(TenantContext::class)->user();
            $user = User::where('company_id', $actor->company_id)->whereKey($data['user_id'] ?? $actor->id)->lockForUpdate()->firstOrFail();
            if ($user->id !== $actor->id) {
                Gate::authorize('manage-users');
            }
            $location = app(UnitOperationalService::class)->resolve($data, staff: $user);
            $location = $location->newQuery()->lockForUpdate()->findOrFail($location->id);
            $this->stock->require($location->type === 'COUNTER', 'Shifts must be opened at a counter.');
            $this->stock->require($data['type'] === 'CASHIER' ? $user->hasPermission('issue-drinks') : $user->hasPermission('create-orders'), 'Employee is not authorized for this shift type.');
            $this->stock->require($user->active && $user->canAccessBranch($location->branch_id) && $user->canAccessCounter($location->id), 'Employee cannot access this branch.');
            $this->stock->require(! $user->isPosTerminal(), __('ui.POS Terminal cannot own a shift.'));
            $type = app(OperationalShift::class)->type($user);
            $this->stock->require(! $type || $data['type'] === $type, 'Employee is not authorized for this shift type.');
            $this->stock->require(Branch::findOrFail($location->branch_id)->active, 'Branch is inactive.');
            $this->stock->require(! Shift::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->where('status', 'OPEN')->exists(), 'Employee already has an open shift.');
            $parent = null;
            $opening = '0.00';
            $handoverId = isset($data['handover_id']) ? (int) $data['handover_id'] : null;
            if ($data['type'] === 'CASHIER') {
                $unit = BusinessUnit::whereKey($location->business_unit_id)->lockForUpdate()->firstOrFail();
                $unitActive = app(UnitOperationalService::class)->activeCashiers($unit->id);
                $this->stock->require($unitActive < $unit->max_active_cashiers, __('ui.This Business Unit already has the maximum number of active cashiers.'));
                $active = $location->active_cashiers;
                $this->stock->require($active < app(UnitOperationalService::class)->physicalLimit($location), __('ui.This counter already has the maximum number of active cashiers.'));
                app(CounterCashierService::class)->assertOperational($location->id);
                if ($active === 0) {
                    $this->stock->require($this->counterBlockers($location->id, (int) $actor->company_id) === [], __('ui.Close all waiter shifts and settle all balances before reconciliation.'));
                }
                $opening = app(CashHandoverService::class)->opening($location->id, $user, $handoverId);
                if (D::cmp((string) ($data['opening_cash'] ?? '0'), '0') !== 0) {
                    Gate::authorize('authorize-opening-cash');
                    $this->stock->require($handoverId === null && D::cmp($opening, '0') === 0 && mb_strlen(trim($data['opening_reason'] ?? '')) >= 8, __('ui.An authorized opening cash adjustment needs a reason.'));
                    $opening = D::money($data['opening_cash']);
                    $this->stock->require(D::cmp($opening, '0') >= 0, __('ui.Enter a valid opening cash amount.'));
                }
            } else {
                $this->stock->require(D::cmp($this->ledger->balance($user->id), '0') <= 0, __('ui.Settle outstanding debt from previous shifts before opening a new waiter shift.'));
                $this->stock->require(! Order::withoutGlobalScopes()->where('company_id', $user->company_id)->where('waiter_id', $user->id)->whereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE', 'VOID_REQUESTED'])->exists(), __('ui.Resolve pending orders and settlements before opening a new waiter shift.'));
                $this->stock->require($handoverId === null && D::cmp((string) ($data['opening_cash'] ?? '0'), '0') === 0, __('ui.Waiter shifts do not carry opening cash.'));
                $parent = $this->counterCashier($location->id);
                $this->stock->require(app(CashHandoverService::class)->active($parent) === null, __('ui.Cash handover is in progress. Finish closing the cashier shift first.'));
            }
            $shift = Shift::create(['branch_id' => $location->branch_id, 'user_id' => $user->id, 'stock_location_id' => $location->id, 'number' => $this->numbers->next('SHF'), 'type' => $data['type'], 'status' => 'OPEN', 'opening_cash' => $opening, 'opened_at' => now(), 'opened_by' => $actor->id, 'cashier_shift_id' => $parent?->id]);
            if ($handoverId !== null) {
                app(CashHandoverService::class)->applied($handoverId, $shift);
            }
            app(ShiftStockService::class)->opening($shift);
            $this->audit->record('SHIFT_OPENED', 'Shifts', 'Shift', $shift->id, [], ['number' => $shift->number, 'user_id' => $user->id, 'opened_by' => $actor->id, 'counter_id' => $location->id, 'opening_cash' => $shift->opening_cash, 'opening_reason' => $data['opening_reason'] ?? null, 'handover_id' => $handoverId, 'cashier_shift_id' => $parent?->id], $shift->branch_id);

            return $shift;
        });
    }

    // Called within TransactionService: the company lock serializes open, issue,
    // settlement, stock count and final close, including immediate POS issuance.
    public function counterCashier(int $location): Shift
    {
        $counter = $this->stock->location($location);
        $shifts = Shift::where('stock_location_id', $location)->where('type', 'CASHIER')->where('status', 'OPEN')->orderBy('id')->lockForUpdate()->get();
        $this->stock->require($shifts->isNotEmpty(), __('ui.Open the cashier shift at this counter first.'));
        $this->stock->require($shifts->count() <= app(UnitOperationalService::class)->physicalLimit($counter), __('ui.This counter has overlapping legacy cashier shifts. Ask a manager to complete supervised cutover.'));

        app(CounterCashierService::class)->assertOperational($location);

        return $shifts->firstOrFail();
    }

    /** @return list<array{waiter_id: int, name: string, open: bool, balance: string}> */
    public function blockers(Shift $shift): array
    {
        return $this->counterBlockers($shift->stock_location_id, $shift->company_id);
    }

    /** @return list<array{waiter_id: int, name: string, open: bool, balance: string}> */
    private function counterBlockers(int $counter, int $company): array
    {
        abort_unless($company === app(TenantContext::class)->companyId(), 404);
        $location = StockLocation::withoutGlobalScopes()->where('company_id', $company)->findOrFail($counter);
        $unit = BusinessUnit::withoutGlobalScopes()->where('company_id', $company)->find($location->business_unit_id);
        $unitScope = $unit && ! $unit->advanced_locations_enabled ? $unit->id : null;
        $saved = fn (string $table) => DB::table('operational_unit_contexts')->where('company_id', $company)->where('record_type', $table)->where('business_unit_id', $unitScope)->select('record_id');
        $open = Shift::withoutGlobalScopes()->where('company_id', $company)->when($unitScope, fn ($q) => $q->whereIn('id', $saved('shifts')), fn ($q) => $q->where('stock_location_id', $counter))->where('type', 'WAITER')->where('status', 'OPEN')->pluck('user_id')->all();
        // Include legacy and closed waiter shifts: closing a session or an override
        // never forgives debt. Do not hide blockers after staff reassignment.
        $orders = Order::withoutGlobalScopes()->where('company_id', $company)->when($unitScope, fn ($q) => $q->whereIn('id', $saved('orders')), fn ($q) => $q->where('stock_location_id', $counter))->select('id');
        $balances = WaiterLedgerEntry::withoutGlobalScopes()->where('company_id', $company)->whereIn('order_id', $orders)->select('waiter_id')
            ->selectRaw('SUM(debit) - SUM(credit) AS outstanding')->groupBy('waiter_id')->get();
        $debts = [];
        foreach ($balances as $row) {
            $balance = (string) $row->getAttribute('outstanding');
            if (D::cmp($balance, '0') > 0) {
                $debts[$row->waiter_id] = $balance;
            }
        }
        $result = [];
        foreach (array_unique([...$open, ...array_keys($debts)]) as $id) {
            $result[] = ['waiter_id' => (int) $id, 'name' => User::where('company_id', $company)->whereKey($id)->firstOrFail()->name, 'open' => in_array($id, $open, true), 'balance' => $debts[$id] ?? '0.00'];
        }

        return $result;
    }

    public function activeCashier(int $location): Shift
    {
        $actor = app(TenantContext::class)->user();
        app(CounterCashierService::class)->assertOperational($location);
        $shift = Shift::where('user_id', $actor->id)->where('stock_location_id', $location)->where('type', 'CASHIER')->where('status', 'OPEN')->lockForUpdate()->first();
        if (! $shift && $actor->hasPermission('manage-users') && $actor->hasPermission('view-staff-operations')) {
            return $this->counterCashier($location);
        }
        $this->stock->require((bool) $shift, 'Open your cashier shift at this counter first.');

        return $shift;
    }

    /** @return numeric-string */
    public function expected(Shift $shift): string
    {
        abort_unless($shift->company_id === app(TenantContext::class)->companyId(), 404);
        Gate::authorize('view', $shift);

        if ($shift->status === 'CLOSED') {
            return $shift->expected_cash;
        }

        return D::add($shift->opening_cash, (string) Payment::withoutGlobalScopes()->where('company_id', $shift->company_id)->where('shift_id', $shift->id)->where('method', 'Cash')->sum('amount'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function close(int $id, array $data): Shift
    {
        Gate::authorize('close-shifts');

        return $this->tx->run(function () use ($id, $data) {
            $shift = Shift::lockForUpdate()->findOrFail($id);
            Gate::authorize('view', $shift);
            $actor = app(TenantContext::class)->user();
            if ($shift->user_id !== $actor->id) {
                Gate::authorize('manage-users');
            }
            $this->stock->require($shift->status === 'OPEN', 'Shift is already closed.');
            $old = ['status' => $shift->status];
            if ($shift->type === 'WAITER') {
                $balance = $this->ledger->balance($shift->user_id);
                $this->stock->require(D::cmp($balance, '0') <= 0, __('ui.Cannot close shift. :name has an outstanding balance of TSh :balance.', ['name' => User::where('company_id', $shift->company_id)->findOrFail($shift->user_id)->name, 'balance' => $balance]));
                $this->stock->require(! Order::where('shift_id', $shift->id)->whereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE'])->exists(), 'Submit or cancel pending orders before closing the shift.');
            } else {
                $blockers = $this->blockers($shift);
                $this->stock->require($blockers === [], __('ui.Cannot close cashier shift. Settle balances and close waiter shifts: :waiters', ['waiters' => implode('; ', array_map(fn ($b) => $b['name'].' ('.($b['open'] ? __('ui.Open') : __('ui.Closed')).', TSh '.$b['balance'].')', $blockers))]));
                $this->stock->require(array_key_exists('actual_cash', $data) && $data['actual_cash'] !== null, __('ui.Enter actual cash before closing the cashier shift.'));
                $expected = $this->expected($shift);
                $actual = D::money($data['actual_cash'] ?? '0');
                $this->stock->require(D::cmp($actual, $shift->actual_cash ?? '0') === 0, __('ui.Cash changed. Save cash reconciliation and physical counts again.'));
                $difference = D::sub($actual, $expected);
                $this->stock->require(D::cmp($difference, '0') === 0 || strlen(trim($data['notes'] ?? '')) >= 8, 'Explain the cash shortage or excess.');
                $shift->expected_cash = $expected;
                $shift->actual_cash = $actual;
                $shift->cash_difference = $difference;
            }
            $shift->closing_notes = $data['notes'] ?? null;
            if ($shift->type === 'CASHIER') {
                app(ShiftStockService::class)->ready($shift);
            }
            $handover = $shift->type === 'CASHIER' ? app(CashHandoverService::class)->disposition($shift) : null;
            if ($shift->type === 'CASHIER') {
                app(ShiftStockService::class)->finalize($shift);
            }
            $shift->status = 'CLOSED';
            $shift->closed_at = now();
            $shift->closing_notes = $data['notes'] ?? null;
            $shift->save();
            if ($handover !== null && $handover->destination === 'TREASURY') {
                app(TreasuryService::class)->release($handover);
            }
            $this->audit->record('SHIFT_CLOSED', 'Shifts', 'Shift', $shift->id, $old, ['status' => 'CLOSED', 'actual' => $shift->actual_cash, 'difference' => $shift->cash_difference], $shift->branch_id);

            return $shift;
        });
    }
}
