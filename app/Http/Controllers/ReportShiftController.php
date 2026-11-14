<?php

namespace App\Http\Controllers;

use App\Domain\BusinessUnitContext;
use App\Models\User;
use App\Services\ReportShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReportShiftController extends Controller
{
    public function index(Request $request, ReportShiftService $service): JsonResponse
    {
        $data = $request->validate(['report' => ['required', 'string', 'max:100'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'business_unit_id' => ['nullable', 'integer', 'min:1'], 'stock_location_id' => ['nullable', 'integer', 'min:1'], 'shift_id' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'], 'before' => ['nullable', 'integer', 'min:1']]);
        $supported = config('report_pdf.filters.'.$data['report'], config('report_pdf.default_filters'));
        abort_unless($service->supports($data['report']), 422, __('ui.This filter is not available for this report.'));
        app(BusinessUnitContext::class)->select(! empty($data['business_unit_id']) ? (int) $data['business_unit_id'] : null);
        $query = $service->visible($data['report']);
        foreach (['branch_id', 'stock_location_id'] as $key) {
            if (! empty($data[$key])) {
                $query->where($key, $data[$key]);
            }
        }
        if (! empty($data['business_unit_id'])) {
            $query->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', $request->user()->company_id)->where('record_type', 'shifts')->where('business_unit_id', $data['business_unit_id'])->select('record_id'));
        }
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('number', 'like', '%'.$data['search'].'%')->orWhereIn('user_id', User::where('company_id', $request->user()->company_id)->where('name', 'like', '%'.$data['search'].'%')->select('id')));
        }
        if (! empty($data['before'])) {
            $query->where('id', '<', $data['before']);
        }
        $rows = $query->orderByDesc('id')->limit(26)->get();
        $more = $rows->count() > 25;
        $items = $rows->take(25)->map(fn ($shift) => $service->context($shift))->values()->all();
        $selected = ! empty($data['shift_id']) ? $service->context($service->resolve($data, $data['report'])) : null;

        return response()->json(['shifts' => $items, 'selected' => $selected, 'next_before' => $more ? $rows->get(24)->id : null])->header('Cache-Control', 'private, no-store');
    }
}
