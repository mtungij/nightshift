<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $state_key
 * @property bool $triggered
 * @property numeric-string $last_value
 * @property CarbonInterface|null $last_alert_at
 * @property int $generation
 */
class WhatsAppAlertState extends TenantModel
{
    protected $table = 'whatsapp_alert_states';

    protected $attributes = ['triggered' => false, 'last_value' => '0', 'generation' => 0];

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'triggered' => 'boolean', 'last_value' => 'decimal:6', 'last_alert_at' => 'datetime'];
    }
}
