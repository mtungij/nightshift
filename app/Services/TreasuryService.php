<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\CashHandover;
use App\Models\GoodsReceipt;
use App\Models\Shift;
use App\Models\TreasuryEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class TreasuryService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private AuditService $audit) {}

    public function balance(int $custodian, int $branch): string
    {
        $entries = TreasuryEntry::where('custodian_id', $custodian)->where('branch_id', $branch)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END), 0) AS balance")->toBase()->first();

        return D::add((string) $entries->balance, '0');
    }

    // Cash is credited only when both physical receipt and shift finalization exist.
    // The company lock spans receipt, final close and the unique ledger claim.
    public function release(CashHandover $h): ?TreasuryEntry
    {
        if ($h->destination !== 'TREASURY') {
            return null;
        }
        $event = app(CashHandoverService::class)->latest($h);
        $this->stock->require(in_array($event->status, ['RECEIVED', 'APPROVED'], true), __('ui.Confirm the physical cash receipt first.'));
        $this->stock->require(Shift::findOrFail($h->source_shift_id)->status === 'CLOSED', __('ui.The previous cashier must finalize the shift first.'));
        if ($old = TreasuryEntry::where('handover_id', $h->id)->first()) {
            return $old;
        }
        if (D::cmp($event->amount ?? '0', '0') === 0) {
            return null;
        }
        $before = $this->balance((int) $h->to_user_id, $h->branch_id);
        $entry = $this->entry(['branch_id' => $h->branch_id, 'custodian_id' => $h->to_user_id, 'direction' => 'IN', 'transaction_type' => 'CASHIER_RECEIPT', 'amount' => $event->amount, 'handover_id' => $h->id, 'reference_type' => 'CashHandover', 'reference_id' => (string) $h->id, 'external_reference' => $h->number, 'description' => $h->number.' · '.Shift::findOrFail($h->source_shift_id)->number.' · '.User::where('company_id', $h->company_id)->findOrFail($h->from_user_id)->name, 'occurred_at' => now()]);
        $this->auditEntry('TREASURY_RECEIPT', $entry, $before);

        return $entry;
    }

    /** @param array<string, mixed> $data */
    private function entry(array $data): TreasuryEntry
    {
        return TreasuryEntry::create([...$data, 'reference_id' => $data['reference_id'] ?? $data['external_reference'], 'number' => $this->numbers->next('TRY'), 'created_by' => app(TenantContext::class)->user()->id]);
    }

    private function auditEntry(string $action, TreasuryEntry $entry, string $before, ?string $note = null): void
    {
        $this->audit->record($action, 'Treasury', 'TreasuryEntry', $entry->id, ['balance' => $before], ['balance' => $this->balance($entry->custodian_id, $entry->branch_id), 'custodian' => $entry->custodian_id, 'direction' => $entry->direction, 'amount' => $entry->amount, 'type' => $entry->transaction_type, 'reference' => $entry->external_reference, 'source' => $entry->reference_type.' '.$entry->reference_id, 'reason' => $note ?? $entry->description], $entry->branch_id);
    }

    /** @param array<string, mixed> $data */
    public function spend(array $data): TreasuryEntry
    {
        Gate::authorize('view-treasury');
        $permission = match ($data['transaction_type']) {
            'PURCHASE' => 'treasury-purchases', 'EXPENSE' => 'treasury-expenses', 'SALARY' => 'treasury-wages', 'BANK_DEPOSIT' => 'treasury-bank-deposits', default => abort(422),
        };
        Gate::authorize($permission);

        return $this->tx->run(function () use ($data) {
            $actor = app(TenantContext::class)->user();
            $branch = Branch::findOrFail((int) $data['branch_id']);
            $this->stock->require($branch->active && $actor->canAccessBranch($branch->id), __('ui.Select an active permitted branch.'));
            $amount = D::money($data['amount']);
            $this->stock->require(D::cmp($amount, '0') > 0, __('ui.Enter a cash amount greater than zero.'));
            $before = $this->balance($actor->id, $branch->id);
            $this->stock->require(D::cmp($before, $amount) >= 0, __('ui.Insufficient available treasury cash.'));
            $reference = trim($data['external_reference']);
            $this->stock->require($reference !== '' && ! TreasuryEntry::where('transaction_type', $data['transaction_type'])->where('external_reference', $reference)->exists(), __('ui.This cash transaction reference has already been posted.'));
            $fields = ['branch_id' => $branch->id, 'custodian_id' => $actor->id, 'direction' => 'OUT', 'transaction_type' => $data['transaction_type'], 'amount' => $amount, 'reference_type' => 'Treasury', 'external_reference' => $reference, 'description' => trim($data['description']), 'occurred_at' => $data['occurred_at']];
            $this->stock->require(mb_strlen($fields['description']) >= 3, __('ui.Describe the cash transaction.'));
            if ($data['transaction_type'] === 'PURCHASE') {
                $grn = GoodsReceipt::lockForUpdate()->findOrFail((int) ($data['goods_receipt_id'] ?? 0));
                $this->stock->require($grn->branch_id === $branch->id, __('ui.The purchase must belong to this branch.'));
                $spent = TreasuryEntry::where('goods_receipt_id', $grn->id)->where('direction', 'OUT')->get();
                $paid = '0.00';
                foreach ($spent as $payment) {
                    if (! TreasuryEntry::where('reversal_of', $payment->id)->exists()) {
                        $paid = D::add($paid, $payment->amount);
                    }
                }
                $this->stock->require(D::cmp(D::add($paid, $amount), $grn->total_cost) <= 0, __('ui.This purchase is already paid or the amount exceeds its unpaid cost.'));
                $fields += ['goods_receipt_id' => $grn->id, 'reference_type' => 'GoodsReceipt', 'reference_id' => (string) $grn->id];
                $fields['reference_type'] = 'GoodsReceipt';
            } elseif ($data['transaction_type'] === 'EXPENSE') {
                $this->stock->require(in_array($data['category'] ?? '', config('treasury.expense_categories'), true), __('ui.Select an expense category.'));
                $fields['category'] = $data['category'];
            } elseif ($data['transaction_type'] === 'SALARY') {
                $employee = User::where('company_id', $actor->company_id)->findOrFail((int) ($data['employee_id'] ?? 0));
                $this->stock->require(! $employee->isPosTerminal() && $employee->canAccessBranch($branch->id) && trim($data['pay_period'] ?? '') !== '', __('ui.Select an assigned employee and payment period.'));
                $fields += ['employee_id' => $employee->id, 'pay_period' => trim($data['pay_period']), 'reference_type' => 'User', 'reference_id' => (string) $employee->id];
                $fields['reference_type'] = 'User';
            } else {
                $this->stock->require(trim($data['bank'] ?? '') !== '', __('ui.Enter the bank and deposit reference.'));
                $fields['bank'] = trim($data['bank']);
            }
            $entry = $this->entry($fields);
            $this->auditEntry('TREASURY_'.$entry->transaction_type, $entry, $before);

            return $entry;
        });
    }

    /** @param array<string, mixed> $data */
    public function reverse(int $id, array $data): TreasuryEntry
    {
        Gate::authorize('reverse-treasury');
        Gate::authorize('view-treasury');

        return $this->tx->run(function () use ($id, $data) {
            $old = TreasuryEntry::lockForUpdate()->findOrFail($id);
            $actor = app(TenantContext::class)->user();
            abort_unless($old->custodian_id === $actor->id || $actor->hasPermission('oversee-treasury'), 403);
            $this->stock->require($old->transaction_type !== 'REVERSAL' && ! TreasuryEntry::where('reversal_of', $old->id)->exists(), __('ui.This entry has already been reversed.'));
            $note = trim($data['note']);
            $this->stock->require(mb_strlen($note) >= 8, __('ui.Explain the cash custody correction.'));
            $before = $this->balance($old->custodian_id, $old->branch_id);
            if ($old->direction === 'IN') {
                $this->stock->require($old->handover_id !== null && D::cmp($before, $old->amount) >= 0, __('ui.Insufficient available treasury cash.'));
                $to = app(CashHandoverService::class)->recipient((int) ($data['to_user_id'] ?? 0), 'TREASURY', $old->branch_id);
                $this->stock->require($to->id !== $old->custodian_id, __('ui.Select a replacement cash recipient.'));
            }
            $entry = $this->entry(['branch_id' => $old->branch_id, 'custodian_id' => $old->custodian_id, 'direction' => $old->direction === 'IN' ? 'OUT' : 'IN', 'transaction_type' => 'REVERSAL', 'amount' => $old->amount, 'reversal_of' => $old->id, 'reference_type' => 'TreasuryEntry', 'reference_id' => (string) $old->id, 'external_reference' => 'REV-'.$old->number, 'description' => $note, 'occurred_at' => now()]);
            if ($old->direction === 'IN' && isset($to)) {
                $h = CashHandover::findOrFail($old->handover_id);
                $service = app(CashHandoverService::class);
                $service->event($h, 'REVERSED', $old->amount, null, $note, null, $entry->id);
                $service->create(Shift::findOrFail($h->source_shift_id), $old->custodian_id, $to->id, 'TREASURY', $old->amount, null, $note, $h->id);
            }
            $this->auditEntry('TREASURY_REVERSED', $entry, $before, $note);

            return $entry;
        });
    }

    /** @param array<string, mixed> $filters
     * @return Builder<TreasuryEntry>
     */
    public function query(array $filters): Builder
    {
        Gate::authorize('view-treasury');
        $actor = app(TenantContext::class)->user();
        abort_unless($actor->company->active, 403);
        $q = TreasuryEntry::query();
        if (! $actor->hasPermission('oversee-treasury')) {
            abort_if(! empty($filters['custodian_id']) && (int) $filters['custodian_id'] !== $actor->id, 403);
            $q->where('custodian_id', $actor->id);
        } elseif (! empty($filters['custodian_id'])) {
            User::where('company_id', $actor->company_id)->findOrFail((int) $filters['custodian_id']);
            $q->where('custodian_id', $filters['custodian_id']);
        }
        if (! empty($filters['branch_id'])) {
            Branch::findOrFail((int) $filters['branch_id']);
            $q->where('branch_id', $filters['branch_id']);
        }

        return $q;
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function dashboard(array $filters): array
    {
        return $this->tx->run(function () use ($filters) {
            $q = $this->query($filters);
            $all = (clone $q)->selectRaw("custodian_id, branch_id, SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END) AS balance")->groupBy(['custodian_id', 'branch_id'])->get();
            $balances = $all->map(fn ($entry) => ['custodian_id' => $entry->custodian_id, 'custodian' => User::where('company_id', $entry->company_id ?? app(TenantContext::class)->companyId())->findOrFail($entry->custodian_id)->name, 'branch_id' => $entry->branch_id, 'branch' => Branch::findOrFail($entry->branch_id)->name, 'amount' => D::add((string) $entry->getAttribute('balance'), '0')])->all();
            $from = CarbonImmutable::parse($filters['from'] ?? now()->toDateString())->startOfDay();
            $to = CarbonImmutable::parse($filters['to'] ?? now()->toDateString())->endOfDay();
            $this->stock->require($from <= $to && $from->diffInDays($to) <= 366, __('ui.Choose a date interval up to one year.'));
            $entries = (clone $q)->whereBetween('occurred_at', [$from, $to]);
            $totals = [];
            foreach (['CASHIER_RECEIPT', 'PURCHASE', 'EXPENSE', 'SALARY', 'BANK_DEPOSIT', 'REVERSAL'] as $type) {
                $totals[$type] = D::add((string) (clone $entries)->where('transaction_type', $type)->whereNotIn('id', TreasuryEntry::whereNotNull('reversal_of')->select('reversal_of'))->sum('amount'), '0');
            }

            $activity = clone $entries;
            if (! empty($filters['business_unit_id'])) {
                app(StaffVisibility::class)->assertFilters($filters);
                $activity->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', 'treasury_entries')->where('business_unit_id', $filters['business_unit_id'])->select('record_id'));
            }

            $activityRows = $activity->latest('id')->offset((max(1, (int) ($filters['activity_page'] ?? 1)) - 1) * 200)->limit(201)->get();

            return ['activity_has_more' => $activityRows->count() > 200, 'balances' => $balances, 'totals' => $totals, 'entries' => $activityRows->take(200)->map(fn ($entry) => $this->present($entry))->all(), 'from' => $from->toDateString(), 'to' => $to->toDateString()];
        });
    }

    /** @return array<string, mixed> */
    public function present(TreasuryEntry $entry): array
    {
        return [...$entry->toArray(), 'business_unit_id' => ($entry->business_unit_id && app(TenantContext::class)->user()->canAccessBusinessUnit($entry->business_unit_id)) ? $entry->business_unit_id : null, 'business_unit' => BusinessUnit::find($entry->business_unit_id)?->name, 'custodian' => User::where('company_id', $entry->company_id)->findOrFail($entry->custodian_id)->name, 'branch' => Branch::findOrFail($entry->branch_id)->name, 'employee' => $entry->employee_id ? User::where('company_id', $entry->company_id)->findOrFail($entry->employee_id)->name : null, 'reversed' => TreasuryEntry::where('reversal_of', $entry->id)->exists()];
    }
}
