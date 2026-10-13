<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int|null $order_id
 * @property int $shift_id
 * @property string $number
 * @property string $method
 * @property numeric-string $amount
 * @property bool $is_credit
 * @property int $received_by
 * @property string|null $external_reference
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Payment extends ImmutableModel
{
    protected $table = 'payments';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'is_credit' => 'boolean'];
    }
}
