<?php

namespace App\Services;

use App\Domain\BusinessUnitContext;
use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\ShiftStockSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Shared reporting composition. Financial values come only from existing report engines. */
final class OperationalPeriodReportService
{
    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function build(array $filters): array
    {
        Gate::authorize('view-reports');

        return DB::transaction(function () use ($filters) {
            app(BusinessUnitContext::class)->select(null);
            $branches = array_values($filters['branch_ids']);
            $units = array_values($filters['business_unit_ids']);
            abort_unless($branches !== [] && Branch::whereIn('id', $branches)->count() === count($branches), 403);
            abort_unless($units !== [] && BusinessUnit::whereIn('id', $units)->whereIn('branch_id', $branches)->count() === count($units), 403);
            $start = CarbonImmutable::parse($filters['period_start'])->timezone(config('app.timezone'));
            $end = CarbonImmutable::parse($filters['period_end'])->timezone(config('app.timezone'));
            abort_unless($start < $end && $start->diffInDays($end) <= 367, 422);
            $shifts = app(ReportShiftService::class)->visible('Daily Sales')->whereIn('branch_id', $branches)->where('opened_at', '>=', $start)->where('opened_at', '<', $end)->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', 'shifts')->whereIn('business_unit_id', $units)->select('record_id'))->when(! empty($filters['stock_location_id']), fn ($q) => $q->where('stock_location_id', $filters['stock_location_id']))->orderBy('id')->get();
            $metrics = ['units' => '0.000000', 'sales' => '0.00', 'paid' => '0.00', 'outstanding' => '0.00', 'orders' => 0, 'paid_orders' => 0, 'unpaid_orders' => 0, 'expected_cash' => '0.00', 'actual_cash' => '0.00', 'cash_difference' => '0.00', 'waiter_issued' => '0.00', 'waiter_settled' => '0.00', 'waiter_outstanding' => '0.00', 'closed_shifts' => 0, 'open_shifts' => 0, 'cash_received' => '0.00'];
            $sourceReports = [];
            $detailCount = 0;
            $methods = [];
            $unitTotals = [];
            $branchTotals = [];
            $contexts = [];
            $closingCounters = [];
            foreach ($shifts as $shift) {
                $context = app(ReportShiftService::class)->context($shift);
                $contexts[] = $context;
                $metrics[$shift->status === 'OPEN' ? 'open_shifts' : 'closed_shifts']++;
                $scope = ['shift_id' => $shift->id];
                if ($shift->type === 'WAITER') {
                    foreach ($this->rows('Waiter Settlement', $scope) as $row) {
                        foreach (['waiter_issued' => 1, 'waiter_settled' => 2, 'waiter_outstanding' => 3] as $key => $column) {
                            $metrics[$key] = D::add($metrics[$key], (string) $row[$column]);
                        }
                    }

                    continue;
                }
                $salesRows = $this->rows('Daily Sales', $scope);
                if ($filters['include_details'] ?? false) {
                    $detailCount += count($salesRows);
                    app(ReportPdfService::class)->checkLimit($detailCount);
                    $sourceReports[] = ['title' => $context['number'].' · '.$context['employee'], 'headers' => ['Invoice', 'Waiter', 'Table', 'Total', 'Paid', 'Balance', 'Status'], 'rows' => $salesRows];
                }
                foreach ($salesRows as $row) {
                    foreach (['sales' => 3, 'paid' => 4, 'outstanding' => 5] as $key => $column) {
                        $metrics[$key] = D::add($metrics[$key], (string) $row[$column]);
                    }
                    $metrics['orders']++;
                    if ($row[6] === 'PAID') {
                        $metrics['paid_orders']++;
                    } elseif (in_array($row[6], ['ISSUED', 'PARTIALLY_PAID'], true)) {
                        $metrics['unpaid_orders']++;
                    }
                    $unitTotals[$context['branch'].' / '.$context['business_unit']] = D::add($unitTotals[$context['branch'].' / '.$context['business_unit']] ?? '0', (string) $row[3]);
                    $branchTotals[$context['branch']] = D::add($branchTotals[$context['branch']] ?? '0', (string) $row[3]);
                }
                foreach ($this->rows('Sales by Product', $scope) as $row) {
                    $metrics['units'] = D::add($metrics['units'], (string) $row[1], 6);
                }
                foreach ($this->rows('Payment Method Report', $scope) as $row) {
                    $methods[$row[0]] = D::add($methods[$row[0]] ?? '0', (string) $row[1]);
                }
                foreach ($this->rows('Cashier Shift', $scope) as $row) {
                    $metrics['expected_cash'] = D::add($metrics['expected_cash'], (string) $row[4]);
                    if ($shift->status === 'CLOSED') {
                        $metrics['actual_cash'] = D::add($metrics['actual_cash'], (string) $row[5]);
                        $metrics['cash_difference'] = D::add($metrics['cash_difference'], (string) $row[6]);
                    }
                }
                foreach ((Gate::allows('view-treasury') && app(ReportShiftService::class)->visible('Cash Received from Cashiers')->whereKey($shift->id)->exists()) ? $this->rows('Cash Received from Cashiers', $scope) : [] as $row) {
                    $metrics['cash_received'] = D::add($metrics['cash_received'], (string) $row[6]);
                }
                // A shared counter's reconciliation is not additive across simultaneous cashiers.
                if ($shift->status === 'CLOSED' && (! isset($closingCounters[$shift->stock_location_id]) || $closingCounters[$shift->stock_location_id]['closed_at'] < $shift->closed_at)) {
                    $closingCounters[$shift->stock_location_id] = ['id' => $shift->id, 'closed_at' => $shift->closed_at];
                }
            }
            $stock = [];
            foreach ($closingCounters as $closed) {
                $id = $closed['id'];
                $snapshot = ShiftStockSnapshot::where('shift_id', $id)->where('kind', 'CLOSING')->first();
                if ($snapshot) {
                    $context = app(ReportShiftService::class)->context($shifts->firstWhere('id', $id));
                    $stock[] = ['business_unit' => $context['business_unit'], 'show_location' => $context['show_location'], 'shift_id' => $id, 'counter' => $snapshot->data['counter'] ?? '', 'totals' => $snapshot->data['totals'] ?? []];
                }
            }
            $treasury = [];
            // Unassigned expenses and custody balances may be shown only for complete branches.
            foreach ($branches as $branch) {
                $all = DB::table('business_units')->where('company_id', app(TenantContext::class)->companyId())->where('branch_id', $branch)->pluck('id')->all();
                if (! Gate::allows('view-treasury') || array_diff($all, $units) !== [] || ! empty($filters['stock_location_id'])) {
                    continue;
                }
                foreach (['Cash Used for Purchases', 'Expenses', 'Salaries and Wages', 'Bank Deposits', 'Cash Custody Balance'] as $report) {
                    $scope = ['branch_id' => $branch, 'period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String()];
                    $sum = '0.00';
                    foreach ($this->rows($report, $scope) as $row) {
                        $sum = D::add($sum, (string) $row[$report === 'Cash Custody Balance' ? 2 : 6]);
                    }
                    $treasury[$report] = D::add($treasury[$report] ?? '0', $sum);
                }
            }
            app(BusinessUnitContext::class)->select(null);
            $carryover = app(ReportShiftService::class)->visible('Daily Sales')->where('status', 'OPEN')->where('opened_at', '<', $start)->whereIn('branch_id', $branches)->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', 'shifts')->whereIn('business_unit_id', $units)->select('record_id'))->when(! empty($filters['stock_location_id']), fn ($q) => $q->where('stock_location_id', $filters['stock_location_id']))->get()->map(fn ($s) => app(ReportShiftService::class)->context($s))->all();

            return ['can_view_treasury' => Gate::allows('view-treasury'), 'source_reports' => $sourceReports, 'metrics' => $metrics, 'payment_methods' => $methods, 'branches' => $branchTotals, 'business_units' => $unitTotals, 'shifts' => $contexts, 'carryover_open_shifts' => $carryover, 'stock_reconciliations' => $stock, 'treasury' => $treasury, 'period_start' => $filters['period_start'], 'period_end' => $filters['period_end'], 'generated_at' => now()->toIso8601String(), 'currency' => app(TenantContext::class)->user()->company->currency];
        });
    }

    /** @param array<string,mixed> $filters
     * @return list<list<mixed>>
     */
    public function rows(string $name, array $filters): array
    {
        $rows = [];
        for ($page = 1; ; $page++) {
            $result = app(ReportService::class)->generate($name, [...$filters, 'page' => $page]);
            array_push($rows, ...$result['rows']);
            if (! $result['has_more']) {
                return $rows;
            }
        }
    }
}
