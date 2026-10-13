<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int|null $branch_id
 * @property int|null $recipient_id
 * @property string $recipient_name
 * @property string $recipient_phone
 * @property string $message_type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $idempotency_key
 * @property string|null $message_preview
 * @property string|null $message
 * @property array<string, mixed> $payload
 * @property list<int> $scope_branch_ids
 * @property string|null $device_id
 * @property string $status
 * @property int $attempt_count
 * @property string|null $provider_message_id
 * @property string|null $provider_status
 * @property int|null $http_status
 * @property string|null $error_message
 * @property CarbonInterface|null $queued_at
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $next_attempt_at
 * @property CarbonInterface|null $last_dispatched_at
 * @property CarbonInterface|null $sending_at
 */
class WhatsAppDeliveryLog extends TenantModel
{
    protected $table = 'whatsapp_delivery_logs';

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'payload' => 'array', 'scope_branch_ids' => 'array', 'queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'next_attempt_at' => 'datetime', 'last_dispatched_at' => 'datetime', 'sending_at' => 'datetime'];
    }
}
