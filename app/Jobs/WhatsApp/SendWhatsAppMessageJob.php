<?php

namespace App\Jobs\WhatsApp;

use App\Domain\TenantContext;
use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppRecipient;
use App\Models\WhatsAppReportSchedule;
use App\Services\Gowa;
use App\Services\TransactionService;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\GowaException;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\ReportBuilder;
use App\Services\WhatsApp\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public int $companyId, public int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(CompanyContext $context): void
    {
        try {
            $context->run($this->companyId, fn () => $this->deliver());
        } catch (Throwable) {
            // No provider/SQL exception text is serialized to failed_jobs or logs.
            WhatsAppDeliveryLog::withoutGlobalScopes()->where('company_id', $this->companyId)->whereKey($this->deliveryId)->whereNotIn('status', ['SENT', 'SKIPPED'])->update(['status' => 'FAILED', 'error_message' => 'WhatsApp processing could not be completed.', 'failed_at' => now()]);
        }
    }

    private function deliver(): void
    {
        $log = app(TransactionService::class)->run(function () {
            $log = WhatsAppDeliveryLog::lockForUpdate()->findOrFail($this->deliveryId);
            if (! in_array($log->status, ['QUEUED', 'RETRYING'], true) || ($log->next_attempt_at && $log->next_attempt_at->isFuture())) {
                return null;
            }
            $settings = app(SettingsService::class)->current();
            $recipient = WhatsAppRecipient::find($log->recipient_id);
            $branches = $recipient ? app(RecipientService::class)->branches($recipient) : [];
            $actor = null;
            $requestedBy = $log->payload['requested_by'] ?? null;
            if ($requestedBy) {
                $actor = User::where('company_id', $this->companyId)->where('active', true)->whereKey($requestedBy)->first();
                if (! $actor || $actor->isPosTerminal() || ! $actor->hasPermission('whatsapp.view_settings') || ($log->message_type !== 'TEST' && ! $actor->hasPermission('view-reports')) || ($log->message_type === 'TREASURY_SUMMARY' && ! $actor->hasPermission('view-treasury')) || ($recipient && ! app(TenantContext::class)->run($actor, fn () => app(RecipientService::class)->visible($recipient))) || ! $actor->hasPermission($log->message_type === 'TEST' ? 'whatsapp.send_test' : ($log->schedule_id ? 'whatsapp.manage_settings' : 'whatsapp.send_manual_report')) || (array_filter($log->scope_branch_ids, fn ($id) => ! $actor->canAccessBranch($id)) !== [] || array_filter($log->scope_business_unit_ids ?? [], fn ($id) => ! $actor->canAccessBusinessUnit($id)) !== [])) {
                    $log->update(['status' => 'SKIPPED', 'error_message' => 'Report authorization changed.']);

                    return null;
                }
            }
            $unitIds = $recipient ? app(RecipientService::class)->units($recipient) : [];
            if ($recipient && array_diff($log->scope_business_unit_ids ?? [], $unitIds) !== []) {
                $log->update(['status' => 'SKIPPED', 'error_message' => 'Report authorization changed.']);

                return null;
            }
            if ($log->schedule_id) {
                $schedule = WhatsAppReportSchedule::find($log->schedule_id);
                if (! $schedule || ! $schedule->enabled || $schedule->report_type !== $log->message_type || $schedule->format !== ($log->payload['format'] ?? 'TEXT') || ! in_array($log->recipient_id, $schedule->recipient_ids, true) || ($schedule->branch_id && array_diff($log->scope_branch_ids, [$schedule->branch_id]) !== []) || ($schedule->business_unit_id && array_diff($log->scope_business_unit_ids ?? [], [$schedule->business_unit_id]) !== []) || $schedule->stock_location_id !== ($log->payload['stock_location_id'] ?? null)) {
                    $log->update(['status' => 'SKIPPED', 'error_message' => 'Report schedule or scope changed.']);

                    return null;
                }
                if (! empty($log->payload['scheduled_at']) && now()->greaterThan(CarbonImmutable::parse($log->payload['scheduled_at'])->addMinutes($schedule->grace_minutes))) {
                    $log->update(['status' => 'SKIPPED', 'error_message' => 'Scheduled report expired beyond its delivery window.']);

                    return null;
                }
            }
            $enabledFlag = $log->schedule_id ? null : match ($log->message_type) {
                'DAILY' => 'daily_report', 'SHIFT' => 'shift_report', 'TEST', 'MANUAL' => null, default => strtolower($log->message_type)
            };
            if ($enabledFlag && ! ($settings->preferences()[$enabledFlag] ?? false)) {
                $log->update(['status' => 'SKIPPED', 'error_message' => 'This report or alert was disabled.']);

                return null;
            }
            if (! $settings->preferences()['enabled'] || ! $settings->device_id || $settings->device_id !== $log->device_id || ! $recipient || ! $recipient->active || ($log->scope_business_unit_ids === null && ! $recipient->all_business_units && $log->message_type !== 'TEST') || ! app(RecipientService::class)->enabled($recipient, $log->message_type) || array_diff($log->scope_branch_ids, $branches) !== [] || $log->scope_branch_ids === [] || $recipient->phone !== $log->recipient_phone) {
                $log->update(['status' => 'SKIPPED', 'error_message' => 'Integration, recipient, or branch scope changed.']);

                return null;
            }
            if ($log->attempt_count >= $this->tries) {
                $log->update(['status' => 'FAILED', 'failed_at' => now()]);

                return null;
            }
            $payload = $log->payload;
            if ($actor) {
                $authorization = $this->authorization($actor);
                if ((isset($payload['report_authorization']) && $payload['report_authorization'] !== $authorization) || (! isset($payload['report_authorization']) && $log->report_data && $log->message)) {
                    $log->update(['status' => 'SKIPPED', 'error_message' => 'Report authorization changed.']);

                    return null;
                }
                $payload['report_authorization'] = $authorization;
            }
            $log->update(['payload' => $payload, 'status' => 'SENDING', 'sending_at' => now(), 'attempt_count' => $log->attempt_count + 1]);

            return $log;
        });
        if (! $log) {
            return;
        }
        try {
            $settings = app(SettingsService::class)->check(app(SettingsService::class)->current(), throwOnProviderError: true);
            if ($settings->connection_status !== 'CONNECTED') {
                throw new GowaException('The company WhatsApp device is disconnected.', retryable: true);
            }
            if (! $log->message) {
                $message = $this->asRequester($log, fn () => $log->message_type === 'SHIFT' ? app(ReportBuilder::class)->shift($log) : app(ReportBuilder::class)->daily($log));
                $log->update(['message' => $message, 'message_preview' => mb_substr($message, 0, 500)]);
            }
            $attachment = ($log->payload['format'] ?? 'TEXT') === 'TEXT_PDF' && ! $log->attachment_message_id ? $this->asRequester($log, fn () => app(ReportBuilder::class)->attachment($log)) : null;
            if (! $log->provider_message_id) {
                $response = app(Gowa::class)->sendText($settings, $log->recipient_phone, (string) $log->message);
                $id = $response['results']['message_id'] ?? $response['results']['id'] ?? null;
                if (! is_string($id) || $id === '') {
                    throw new GowaException('WhatsApp accepted the request without a message ID. Verify delivery before resending.', ambiguous: true);
                }
                $log->update(['provider_message_id' => mb_substr($id, 0, 255), 'provider_status' => $response['provider_status'], 'http_status' => $response['http_status'], 'text_sent_at' => now()]);
            }
            if (($log->payload['format'] ?? 'TEXT') === 'TEXT_PDF' && ! $log->attachment_message_id) {
                $contents = $attachment ?? $this->asRequester($log, fn () => app(ReportBuilder::class)->attachment($log));
                $log->update(['attachment_status' => 'SENDING']);
                $file = app(Gowa::class)->sendFile($settings, $log->recipient_phone, $contents, 'nightshift-'.$log->message_type.'-'.$log->id.'.pdf', 'Nightshift');
                $fileId = $file['results']['message_id'] ?? $file['results']['id'] ?? null;
                if (! is_string($fileId) || $fileId === '') {
                    throw new GowaException('WhatsApp accepted the file without a message ID. Verify before resending.', ambiguous: true);
                }
                $log->update(['attachment_status' => 'SENT', 'attachment_message_id' => mb_substr($fileId, 0, 255), 'attachment_sent_at' => now()]);
            }
            $log->update(['status' => 'SENT', 'sent_at' => now(), 'error_message' => null, 'next_attempt_at' => null]);
        } catch (GowaException $error) {
            if ($log->attachment_status === 'SENDING') {
                $log->update(['attachment_status' => $error->ambiguous ? 'AMBIGUOUS' : 'PENDING']);
            }
            $retry = $error->retryable && ! $error->ambiguous && $log->attempt_count < $this->tries;
            $delay = $this->backoff()[$log->attempt_count - 1];
            $log->update(['status' => $retry ? 'RETRYING' : 'FAILED', 'http_status' => $error->httpStatus, 'error_message' => $error->getMessage(), 'failed_at' => $retry ? null : now(), 'next_attempt_at' => $retry ? now()->addSeconds($delay) : null]);
            if ($retry && $this->job) {
                $this->release($delay);
            }
        } catch (Throwable) {
            $log->update(['status' => 'FAILED', 'error_message' => 'WhatsApp processing failed. No automatic resend.', 'failed_at' => now()]);
        }
    }

    private function authorization(User $user): string
    {
        $assignments = [];
        foreach (['branch_user' => 'branch_id', 'business_unit_user' => 'business_unit_id', 'counter_user' => 'stock_location_id'] as $table => $column) {
            $assignments[$table] = DB::table($table)->where('company_id', $user->company_id)->where('user_id', $user->id)->orderBy($column)->pluck($column)->all();
        }

        return hash('sha256', json_encode([$user->role_id, $user->role?->name, $user->role?->permissions()->orderBy('permissions.name')->pluck('permissions.name')->all(), $user->business_units_configured, $user->counters_configured, $assignments], JSON_THROW_ON_ERROR));
    }

    /** @param callable():string $callback */
    private function asRequester(WhatsAppDeliveryLog $log, callable $callback): string
    {
        if (empty($log->payload['requested_by'])) {
            return $callback();
        }
        $user = User::where('company_id', $this->companyId)->where('active', true)->whereKey($log->payload['requested_by'])->firstOrFail();

        return app(TenantContext::class)->run($user, $callback);
    }

    public function failed(?Throwable $error): void
    {
        WhatsAppDeliveryLog::withoutGlobalScopes()->where('company_id', $this->companyId)->whereKey($this->deliveryId)->whereNotIn('status', ['SENT', 'SKIPPED'])->update(['status' => 'FAILED', 'failed_at' => now(), 'error_message' => 'WhatsApp worker stopped before delivery was confirmed. Verify before resending.']);
    }
}
