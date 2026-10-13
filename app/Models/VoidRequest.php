<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $order_id
 * @property string $number
 * @property string $reason
 * @property string $status
 * @property string $previous_status
 * @property int $requested_by
 * @property int|null $approved_by
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class VoidRequest extends TenantModel
{
    protected $table = 'void_requests';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
