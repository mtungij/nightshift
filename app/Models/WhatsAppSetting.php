<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string|null $device_id
 * @property bool $provisioned
 * @property string $connection_status
 * @property string|null $jid
 * @property CarbonInterface|null $last_checked_at
 * @property CarbonInterface|null $last_health_queued_at
 * @property int $audit_cursor
 * @property array<string, mixed>|null $options
 */
class WhatsAppSetting extends TenantModel
{
    protected $table = 'whatsapp_settings';

    protected $attributes = ['provisioned' => false, 'connection_status' => 'NOT_CONFIGURED', 'audit_cursor' => 0];

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'provisioned' => 'boolean', 'last_checked_at' => 'datetime', 'last_health_queued_at' => 'datetime', 'options' => 'array'];
    }

    /** @return array<string, mixed> */
    public function preferences(): array
    {
        return [...config('whatsapp.defaults'), ...($this->options ?? [])];
    }
}
