<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\OperationalShift;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\CashHandover;
use App\Models\CashHandoverEvent;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CashHandoverService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private AuditService $audit) {}

    public function latest(CashHandover $handover): CashHandoverEvent
    {
        return CashHandoverEvent::where('handover_id', $handover->id)->latest('id')->firstOrFail();
    }

    public function active(Shift $shift): ?CashHandover
    {
        foreach (CashHandover::where('source_shift_id', $shift->id)->latest('id')->get() as $handover) {
            if (! in_array($this->latest($handover)->status, ['CANCELLED', 'REVERSED'], true)) {
                return $handover;
            }
        }

        return null;
    }

    private function owner(Shift $shift): void
    {
        Gate::authorize('close-shifts');
        Gate::authorize('view', $shift);
        if ($shift->user_id !== app(TenantContext::class)->user()->id) {
            Gate::authorize('manage-users');
        }
        abort_unless($shift->type === 'CASHIER', 403);
    }

    public function recipient(int $id, string $destination, int $branch): User
    {
        abort_unless(app(TenantContext::class)->user()->company->active, 403);
        $user = User::where('company_id', app(TenantContext::class)->companyId())->findOrFail($id);
        $this->stock->require($user->active && $user->canAccessBranch($branch) && ! $user->isPosTerminal(), __('ui.The recipient cannot receive cash at this branch.'));
        $allowed = $destination === 'NEXT_CASHIER'
            ? app(OperationalShift::class)->type($user) === 'CASHIER'
            : $destination === 'TREASURY' && $user->hasPermission('receive-treasury');
        $this->stock->require($allowed, __('ui.Select an authorized cash recipient.'));

        return $user;
    }

    /** @param array<string, mixed> $data */
    public function stage(int $shiftId, array $data): CashHandover
    {
        return $this->tx->run(function () use ($shiftId, $data) {
            $shift = Shift::lockForUpdate()->findOrFail($shiftId);
            $this->owner($shift);
            $this->stock->require($shift->status === 'OPEN', __('ui.This shift is already finalized.'));
            $this->stock->require($this->active($shift) === null, __('ui.This shift already has a cash handover.'));
            $report = app(ShiftStockService::class)->report($shift->id);
            $this->stock->require($report['blockers'] === [] && $report['complete'] && $report['totals']['ledger_mismatches'] === 0 && ($report['totals']['variance_count'] === 0 || $report['approved']), __('ui.Complete shift reconciliation before requesting cash receipt.'));
            $amount = D::money($data['amount']);
            $destination = $data['destination'];
            $this->stock->require(D::cmp($amount, $shift->actual_cash) === 0 && D::cmp($amount, '0') > 0, __('ui.Hand over the full counted cash amount.'));
            $recipient = $this->recipient((int) $data['to_user_id'], $destination, $shift->branch_id);
            $this->stock->require($destination !== 'NEXT_CASHIER' || $recipient->id !== $shift->user_id, __('ui.Select a different receiving cashier.'));

            return $this->create($shift, $shift->user_id, $recipient->id, $destination, $amount, $report['basis'], $data['note'] ?? null);
        });
    }

    public function create(Shift $shift, int $from, ?int $to, string $destination, string $amount, ?string $basis = null, ?string $note = null, ?int $replaces = null): CashHandover
    {
        if ($to) {
            $this->stock->require(User::where('company_id', $shift->company_id)->findOrFail($to)->canAccessBusinessUnit($shift->business_unit_id), __('ui.Select an authorized cash recipient.'));
        }
        if ($to && $destination === 'NEXT_CASHIER') {
            $this->stock->require(User::where('company_id', $shift->company_id)->findOrFail($to)->canAccessCounter($shift->stock_location_id), __('ui.Select an authorized cash recipient.'));
        }
        $h = CashHandover::create(['branch_id' => $shift->branch_id, 'stock_location_id' => $shift->stock_location_id, 'source_shift_id' => $shift->id, 'from_user_id' => $from, 'to_user_id' => $to, 'created_by' => app(TenantContext::class)->user()->id, 'replaces_id' => $replaces, 'number' => $this->numbers->next('CHO'), 'destination' => $destination, 'amount' => D::money($amount), 'basis' => $basis, 'note' => $note]);
        $this->event($h, $destination === 'NONE' ? 'NO_CASH' : 'PENDING', $amount, '0', $note);

        return $h;
    }

    public function event(CashHandover $h, string $status, ?string $amount = null, ?string $difference = null, ?string $note = null, ?int $shift = null, ?int $entry = null): CashHandoverEvent
    {
        $old = CashHandoverEvent::where('handover_id', $h->id)->latest('id')->first();
        $event = CashHandoverEvent::create(['branch_id' => $h->branch_id, 'handover_id' => $h->id, 'created_by' => app(TenantContext::class)->user()->id, 'status' => $status, 'amount' => $amount, 'difference' => $difference, 'note' => $note, 'related_shift_id' => $shift, 'ledger_entry_id' => $entry]);
        $this->audit->record('CASH_HANDOVER_'.$status, 'Cash custody', 'CashHandover', $h->id, $old?->only(['status', 'amount', 'difference']) ?? [], ['status' => $status, 'source_shift' => $h->source_shift_id, 'from' => $h->from_user_id, 'to' => $h->to_user_id, 'expected' => $h->amount, 'received' => $amount, 'difference' => $difference, 'reason' => $note, 'related_shift' => $shift, 'ledger_entry' => $entry], $h->branch_id);

        return $event;
    }

    public function receive(int $id, string $amount, ?string $note = null): CashHandover
    {
        return $this->tx->run(function () use ($id, $amount, $note) {
            $h = CashHandover::lockForUpdate()->findOrFail($id);
            $actor = app(TenantContext::class)->user();
            abort_unless($h->to_user_id === $actor->id, 403);
            $this->recipient($actor->id, $h->destination, $h->branch_id);
            $this->stock->require($this->latest($h)->status === 'PENDING', __('ui.This handover has already been processed.'));
            $source = Shift::findOrFail($h->source_shift_id);
            $this->stock->require($h->destination !== 'NEXT_CASHIER' || ! Shift::where('user_id', $actor->id)->where('status', 'OPEN')->exists(), __('ui.Close your current shift before receiving the next counter handover.'));
            $this->stock->require($h->destination !== 'NEXT_CASHIER' || $source->status === 'CLOSED', __('ui.The previous cashier must finalize the shift first.'));
            $received = D::money($amount);
            $this->stock->require(D::cmp($received, '0') >= 0, __('ui.Enter a valid received cash amount.'));
            $difference = D::sub($received, $h->amount);
            $disputed = D::cmp($difference, '0') !== 0;
            $this->stock->require(! $disputed || mb_strlen(trim($note ?? '')) >= 8, __('ui.Explain the handover difference.'));
            $this->event($h, $disputed ? 'DISPUTED' : 'RECEIVED', $received, $difference, $note);
            if (! $disputed && $source->status === 'CLOSED' && $h->destination === 'TREASURY') {
                app(TreasuryService::class)->release($h);
            }

            return $h;
        });
    }

    public function approve(int $id, string $note): CashHandover
    {
        Gate::authorize('approve-cash-discrepancies');

        return $this->tx->run(function () use ($id, $note) {
            $h = CashHandover::lockForUpdate()->findOrFail($id);
            $last = $this->latest($h);
            $this->stock->require($last->status === 'DISPUTED' && mb_strlen(trim($note)) >= 8, __('ui.Review the disputed amount and provide an approval reason.'));
            $this->event($h, 'APPROVED', $last->amount, $last->difference, $note);
            if ($h->destination === 'TREASURY' && Shift::findOrFail($h->source_shift_id)->status === 'CLOSED') {
                app(TreasuryService::class)->release($h);
            }

            return $h;
        });
    }

    /** @param array<string, mixed> $data */
    public function cancel(int $id, array $data): CashHandover
    {
        return $this->tx->run(function () use ($id, $data) {
            $h = CashHandover::lockForUpdate()->findOrFail($id);
            $shift = Shift::findOrFail($h->source_shift_id);
            $actor = app(TenantContext::class)->user();
            if ($shift->status !== 'OPEN' || $actor->id !== $shift->user_id) {
                Gate::authorize('manage-cash-handovers');
            } else {
                $this->owner($shift);
            }
            $this->stock->require(in_array($this->latest($h)->status, ['PENDING', 'DISPUTED'], true), __('ui.Received cash requires an audited reversal.'));
            $note = trim($data['note'] ?? '');
            $this->stock->require(mb_strlen($note) >= 8, __('ui.Explain the cash custody correction.'));
            if ($shift->status === 'CLOSED') {
                $to = $this->recipient((int) ($data['to_user_id'] ?? 0), $data['destination'] ?? '', $shift->branch_id);
                $this->stock->require($to->id !== $h->to_user_id || $data['destination'] !== $h->destination, __('ui.Select a replacement cash recipient.'));
                $this->create($shift, $h->from_user_id, $to->id, $data['destination'], $h->amount, null, $note, $h->id);
            }
            $this->event($h, 'CANCELLED', null, null, $note);

            return $h;
        });
    }

    /** @return list<CashHandover> */
    public function awaiting(int $counter): array
    {
        return array_values(CashHandover::where('stock_location_id', $counter)->where('destination', 'NEXT_CASHIER')->orderBy('id')->get()
            ->filter(fn ($h) => in_array($this->latest($h)->status, ['PENDING', 'RECEIVED', 'DISPUTED', 'APPROVED'], true))->values()->all());
    }

    public function opening(int $counter, User $cashier, ?int $id): string
    {
        $pending = $this->awaiting($counter);
        if (StockLocation::findOrFail($counter)->max_active_cashiers > 1) {
            $pending = array_values(array_filter($pending, fn ($h) => $h->to_user_id === $cashier->id));
        }
        if ($pending === []) {
            $this->stock->require($id === null, __('ui.No cash handover is available for this counter.'));

            return '0.00';
        }
        $this->stock->require(count($pending) === 1, __('ui.A manager must resolve the pending cash handovers.'));
        $h = $pending[0];
        $this->stock->require($id === $h->id && $h->to_user_id === $cashier->id, __('ui.Confirm the assigned counter handover before opening this shift.'));
        // A manager may open for a cashier, but cannot attest receipt for them.
        if ($this->latest($h)->status === 'PENDING') {
            abort_unless(app(TenantContext::class)->user()->id === $cashier->id, 403);
            $this->receive($h->id, $h->amount);
        }
        $last = $this->latest($h);
        $this->stock->require(in_array($last->status, ['RECEIVED', 'APPROVED'], true), __('ui.A manager must approve the handover difference before opening.'));

        return D::money($last->amount ?? '0');
    }

    public function applied(int $id, Shift $shift): void
    {
        $h = CashHandover::findOrFail($id);
        $last = $this->latest($h);
        $this->event($h, 'OPENED', $last->amount, $last->difference, null, $shift->id);
    }

    public function disposition(Shift $shift): CashHandover
    {
        $h = $this->active($shift);
        if (D::cmp($shift->actual_cash, '0') === 0 && ! $h) {
            $h = $this->create($shift, $shift->user_id, null, 'NONE', '0');
        }
        $this->stock->require($h !== null, __('ui.Select where the counted cash is being handed over.'));
        $h ??= throw new \LogicException('Missing cash disposition');
        $this->stock->require(D::cmp($h->amount, $shift->actual_cash) === 0, __('ui.Hand over the full counted cash amount.'));
        if ($h->destination === 'TREASURY') {
            $this->stock->require(in_array($this->latest($h)->status, ['RECEIVED', 'APPROVED'], true), __('ui.The accountant or admin must confirm cash receipt before final close.'));
        } elseif ($h->destination === 'NEXT_CASHIER') {
            $this->stock->require($this->latest($h)->status === 'PENDING', __('ui.The next cashier handover is not pending receipt.'));
        } else {
            $this->stock->require($h->destination === 'NONE' && D::cmp($shift->actual_cash, '0') === 0, __('ui.Select where the counted cash is being handed over.'));
        }
        $report = app(ShiftStockService::class)->report($shift->id);
        $this->stock->require($h->basis === null || hash_equals($h->basis, $report['basis']), __('ui.Cash or stock changed. Cancel the unreceived handover and reconcile again.'));

        return $h;
    }

    /** @return array<string, mixed> */
    public function present(CashHandover $h): array
    {
        $last = $this->latest($h);
        $receipt = CashHandoverEvent::where('handover_id', $h->id)->whereIn('status', ['RECEIVED', 'DISPUTED', 'APPROVED', 'OPENED'])->latest('id')->first();
        $source = Shift::findOrFail($h->source_shift_id);
        $user = fn (?int $id) => $id ? User::where('company_id', $h->company_id)->findOrFail($id)->name : null;

        return [...$h->toArray(), 'show_location' => app(UnitOperationalService::class)->showLocation($h->company_id, $h->business_unit_id, $h->stock_location_id), 'business_unit_id' => $h->business_unit_id, 'business_unit' => BusinessUnit::withoutGlobalScopes()->where('company_id', $h->company_id)->find($h->business_unit_id)?->name, 'status' => $last->status, 'received_amount' => $receipt && $receipt->status !== 'DISPUTED' ? $receipt->amount : null, 'reported_amount' => $receipt?->amount, 'difference' => $receipt?->difference, 'from_name' => $user($h->from_user_id), 'to_name' => $user($h->to_user_id), 'branch' => Branch::findOrFail($h->branch_id)->name, 'counter' => StockLocation::withoutGlobalScopes()->where('company_id', $h->company_id)->findOrFail($h->stock_location_id)->name, 'source_number' => $source->number, 'source_user_id' => $source->user_id, 'source_status' => $source->status, 'events' => CashHandoverEvent::where('handover_id', $h->id)->orderBy('id')->get()->map(fn ($event) => [...$event->toArray(), 'actor' => $user($event->created_by)])->all()];
    }
}
