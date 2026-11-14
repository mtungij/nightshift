<?php

namespace App\Http\Controllers;

use App\Contracts\ReportPdfRenderer;
use App\Domain\OperationalShift;
use App\Domain\TenantContext;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReportPdfService;
use App\Services\ShiftStockService;
use App\Support\UiText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class ShiftStockController extends Controller
{
    public function show(int $id, ShiftStockService $service): JsonResponse
    {
        return response()->json($service->report($id));
    }

    public function page(int $id, ShiftStockService $service): View
    {
        $report = $service->report($id);
        $recipients = $report['can_edit'] ? User::where('company_id', app(TenantContext::class)->companyId())->where('active', true)->get()->filter(fn ($u) => $u->canAccessBranch(Shift::findOrFail($id)->branch_id) && $u->canAccessBusinessUnit(Shift::findOrFail($id)->business_unit_id) && (app(OperationalShift::class)->type($u) === 'CASHIER' || $u->hasPermission('receive-treasury')))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'cashier' => app(OperationalShift::class)->type($u) === 'CASHIER', 'treasury' => $u->hasPermission('receive-treasury')])->values() : collect();

        return view('shift-stock', compact('report', 'recipients'));
    }

    public function pdf(int $id, ShiftStockService $service, ReportPdfService $pdf): Response
    {
        $report = $service->report($id);
        $shift = Shift::findOrFail($id);
        $metadata = $pdf->metadata('Stock Reconciliation', ['shift_id' => $id, 'business_unit_id' => $shift->business_unit_id]);
        $metadata['filters'] += [UiText::get('Branch') => $report['branch'], UiText::get('Counter') => $report['counter']];
        $html = view('shift-stock-sheet', ['report' => $report, 'pdfMetadata' => $metadata])->render();

        return $pdf->response(
            app(ReportPdfRenderer::class)->render($html, 'landscape'),
            'shift-'.$report['number'],
            [],
            true,
        );
    }

    public function sheet(int $id, ShiftStockService $service): View
    {
        return view('shift-stock-sheet', ['report' => $service->report($id)]);
    }
}
