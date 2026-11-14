<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string $name
 * @property string $phone
 * @property string|null $label
 * @property bool $all_branches
 * @property list<int> $branch_ids
 * @property bool $daily_report
 * @property bool $shift_reports
 * @property bool $critical_alerts
 * @property bool $all_business_units
 * @property list<int>|null $business_unit_ids
 * @property list<string>|null $enabled_types
 * @property string|null $language
 * @property bool $active
 */
class WhatsAppRecipient extends TenantModel
{
    protected $attributes = ['all_business_units' => true];

    protected $table = 'whatsapp_recipients';

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'business_unit_ids' => 'array', 'enabled_types' => 'array', 'all_business_units' => 'boolean', 'branch_ids' => 'array', 'all_branches' => 'boolean', 'daily_report' => 'boolean', 'shift_reports' => 'boolean', 'critical_alerts' => 'boolean', 'active' => 'boolean'];
    }
}
