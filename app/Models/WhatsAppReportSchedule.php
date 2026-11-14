<?php

namespace App\Models;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $report_type
 * @property bool $enabled
 * @property string $send_time
 * @property string $timezone
 * @property string $cutoff_time
 * @property int $weekday
 * @property int $month_day
 * @property bool $month_end
 * @property int $grace_minutes
 * @property string $format
 * @property string $language
 * @property string $scope
 * @property int|null $branch_id
 * @property int|null $business_unit_id
 * @property int|null $stock_location_id
 * @property bool $per_branch
 * @property bool $unit_breakdown
 * @property list<int> $recipient_ids
 * @property int $created_by
 */
class WhatsAppReportSchedule extends TenantModel
{
    protected $table = 'whatsapp_report_schedules';

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'enabled' => 'boolean', 'month_end' => 'boolean', 'per_branch' => 'boolean', 'unit_breakdown' => 'boolean', 'recipient_ids' => 'array'];
    }
}
