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
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ShiftStockController extends Controller
{
    public function show(int $id, ShiftStockService $service): JsonResponse
    {
        return response()->json($service->report($id))->header('Cache-Control', 'private, no-store');
    }

    public function page(Request $request, int $id, ShiftStockService $service): View|Response
    {
        $report = $service->report($id);
        $shift = Shift::findOrFail($id);
        $actor = app(TenantContext::class)->user();
        $recipients = collect();
        if ($report['can_edit']) {
            $operational = app(OperationalShift::class);
            $recipients = User::where('company_id', $actor->company_id)->where('active', true)->orderBy('name')->get()
                ->filter(fn ($user) => $user->canAccessBranch($shift->branch_id) && $user->canAccessBusinessUnit($shift->business_unit_id))
                ->map(fn ($user) => [
                    'id' => $user->id, 'name' => $user->name,
                    'cashier' => $operational->type($user) === 'CASHIER' && $user->canAccessCounter($shift->stock_location_id),
                    'treasury' => $user->hasPermission('receive-treasury'),
                ])->filter(fn ($recipient) => $recipient['cashier'] || $recipient['treasury'])->values();
        }
        if ($actor->role->name === 'Cashier' && $shift->user_id === $actor->id
            && (($report['status'] === 'OPEN' && $report['can_edit']) || ($report['status'] === 'CLOSED' && $request->boolean('close')))) {
            return response()->view('shift-close-wizard', compact('report', 'recipients'))->header('Cache-Control', 'private, no-store');
        }

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
