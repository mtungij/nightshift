<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\OperationalShift;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
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
            $location = $this->stock->location($data['stock_location_id']);
            $this->stock->require($location->type === 'COUNTER', 'Shifts must be opened at a counter.');
            $this->stock->require($data['type'] === 'CASHIER' ? $user->hasPermission('issue-drinks') : $user->hasPermission('create-orders'), 'Employee is not authorized for this shift type.');
            $this->stock->require($user->active && $user->canAccessBranch($location->branch_id), 'Employee cannot access this branch.');
            $type = app(OperationalShift::class)->type($user);
            $this->stock->require(! $type || $data['type'] === $type, 'Employee is not authorized for this shift type.');
            $this->stock->require(Branch::findOrFail($location->branch_id)->active, 'Branch is inactive.');
            $this->stock->require(! Shift::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->where('status', 'OPEN')->exists(), 'Employee already has an open shift.');
            $shift = Shift::create(['branch_id' => $location->branch_id, 'user_id' => $user->id, 'stock_location_id' => $location->id, 'number' => $this->numbers->next('SHF'), 'type' => $data['type'], 'status' => 'OPEN', 'opening_cash' => D::money($data['opening_cash'] ?? '0'), 'opened_at' => now()]);
            $this->audit->record('SHIFT_OPENED', 'Shifts', 'Shift', $shift->id, [], ['number' => $shift->number], $shift->branch_id);

            return $shift;
        });
    }

    public function activeCashier(int $location): Shift
    {
        $actor = app(TenantContext::class)->user();
        $shift = Shift::where('user_id', $actor->id)->where('stock_location_id', $location)->where('type', 'CASHIER')->where('status', 'OPEN')->lockForUpdate()->first();
        $this->stock->require((bool) $shift, 'Open your cashier shift at this counter first.');

        return $shift;
    }

    /** @return numeric-string */
    public function expected(Shift $shift): string
    {
        Gate::authorize('view', $shift);

        return D::add($shift->opening_cash, (string) Payment::where('shift_id', $shift->id)->where('method', 'Cash')->sum('amount'));
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
                if (D::cmp($balance, '0') > 0) {
                    Gate::authorize('override-shifts');
                    $reason = trim($data['override_reason'] ?? '');
                    $this->stock->require(strlen($reason) >= 8, ($actor->hasPermission('all-branches') || $shift->user_id === $actor->id ? "Cannot close shift: outstanding {$balance}. A manager override needs a reason." : 'Cannot close shift while the account has outstanding debt. An override needs a reason.'));
                    $shift->override_reason = $reason;
                    $shift->authorized_by = $actor->id;
                    $shift->override_at = now();
                    $this->audit->record('SHIFT_OVERRIDE', 'Shifts', 'Shift', $shift->id, ['outstanding' => $balance], ['reason' => $reason, 'authorized_by' => $actor->id], $shift->branch_id);
                }
                $this->stock->require(! Order::where('shift_id', $shift->id)->whereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE'])->exists(), 'Submit or cancel pending orders before closing the shift.');
            } else {
                $expected = $this->expected($shift);
                $actual = D::money($data['actual_cash'] ?? '0');
                $difference = D::sub($actual, $expected);
                $this->stock->require(D::cmp($difference, '0') === 0 || strlen(trim($data['notes'] ?? '')) >= 8, 'Explain the cash shortage or excess.');
                $shift->expected_cash = $expected;
                $shift->actual_cash = $actual;
                $shift->cash_difference = $difference;
            }
            $shift->status = 'CLOSED';
            $shift->closed_at = now();
            $shift->closing_notes = $data['notes'] ?? null;
            $shift->save();
            $this->audit->record('SHIFT_CLOSED', 'Shifts', 'Shift', $shift->id, $old, ['status' => 'CLOSED', 'actual' => $shift->actual_cash, 'difference' => $shift->cash_difference], $shift->branch_id);

            return $shift;
        });
    }
}
