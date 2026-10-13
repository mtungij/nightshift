<?php

namespace App\Http\Controllers;

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppRecipient;
use App\Services\WhatsApp\GowaException;
use App\Services\WhatsApp\Outbox;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\Scheduler;
use App\Services\WhatsApp\SettingsService;
use App\Support\TanzanianPhone;
use App\Support\UiText;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WhatsAppController extends Controller
{
    public function index(): View
    {
        Gate::authorize('whatsapp.view_settings');
        $settings = app(SettingsService::class)->current();
        $recipients = WhatsAppRecipient::orderBy('name')->get()->filter(fn ($r) => app(RecipientService::class)->visible($r));
        $allowed = Branch::pluck('id')->all();
        $logs = Gate::allows('whatsapp.view_logs') ? WhatsAppDeliveryLog::latest('id')->limit(200)->get()->filter(fn ($l) => app(TenantContext::class)->user()->hasPermission('all-branches') || ($l->scope_branch_ids !== [] && array_diff($l->scope_branch_ids, $allowed) === []))->take(100) : collect();

        return view('whatsapp.settings', ['settings' => $settings, 'options' => $settings->preferences(), 'recipients' => $recipients, 'logs' => $logs, 'branches' => Branch::orderBy('name')->get()]);
    }

    public function save(Request $request): JsonResponse
    {
        Gate::authorize('whatsapp.manage_settings');
        $rules = [];
        foreach (['enabled', 'daily_report', 'shift_report', 'waiter_outstanding', 'cash_shortage', 'low_stock', 'void', 'breakage', 'complimentary', 'large_sale'] as $field) {
            $rules[$field] = ['required', 'boolean'];
        }
        foreach (['waiter_threshold', 'cash_threshold', 'breakage_threshold', 'complimentary_threshold', 'large_sale_threshold'] as $field) {
            $rules[$field] = ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/D'];
        }
        $rules += ['daily_time' => ['required', 'date_format:H:i'], 'language' => ['required', Rule::in(['en', 'sw'])], 'report_mode' => ['required', Rule::in(['consolidated', 'branch'])], 'cooldown_minutes' => ['required', 'integer', 'min:1', 'max:10080'], 'low_stock_drop_percent' => ['required', 'integer', 'min:1', 'max:100']];
        app(SettingsService::class)->save($request->validate($rules));

        return response()->json(['message' => UiText::get('WhatsApp settings saved.')]);
    }

    public function connection(Request $request, string $action): JsonResponse
    {
        Gate::authorize($action === 'status' ? 'whatsapp.view_settings' : 'whatsapp.manage_settings');
        // Ignore/reject client device IDs: every action uses the saved company slot.
        $request->validate(['device_id' => ['prohibited']]);
        try {
            if ($action === 'status') {
                $settings = app(SettingsService::class)->check(app(SettingsService::class)->current());

                return response()->json(['status' => $settings->connection_status, 'jid' => $settings->jid, 'last_checked_at' => $settings->last_checked_at?->toIso8601String()]);
            }
            abort_unless(in_array($action, ['qr', 'code', 'reconnect'], true), 404);
            $phone = null;
            if ($action === 'code') {
                $request->validate(['phone' => ['required', 'string', 'max:40']]);
                $phone = TanzanianPhone::normalize((string) $request->input('phone'));
            }

            return response()->json(app(SettingsService::class)->link($action, $phone));
        } catch (GowaException $error) {
            return response()->json(['message' => UiText::get($error->getMessage())], 422);
        }
    }

    public function recipient(Request $request, ?int $id = null): JsonResponse
    {
        Gate::authorize('whatsapp.manage_recipients');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:40'], 'label' => ['nullable', 'string', 'max:80'], 'all_branches' => ['required', 'boolean'], 'branch_ids' => ['present', 'array'], 'branch_ids.*' => ['integer', 'distinct', 'min:1'], 'daily_report' => ['required', 'boolean'], 'shift_reports' => ['required', 'boolean'], 'critical_alerts' => ['required', 'boolean'], 'active' => ['required', 'boolean']]);
        $recipient = app(RecipientService::class)->save($data, $id);

        return response()->json(['id' => $recipient->id]);
    }

    public function send(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['test', 'report'], true), 404);
        Gate::authorize($type === 'test' ? 'whatsapp.send_test' : 'whatsapp.send_manual_report');
        $data = $request->validate(['recipient_id' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'uuid'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $recipient = app(RecipientService::class)->find($data['recipient_id']);
        $settings = app(SettingsService::class)->current();
        if (! $settings->preferences()['enabled'] || ! $settings->device_id || $settings->connection_status !== 'CONNECTED' || ! $recipient->active) {
            return response()->json(['message' => UiText::get('Enable WhatsApp and connect the company device first.')], 422);
        }
        $company = app(TenantContext::class)->user()->company;
        if ($type === 'report') {
            $date = $data['date'] ?? CarbonImmutable::now($company->timezone ?: 'Africa/Dar_es_Salaam')->toDateString();
            if ($date > CarbonImmutable::now($company->timezone ?: 'Africa/Dar_es_Salaam')->toDateString()) {
                throw ValidationException::withMessages(['date' => 'Choose today or an earlier report date.']);
            }
            $logs = app(Scheduler::class)->daily($recipient, $date, app(TenantContext::class)->user()->id);
        } else {
            $message = $settings->preferences()['language'] === 'sw' ? '✅ Nightshift WhatsApp imeunganishwa vizuri.' : '✅ Nightshift WhatsApp is connected.';
            $log = app(Outbox::class)->enqueue($recipient, 'TEST', $data['idempotency_key'], app(RecipientService::class)->branches($recipient), ['requested_by' => app(TenantContext::class)->user()->id], $message."\n\n".$company->name);
            $logs = [$log];
        }

        return response()->json(['message' => UiText::get('WhatsApp message queued.'), 'ids' => array_map(fn ($l) => $l->id, $logs)], 202);
    }
}
