<?php

namespace App\Services\WhatsApp;

use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppRecipient;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Outbox
{
    /** @param list<int> $branchIds
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(WhatsAppRecipient $recipient, string $type, string $period, array $branchIds, array $payload = [], ?string $message = null, ?string $referenceType = null, ?int $referenceId = null): WhatsAppDeliveryLog
    {
        abort_unless(app(RecipientService::class)->visible($recipient), 403);
        sort($branchIds);
        $settings = app(SettingsService::class)->current();
        $key = hash('sha256', json_encode([$type, $period, $recipient->id, $branchIds, $referenceType, $referenceId], JSON_THROW_ON_ERROR));
        $log = app(TransactionService::class)->run(fn () => WhatsAppDeliveryLog::firstOrCreate(['idempotency_key' => $key], [
            'branch_id' => count($branchIds) === 1 ? $branchIds[0] : null,
            'recipient_id' => $recipient->id, 'recipient_name' => $recipient->name, 'recipient_phone' => $recipient->phone,
            'message_type' => $type, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'scope_branch_ids' => $branchIds, 'payload' => $payload, 'message' => $message,
            'message_preview' => $message ? mb_substr($message, 0, 500) : null,
            'device_id' => $settings->device_id, 'status' => 'QUEUED', 'queued_at' => now(),
        ]));
        if ($log->wasRecentlyCreated) {
            $this->dispatch($log);
        }

        return $log;
    }

    public function dispatch(WhatsAppDeliveryLog $log): void
    {
        try {
            SendWhatsAppMessageJob::dispatch($log->company_id, $log->id)->onConnection(config('gowa.queue_connection'))->onQueue(config('gowa.queue'))->afterCommit();
            $log->update(['last_dispatched_at' => now()]);
        } catch (Throwable) {
            // The durable outbox is recovered by the scheduler. Business writes are untouched.
            Log::warning('WhatsApp queue unavailable; outbox retained.', ['company_id' => $log->company_id, 'delivery_id' => $log->id]);
        }
    }
}
