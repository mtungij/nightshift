<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class WhatsAppScheduleController extends Controller
{
    public function save(Request $request, ScheduleService $service, ?int $id = null): JsonResponse
    {
        Gate::authorize('whatsapp.manage_settings');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'report_type' => ['required', Rule::in(['DAILY', 'WEEKLY', 'MONTHLY', 'TREASURY_SUMMARY'])],
            'enabled' => ['required', 'boolean'], 'send_time' => ['required', 'date_format:H:i'], 'timezone' => ['required', 'timezone'], 'cutoff_time' => ['required', 'date_format:H:i'],
            'weekday' => ['required', 'integer', 'between:1,7'], 'month_day' => ['required', 'integer', 'between:1,31'], 'month_end' => ['required', 'boolean'], 'grace_minutes' => ['required', 'integer', 'between:1,120'],
            'format' => ['required', Rule::in(['TEXT', 'TEXT_PDF'])], 'language' => ['required', Rule::in(['en', 'sw'])], 'scope' => ['required', Rule::in(['COMPANY', 'BRANCH', 'UNIT'])],
            'branch_id' => ['nullable', Rule::requiredIf(in_array($request->input('scope'), ['BRANCH', 'UNIT'], true)), Rule::prohibitedIf($request->input('scope') === 'COMPANY'), 'integer', 'min:1'], 'business_unit_id' => ['nullable', Rule::requiredIf($request->input('scope') === 'UNIT'), Rule::prohibitedIf($request->input('scope') !== 'UNIT'), 'integer', 'min:1'], 'stock_location_id' => ['nullable', Rule::prohibitedIf($request->input('scope') === 'COMPANY'), 'integer', 'min:1'],
            'per_branch' => ['required', 'boolean'], 'unit_breakdown' => ['required', 'boolean'], 'recipient_ids' => ['required', 'array', 'min:1'], 'recipient_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);
        $schedule = $service->save($data, $id);

        return response()->json(['id' => $schedule->id, 'message' => __('ui.Report schedule saved.')]);
    }
}
