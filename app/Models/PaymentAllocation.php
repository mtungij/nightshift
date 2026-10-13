<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $payment_id
 * @property int $order_id
 * @property numeric-string $amount
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PaymentAllocation extends ImmutableModel
{
    protected $table = 'payment_allocations';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
