<?php

namespace App\Jobs\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppRecipient;
use App\Services\Gowa;
use App\Services\TransactionService;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\GowaException;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\ReportBuilder;
use App\Services\WhatsApp\SettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

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
            $requestedBy = $log->payload['requested_by'] ?? null;
            if ($requestedBy) {
                $actor = User::where('company_id', $this->companyId)->where('active', true)->whereKey($requestedBy)->first();
                if (! $actor || ! $actor->hasPermission($log->message_type === 'TEST' ? 'whatsapp.send_test' : 'whatsapp.send_manual_report') || array_filter($log->scope_branch_ids, fn ($id) => ! $actor->canAccessBranch($id)) !== []) {
                    $log->update(['status' => 'SKIPPED', 'error_message' => 'Report authorization changed.']);

                    return null;
                }
            }
            $enabledFlag = match ($log->message_type) {
                'DAILY' => 'daily_report', 'SHIFT' => 'shift_report', 'TEST', 'MANUAL' => null, default => strtolower($log->message_type)
            };
            if ($enabledFlag && ! ($settings->preferences()[$enabledFlag] ?? false)) {
                $log->update(['status' => 'SKIPPED', 'error_message' => 'This report or alert was disabled.']);

                return null;
            }
            $preference = match ($log->message_type) {
                'DAILY', 'MANUAL' => 'daily_report', 'SHIFT' => 'shift_reports', 'TEST' => null, default => 'critical_alerts'
            };
            if (! $settings->preferences()['enabled'] || ! $settings->device_id || $settings->device_id !== $log->device_id || ! $recipient || ! $recipient->active || ($preference && ! $recipient->$preference) || array_diff($log->scope_branch_ids, $branches) !== [] || $log->scope_branch_ids === [] || $recipient->phone !== $log->recipient_phone) {
                $log->update(['status' => 'SKIPPED', 'error_message' => 'Integration, recipient, or branch scope changed.']);

                return null;
            }
            if ($log->attempt_count >= $this->tries) {
                $log->update(['status' => 'FAILED', 'failed_at' => now()]);

                return null;
            }
            $log->update(['status' => 'SENDING', 'sending_at' => now(), 'attempt_count' => $log->attempt_count + 1]);

            return $log;
        });
        if (! $log) {
            return;
        }
        try {
            $settings = app(SettingsService::class)->check(app(SettingsService::class)->current());
            if ($settings->connection_status !== 'CONNECTED') {
                throw new GowaException('The company WhatsApp device is disconnected.', retryable: true);
            }
            if (! $log->message) {
                $message = $log->message_type === 'SHIFT' ? app(ReportBuilder::class)->shift($log) : app(ReportBuilder::class)->daily($log);
                $log->update(['message' => $message, 'message_preview' => mb_substr($message, 0, 500)]);
            }
            $response = app(Gowa::class)->sendText($settings, $log->recipient_phone, (string) $log->message);
            $id = $response['results']['message_id'] ?? $response['results']['id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new GowaException('WhatsApp accepted the request without a message ID. Verify delivery before resending.', ambiguous: true);
            }
            $log->update(['status' => 'SENT', 'provider_message_id' => mb_substr($id, 0, 255), 'provider_status' => $response['provider_status'], 'http_status' => $response['http_status'], 'sent_at' => now(), 'error_message' => null, 'next_attempt_at' => null]);
        } catch (GowaException $error) {
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

    public function failed(?Throwable $error): void
    {
        WhatsAppDeliveryLog::withoutGlobalScopes()->where('company_id', $this->companyId)->whereKey($this->deliveryId)->whereNotIn('status', ['SENT', 'SKIPPED'])->update(['status' => 'FAILED', 'failed_at' => now(), 'error_message' => 'WhatsApp worker stopped before delivery was confirmed. Verify before resending.']);
    }
}
