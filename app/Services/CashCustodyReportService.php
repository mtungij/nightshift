<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\CashHandover;
use App\Models\GoodsReceipt;
use App\Models\Shift;
use App\Models\TreasuryEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

final class CashCustodyReportService
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function build(string $name, array $filters, int $offset): array
    {
        $selectedShift = app(ReportShiftService::class)->resolve($filters, $name);
        $from = isset($filters['period_start']) ? CarbonImmutable::parse($filters['period_start'])->timezone(config('app.timezone')) : CarbonImmutable::parse($filters['from'] ?? now()->toDateString())->startOfDay();
        $to = isset($filters['period_end']) ? CarbonImmutable::parse($filters['period_end'])->timezone(config('app.timezone'))->subMicrosecond() : CarbonImmutable::parse($filters['to'] ?? now()->toDateString())->endOfDay();
        app(StockService::class)->require($selectedShift !== null || ($from <= $to && $from->diffInDays($to) <= 366), __('ui.Choose a date interval up to one year.'));
        $actor = app(TenantContext::class)->user();
        abort_unless($actor->active && $actor->company->active && ! $actor->isPosTerminal(), 403);
        if ($name === 'Cashier Handover') {
            abort_unless($actor->hasPermission('review-cash-handovers') || $actor->hasPermission('view-treasury') || ($actor->hasPermission('issue-drinks') && $actor->hasPermission('close-shifts')), 403);
            $q = CashHandover::when(! $selectedShift, fn ($q) => $q->whereBetween('created_at', [$from, $to]));
            if (! $actor->hasPermission('review-cash-handovers') && ! $actor->hasPermission('oversee-treasury')) {
                $q->where(fn ($q) => $q->where('from_user_id', $actor->id)->orWhere('to_user_id', $actor->id));
            }
            if (! empty($filters['branch_id'])) {
                Branch::findOrFail((int) $filters['branch_id']);
                $q->where('branch_id', $filters['branch_id']);
            }
            foreach (['business_unit_id', 'stock_location_id'] as $field) {
                if (! empty($filters[$field])) {
                    $q->whereIn('source_shift_id', app(ReportShiftService::class)->visible($name)->select('id'));
                    if ($field === 'stock_location_id') {
                        $q->where('stock_location_id', $filters[$field]);
                    }
                }
            }
            if (! empty($filters['shift_id'])) {
                $q->where('source_shift_id', Shift::findOrFail((int) $filters['shift_id'])->id);
            }

            return ['headers' => ['Reference', 'Shift', 'Operating date', 'Branch', 'Counter', 'From', 'Destination', 'Expected cash', 'Cash Received', 'Cash Difference', 'Receipt status'], 'rows' => $q->orderBy('id')->offset($offset)->limit(101)->get()->map(function ($h) {
                $r = app(CashHandoverService::class)->present($h);

                return [$h->number, $r['source_number'], Shift::findOrFail($h->source_shift_id)->operating_date, $r['branch'], $r['counter'], $r['from_name'], $r['to_name'], $h->amount, $r['reported_amount'], $r['difference'], $r['status']];
            })->all()];
        }
        $q = app(TreasuryService::class)->query($filters);
        if ($name === 'Cash Custody Balance') {
            $rows = $q->selectRaw("custodian_id, branch_id, SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END) AS balance")->groupBy(['custodian_id', 'branch_id'])->orderBy('custodian_id')->orderBy('branch_id')->offset($offset)->limit(101)->get();

            return ['headers' => ['Custodian', 'Branch', 'Available Cash'], 'rows' => $rows->map(fn ($entry) => [User::where('company_id', $actor->company_id)->findOrFail($entry->custodian_id)->name, Branch::findOrFail($entry->branch_id)->name, D::add((string) $entry->getAttribute('balance'), '0')])->all()];
        }
        $type = ['Cash Received from Cashiers' => 'CASHIER_RECEIPT', 'Cash Used for Purchases' => 'PURCHASE', 'Expenses' => 'EXPENSE', 'Salaries and Wages' => 'SALARY', 'Bank Deposits' => 'BANK_DEPOSIT', 'Reversals' => 'REVERSAL'][$name] ?? null;
        if ($type) {
            $q->where('transaction_type', $type);
        }
        if ($name === 'Cash Received from Cashiers' && (! empty($filters['business_unit_id']) || ! empty($filters['stock_location_id']))) {
            $sources = app(ReportShiftService::class)->visible($name);
            if (! empty($filters['stock_location_id'])) {
                $sources->where('stock_location_id', $filters['stock_location_id']);
            }
            $q->whereIn('handover_id', CashHandover::whereIn('source_shift_id', $sources->select('id'))->select('id'));
        }
        if ($selectedShift) {
            $q->where('transaction_type', 'CASHIER_RECEIPT')->whereIn('handover_id', CashHandover::where('source_shift_id', $selectedShift->id)->select('id'));
        } else {
            $q->whereBetween('occurred_at', [$from, $to]);
        }

        return ['headers' => ['Reference', 'Date and time', 'Custodian', 'Branch', 'Transaction type', 'Direction', 'Amount', 'Category', 'Employee', 'Payment period', 'Bank', 'Posted purchase', 'Description', 'Status'], 'rows' => $q->orderBy('id')->offset($offset)->limit(101)->get()->map(fn ($entry) => [$entry->external_reference, $entry->occurred_at->toDateTimeString(), User::where('company_id', $actor->company_id)->findOrFail($entry->custodian_id)->name, Branch::findOrFail($entry->branch_id)->name, $entry->transaction_type, $entry->direction, $entry->amount, $entry->category, $entry->employee_id ? User::where('company_id', $actor->company_id)->findOrFail($entry->employee_id)->name : null, $entry->pay_period, $entry->bank, $entry->goods_receipt_id ? GoodsReceipt::findOrFail($entry->goods_receipt_id)->number : null, $entry->description, TreasuryEntry::where('reversal_of', $entry->id)->exists() ? 'Reversed' : 'Posted'])->all()];
    }
}
