<?php

namespace App\Services\WhatsApp;

use App\Domain\TenantContext;
use App\Jobs\WhatsApp\CheckGowaDeviceJob;
use App\Jobs\WhatsApp\SendOperationalAlertJob;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppRecipient;
use App\Models\WhatsAppReportSchedule;
use App\Models\WhatsAppSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Scheduler
{
    public function tick(): void
    {
        WhatsAppSetting::withoutGlobalScopes()->orderBy('id')->chunkById(100, function ($settings) {
            foreach ($settings as $setting) {
                if (! $setting->preferences()['enabled']) {
                    continue;
                }
                try {
                    app(CompanyContext::class)->run($setting->company_id, fn () => $this->company($setting));
                } catch (Throwable) {
                    Log::warning('WhatsApp scheduled work deferred.', ['company_id' => $setting->company_id]);
                }
            }
        });
    }

    private function company(WhatsAppSetting $settings): void
    {
        $options = $settings->preferences();
        $timezone = Company::findOrFail($settings->company_id)->timezone ?: 'Africa/Dar_es_Salaam';
        $local = CarbonImmutable::now($timezone);
        foreach (WhatsAppReportSchedule::where('enabled', true)->get() as $schedule) {
            $period = app(ScheduleService::class)->due($schedule);
            if ($period) {
                app(ScheduleService::class)->enqueue($schedule, $period);
            }
        }
        if (! WhatsAppReportSchedule::where('report_type', 'DAILY')->exists() && $options['daily_report'] && $local->format('H:i') >= $options['daily_time']) {
            foreach (WhatsAppRecipient::where('active', true)->get()->filter(fn ($r) => app(RecipientService::class)->enabled($r, 'DAILY')) as $recipient) {
                $due = $local->setTimeFromTimeString($options['daily_time']);
                if ($local->diffInMinutes($due, true) <= 60) {
                    $this->daily($recipient, $local->toDateString());
                }
            }
        }
        if ($settings->device_id && (! $settings->last_health_queued_at || $settings->last_health_queued_at->lte(now()->subMinutes(15)))) {
            CheckGowaDeviceJob::dispatch($settings->company_id)->onConnection(config('gowa.queue_connection'))->onQueue(config('gowa.queue'));
            $settings->update(['last_health_queued_at' => now()]);
        }
        SendOperationalAlertJob::dispatch($settings->company_id)->onConnection(config('gowa.queue_connection'))->onQueue(config('gowa.queue'));
        // Recover a lost queue push, but never automatically repeat ambiguous sends.
        WhatsAppDeliveryLog::where('status', 'SENDING')->where('sending_at', '<=', now()->subMinutes(10))->update(['status' => 'FAILED', 'failed_at' => now(), 'error_message' => 'Delivery was interrupted. Verify on the device before resending.']);
        foreach (WhatsAppDeliveryLog::whereIn('status', ['QUEUED', 'RETRYING'])->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->where(fn ($q) => $q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', now()->subMinutes(10)))->limit(100)->get() as $log) {
            app(Outbox::class)->dispatch($log);
        }
    }

    /** @return list<WhatsAppDeliveryLog> */
    public function daily(WhatsAppRecipient $recipient, string $date, ?int $requestedBy = null): array
    {
        $options = app(SettingsService::class)->current()->preferences();
        $branches = app(RecipientService::class)->branches($recipient);
        $scopes = $options['report_mode'] === 'branch' ? array_map(fn ($id) => [$id], $branches) : [$branches];
        $logs = [];
        foreach ($scopes as $ids) {
            if ($ids === []) {
                continue;
            }
            $timezone = app(TenantContext::class)->user()->company->timezone ?: 'Africa/Dar_es_Salaam';
            $end = CarbonImmutable::parse($date, $timezone)->setTimeFromTimeString($options['operating_cutoff']);
            // Manual reports select the named operating date; scheduled compatibility reports select the last completed period.
            if ($requestedBy) {
                $end = $end->addDay();
            } elseif ($end > CarbonImmutable::now($timezone)) {
                $end = $end->subDay();
            }
            $units = BusinessUnit::whereIn('id', app(RecipientService::class)->units($recipient))->whereIn('branch_id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $logs[] = app(Outbox::class)->enqueue($recipient, $requestedBy ? 'MANUAL' : 'DAILY', $date, $ids, ['date' => $date, 'scope_business_unit_ids' => $units, 'period_start' => $end->subDay()->toIso8601String(), 'period_end' => $end->toIso8601String(), 'timezone' => $timezone, 'language' => $recipient->language ?? $options['language'], 'requested_by' => $requestedBy]);
        }

        return $logs;
    }
}
