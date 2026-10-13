<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int|null $user_id
 * @property int|null $stock_location_id
 * @property string $type
 * @property string $title
 * @property string $message
 * @property string $screen
 * @property list<int>|null $read_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class BusinessNotification extends TenantModel
{
    protected $table = 'notifications';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'read_by' => 'array'];
    }
}
