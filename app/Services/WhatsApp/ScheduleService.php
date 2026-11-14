<?php

namespace App\Services\WhatsApp;

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppRecipient;
use App\Models\WhatsAppReportSchedule;
use App\Services\AuditService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final class ScheduleService
{
    /** @param array<string,mixed> $data */
    public function save(array $data, ?int $id = null): WhatsAppReportSchedule
    {
        Gate::authorize('whatsapp.manage_settings');

        return app(TransactionService::class)->run(function () use ($data, $id) {
            $schedule = $id ? WhatsAppReportSchedule::findOrFail($id) : new WhatsAppReportSchedule;
            $actor = app(TenantContext::class)->user();
            if ($data['scope'] === 'COMPANY') {
                abort_if(! empty($data['branch_id']) || ! empty($data['business_unit_id']) || ! empty($data['stock_location_id']), 422, 'Whole-company schedules cannot include a narrower location filter.');
                abort_unless($actor->hasPermission('all-branches') && $actor->hasPermission('all-business-units'), 403);
                $data['branch_id'] = null;
                $data['business_unit_id'] = null;
            } else {
                Branch::findOrFail($data['branch_id'] ?? 0);
                if ($data['scope'] === 'UNIT') {
                    $unit = BusinessUnit::whereKey($data['business_unit_id'] ?? 0)->firstOrFail();
                    abort_unless($unit->branch_id === (int) $data['branch_id'], 422);
                } else {
                    $data['business_unit_id'] = null;
                    abort_unless($actor->hasPermission('all-business-units'), 403);
                }
            }
            if (! empty($data['stock_location_id'])) {
                $location = StockLocation::whereKey($data['stock_location_id'])->firstOrFail();
                abort_unless($location->type === 'COUNTER' && (empty($data['branch_id']) || $location->branch_id === (int) $data['branch_id']) && (empty($data['business_unit_id']) || $location->business_unit_id === (int) $data['business_unit_id']), 422);
            }
            foreach ($data['recipient_ids'] as $recipientId) {
                app(RecipientService::class)->find($recipientId);
            }
            $before = $schedule->exists ? $schedule->only(['enabled', 'send_time', 'timezone', 'cutoff_time', 'scope', 'branch_id', 'business_unit_id', 'format']) : [];
            $schedule->fill([...$data, 'created_by' => $schedule->created_by ?? $actor->id])->save();
            app(AuditService::class)->record('WHATSAPP_SCHEDULE_SAVED', 'WhatsApp', 'WhatsAppReportSchedule', $schedule->id, $before, $schedule->only(['enabled', 'send_time', 'timezone', 'cutoff_time', 'scope', 'branch_id', 'business_unit_id', 'format']));

            return $schedule;
        });
    }

    /** @return array<string,mixed>|null */
    public function due(WhatsAppReportSchedule $schedule, ?CarbonImmutable $instant = null): ?array
    {
        if (! $schedule->enabled) {
            return null;
        }
        $local = ($instant ?? CarbonImmutable::now())->timezone($schedule->timezone);
        // Yesterday is needed for a short grace interval crossing local midnight.
        foreach ([$local->startOfDay(), $local->subDay()->startOfDay()] as $day) {
            if ($schedule->report_type === 'WEEKLY' && $day->dayOfWeekIso !== $schedule->weekday) {
                continue;
            }
            if ($schedule->report_type === 'MONTHLY' && $day->day !== ($schedule->month_end ? $day->daysInMonth : min($schedule->month_day, $day->daysInMonth))) {
                continue;
            }
            $at = $day->setTimeFromTimeString($schedule->send_time);
            if ($at > $local || $local->diffInMinutes($at, true) > $schedule->grace_minutes) {
                continue;
            }
            $end = $at->setTimeFromTimeString($schedule->cutoff_time);
            if ($end > $at) {
                $end = $end->subDay();
            }
            if ($schedule->report_type === 'MONTHLY') {
                $end = $at->startOfMonth()->setTimeFromTimeString($schedule->cutoff_time);
                if ($end > $at) {
                    $end = $end->subMonthNoOverflow();
                }
            }
            $start = match ($schedule->report_type) {
                'WEEKLY' => $end->subWeek(),'MONTHLY' => $end->subMonthNoOverflow(),default => $end->subDay()
            };

            return ['period_start' => $start->toIso8601String(), 'period_end' => $end->toIso8601String(), 'operating_date' => $start->toDateString(), 'scheduled_at' => $at->toIso8601String(), 'timezone' => $schedule->timezone];
        }

        return null;
    }

    /** @param array<string,mixed> $period */
    public function enqueue(WhatsAppReportSchedule $schedule, array $period): void
    {
        $actor = User::where('company_id', $schedule->company_id)->where('active', true)->find($schedule->created_by);
        if (! $actor || ! $actor->hasPermission('whatsapp.manage_settings')) {
            return;
        }
        foreach (WhatsAppRecipient::whereIn('id', $schedule->recipient_ids)->where('active', true)->get() as $recipient) {
            $service = app(RecipientService::class);
            if (! $service->enabled($recipient, $schedule->report_type)) {
                continue;
            }
            $branches = $service->branches($recipient);
            if ($schedule->branch_id) {
                $branches = array_values(array_intersect($branches, [$schedule->branch_id]));
            }
            $units = $service->units($recipient);
            if ($schedule->business_unit_id) {
                $units = array_values(array_intersect($units, [$schedule->business_unit_id]));
            }
            $units = BusinessUnit::whereIn('id', $units)->whereIn('branch_id', $branches)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($branches === [] || $units === [] || array_filter($branches, fn ($id) => ! $actor->canAccessBranch($id)) !== [] || array_filter($units, fn ($id) => ! $actor->canAccessBusinessUnit($id)) !== []) {
                continue;
            }
            app(TransactionService::class)->run(function () use ($schedule, $recipient, $period, $branches, $units, $actor) {
                if (WhatsAppDeliveryLog::where('schedule_id', $schedule->id)->where('recipient_id', $recipient->id)->where('payload->period_start', $period['period_start'])->where('payload->period_end', $period['period_end'])->exists()) {
                    return;
                }
                foreach ($schedule->per_branch ? array_map(fn ($id) => [$id], $branches) : [$branches] as $ids) {
                    $scopeUnits = BusinessUnit::whereIn('id', $units)->whereIn('branch_id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
                    if ($scopeUnits === []) {
                        continue;
                    }
                    app(Outbox::class)->enqueue($recipient, $schedule->report_type, $period['period_start'].'/'.$period['period_end'], $ids, [...$period, 'schedule_id' => $schedule->id, 'scope_business_unit_ids' => $scopeUnits, 'stock_location_id' => $schedule->stock_location_id, 'format' => $schedule->format, 'unit_breakdown' => $schedule->unit_breakdown, 'language' => $recipient->language ?? $schedule->language, 'requested_by' => $actor->id]);
                }
            });
        }
    }
}
