<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $order_id
 * @property int $shift_id
 * @property string $number
 * @property string $reason
 * @property numeric-string $total
 * @property int $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class OrderReturn extends TenantModel
{
    protected $table = 'returns';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
