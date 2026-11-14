<?php

namespace App\Http\Controllers;

use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\ReportPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditTrailController extends Controller
{
    public function index(Request $request, AuditTrailService $service): Response|StreamedResponse
    {
        Gate::authorize('view-audit');
        $actor = $request->user();
        abort_unless($actor->active && $actor->company->active && ! $actor->isPosTerminal(), 403);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'min:1'], 'business_unit_id' => ['nullable', 'integer', 'min:1'], 'stock_location_id' => ['nullable', 'integer', 'min:1'],
            'shift_id' => ['nullable', 'integer', 'min:1'], 'user_id' => ['nullable', 'integer', 'min:1'], 'module' => ['nullable', 'string', 'max:100'], 'action' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        if (empty($filters['shift_id']) && ! empty($filters['from']) && ! empty($filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => __('ui.Choose a date interval up to one year.')]);
        }
        $query = $service->query($filters);
        if ($request->boolean('pdf')) {
            $pdf = app(ReportPdfService::class);
            $rows = [];
            $query->chunkByIdDesc(100, function ($batch) use ($service, $pdf, &$rows) {
                foreach ($service->present($batch) as $row) {
                    $rows[] = [$row['time'], $row['user'], $row['action'], $row['reference'], $row['summary']];
                }$pdf->checkLimit(count($rows));
            });

            return $pdf->download('Audit trail', $filters, [['headers' => ['Time', 'User', 'Action', 'Record', 'Summary'], 'rows' => $rows]]);
        }
        if ($request->boolean('export')) {
            return response()->streamDownload(function () use ($query, $service) {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    throw new \RuntimeException('Cannot open CSV output.');
                }
                fputcsv($out, ['Time', 'User', 'Action', 'Technical action', 'Module', 'Record', 'Summary', 'Previous value', 'New value'], ',', '"', '');
                $query->reorder('id')->chunkById(100, function ($rows) use ($service, $out) {
                    foreach ($service->present($rows) as $row) {
                        $values = [$row['time'], $row['user'], $row['action'], $row['code'], $row['module'], $row['reference'], $row['summary'], json_encode($row['previous']), json_encode($row['next'])];
                        fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, $values), ',', '"', '');
                    }
                });
                fclose($out);
            }, 'nightshift-audit.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store']);
        }
        $page = $query->simplePaginate(25)->withQueryString();

        return response()->view('audit-trail', [
            'rows' => $service->present($page->getCollection()), 'page' => $page, 'filters' => $filters,
            'branches' => Branch::get(['id', 'name']), 'units' => BusinessUnit::get(['id', 'name', 'branch_id', 'advanced_locations_enabled']),
            'shiftCounters' => StockLocation::where('type', 'COUNTER')->get(['id', 'name', 'branch_id', 'business_unit_id']),
            'users' => app(StaffVisibility::class)->unitUsers(User::where('company_id', app(TenantContext::class)->companyId()))->get(['id', 'name']),
            'modules' => $service->query([])->reorder()->distinct()->pluck('module'), 'actions' => $service->query([])->reorder()->distinct()->pluck('action'),
        ])->header('Cache-Control', 'private, no-store');
    }
}
