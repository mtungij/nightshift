<?php

namespace App\Http\Controllers;

use App\Domain\OperationalShift;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Http\Requests\CashCustodyRequest;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\CashHandover;
use App\Models\GoodsReceipt;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\CashHandoverService;
use App\Services\ReportFilterService;
use App\Services\ReportPdfService;
use App\Services\ReportService;
use App\Services\TransactionService;
use App\Services\TreasuryService;
use App\Support\UiText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CashCustodyController extends Controller
{
    private function access(bool $treasury = false): void
    {
        $user = app(TenantContext::class)->user();
        abort_unless($user?->active && $user->company->active && ! $user->isPosTerminal(), 403);
        if ($treasury) {
            Gate::authorize('view-treasury');
        } else {
            abort_unless(($user->hasPermission('issue-drinks') && $user->hasPermission('close-shifts')) || $user->hasPermission('view-treasury') || $user->hasPermission('receive-treasury') || $user->hasPermission('review-cash-handovers'), 403);
        }
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $filters = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1'], 'custodian_id' => ['nullable', 'integer', 'min:1'], 'business_unit_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        if (! empty($filters['from']) && ! empty($filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => __('ui.Choose a date interval up to one year.')]);
        }

        return $filters;
    }

    /** @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function handovers(array $filters = [], bool $pendingOnly = false): array
    {
        $this->access();
        $user = app(TenantContext::class)->user();
        app(StaffVisibility::class)->assertFilters($filters);
        $q = CashHandover::query();
        if (! empty($filters['business_unit_id'])) {
            $q->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', $user->company_id)->where('record_type', 'cash_handovers')->where('business_unit_id', $filters['business_unit_id'])->select('record_id'));
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $q->whereDate('created_at', $operator, $filters[$key]);
            }
        }
        if (! $user->hasPermission('review-cash-handovers') && ! $user->hasPermission('oversee-treasury')) {
            $q->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id));
        }
        if (! empty($filters['branch_id'])) {
            Branch::findOrFail((int) $filters['branch_id']);
            $q->where('branch_id', $filters['branch_id']);
        }

        if ($pendingOnly) {
            $q->where(function ($q) {
                $q->whereHas('latestEvent', fn ($event) => $event->whereIn('status', ['PENDING', 'DISPUTED']))
                    ->orWhere(function ($q) {
                        $q->whereHas('latestEvent', fn ($event) => $event->whereIn('status', ['RECEIVED', 'APPROVED']))
                            ->where(fn ($q) => $q->where('destination', 'NEXT_CASHIER')->orWhereIn('source_shift_id', Shift::where('status', 'OPEN')->select('id')));
                    });
            })->orderBy('id');
        } else {
            $q->latest('id');
        }

        return array_values($q->when(! $pendingOnly, fn ($q) => $q->offset(((int) ($filters['page'] ?? 1) - 1) * 25))->limit($pendingOnly ? 200 : 26)->get()->map(fn ($h) => app(CashHandoverService::class)->present($h))->all());
    }

    /** @return list<array<string, mixed>> */
    private function recipients(): array
    {
        return array_values(User::where('company_id', app(TenantContext::class)->companyId())->where('active', true)->get()
            ->filter(fn ($user) => ($user->hasPermission('receive-treasury') || app(OperationalShift::class)->type($user) === 'CASHIER') && Branch::get()->contains(fn ($branch) => $user->canAccessBranch($branch->id)))
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'treasury' => $user->hasPermission('receive-treasury'), 'cashier' => app(OperationalShift::class)->type($user) === 'CASHIER'])->values()->all());
    }

    public function handoverIndex(Request $request): Response|JsonResponse
    {
        $filters = $this->filters($request);
        if ($request->boolean('pdf')) {
            $pdf = app(ReportPdfService::class);
            $data = $pdf->all(function (int $page) use ($filters) {
                $rows = $this->handovers([...$filters, 'page' => $page]);

                return ['headers' => ['Shift', 'Counter', 'Business Unit', 'From', 'Destination', 'Handover amount', 'Cash Received', 'Cash Difference', 'Receipt status'], 'rows' => array_map(fn ($h) => [$h['source_number'], $h['counter'], $h['business_unit'], $h['from_name'], $h['to_name'] ?? UiText::get('No cash to hand over'), $h['amount'], $h['received_amount'], $h['difference'], $h['status']], array_slice($rows, 0, 25)), 'has_more' => count($rows) > 25];
            });

            return $pdf->download('Cash Handovers', $filters, [$data]);
        }
        $handovers = $this->handovers($filters);
        $data = ['handovers' => array_slice($handovers, 0, 25), 'has_more' => count($handovers) > 25, 'page' => (int) ($filters['page'] ?? 1), 'branches' => Branch::get(), 'units' => BusinessUnit::get(), 'filters' => $filters, 'recipients' => $this->recipients()];

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store') : response()->view('cash-handovers', $data)->header('Cache-Control', 'private, no-store');
    }

    public function treasury(Request $request, TreasuryService $service): Response|JsonResponse
    {
        $this->access(true);
        $filters = $this->filters($request);
        app(StaffVisibility::class)->assertFilters($filters);
        if ($request->boolean('pdf')) {
            $pdf = app(ReportPdfService::class);
            [$sections,$activity,$dashboard] = DB::transaction(function () use ($pdf, $service, $filters) {
                $dashboard = $service->dashboard($filters);
                $sections = [['title' => 'Available Cash', 'headers' => ['Custodian', 'Branch', 'Available Cash'], 'rows' => array_map(fn ($b) => [$b['custodian'], $b['branch'], $b['amount']], $dashboard['balances'])],
                    ['title' => 'Treasury', 'headers' => ['Transaction type', 'Amount'], 'note' => 'Unit filters apply to source activity. Cash balances and totals retain the full custody scope.', 'rows' => array_map(fn ($type, $amount) => [$type, $amount], array_keys($dashboard['totals']), array_values($dashboard['totals']))]];
                $activity = $pdf->all(function (int $page) use ($service, $filters) {
                    $d = $service->dashboard([...$filters, 'activity_page' => $page]);

                    return ['title' => 'Treasury Ledger', 'headers' => ['Date and time', 'Reference', 'Custodian', 'Branch', 'Business Unit', 'Transaction type', 'Direction', 'Amount', 'Description', 'Status'], 'rows' => array_map(fn ($e) => [$e['occurred_at'], $e['external_reference'], $e['custodian'], $e['branch'], $e['business_unit'], $e['transaction_type'], $e['direction'], $e['amount'], $e['description'], $e['reversed'] ? 'Reversed' : 'Posted'], $d['entries']), 'has_more' => $d['activity_has_more']];
                });

                return [$sections, $activity, $dashboard];
            });

            return $pdf->download('Treasury', [...$filters, 'from' => $dashboard['from'], 'to' => $dashboard['to']], [...$sections, $activity]);
        }
        $data = ['units' => BusinessUnit::get(), ...$service->dashboard($filters), 'handovers' => $this->handovers($filters, true), 'branches' => Branch::where('active', true)->get(['id', 'name']),
            'employees' => User::where('company_id', app(TenantContext::class)->companyId())->whereHas('branches', fn ($q) => $q->whereIn('branches.id', Branch::select('id')))->whereHas('role', fn ($q) => $q->where('name', '!=', 'POS Terminal'))->get(['id', 'name']),
            'recipients' => $this->recipients(),
            'purchases' => GoodsReceipt::latest('id')->limit(200)->get(['id', 'number', 'branch_id', 'total_cost']),
        ];
        $response = $request->expectsJson() ? response()->json($data) : response()->view($request->boolean('print') ? 'treasury-print' : 'treasury', $data);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function report(Request $request): Response
    {
        $this->access(true);
        $data = $request->validate(['name' => ['required', Rule::in(config('treasury.reports'))], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $filters = app(ReportFilterService::class)->validate($request, $data['name']);
        if ($data['name'] === 'Cash Custody Balance') {
            unset($filters['from'],$filters['to']);
        } else {
            $filters += ['from' => now()->toDateString(), 'to' => now()->toDateString()];
        }
        if ($request->boolean('pdf')) {
            $pdf = app(ReportPdfService::class);
            $report = $pdf->all(fn (int $page) => app(ReportService::class)->generate($data['name'], [...$filters, 'page' => $page]));

            return $pdf->download($data['name'], $filters, [$report]);
        }
        $report = app(ReportService::class)->generate($data['name'], [...$filters, 'page' => $data['page'] ?? 1]);

        return response()->view('treasury-report', ['report' => $report, 'name' => $data['name'], 'filters' => $filters, 'branches' => Branch::get(['id', 'name']), 'shiftUnits' => BusinessUnit::get(['id', 'name', 'branch_id', 'advanced_locations_enabled']), 'shiftCounters' => StockLocation::where('type', 'COUNTER')->get(['id', 'name', 'branch_id', 'business_unit_id'])])->header('Cache-Control', 'private, no-store');
    }

    public function command(CashCustodyRequest $request, string $action, ?int $id = null): JsonResponse
    {
        $this->access(in_array($action, ['spend', 'reverse'], true));
        $data = $request->validated();
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);
        $result = app(TransactionService::class)->idempotent($key, 'cash-custody-'.$action, ['id' => $id, ...$data], function () use ($action, $id, $data) {
            $h = app(CashHandoverService::class);
            $result = match ($action) {
                'stage' => $h->stage((int) $data['shift_id'], $data),
                'receive' => $h->receive((int) $id, $data['amount'], $data['note'] ?? null),
                'approve' => $h->approve((int) $id, $data['note']),
                'cancel' => $h->cancel((int) $id, $data),
                'spend' => app(TreasuryService::class)->spend($data),
                'reverse' => app(TreasuryService::class)->reverse((int) $id, $data),
                default => abort(404),
            };

            return ['id' => $result->id];
        });

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
