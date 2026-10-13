<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $user_id
 * @property int $stock_location_id
 * @property string $number
 * @property string $type
 * @property string $status
 * @property CarbonInterface $opened_at
 * @property CarbonInterface|null $closed_at
 * @property numeric-string $opening_cash
 * @property numeric-string $expected_cash
 * @property numeric-string $actual_cash
 * @property numeric-string $cash_difference
 * @property string|null $closing_notes
 * @property string|null $override_reason
 * @property int|null $authorized_by
 * @property CarbonInterface|null $override_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Shift extends TenantModel
{
    protected $table = 'shifts';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'override_at' => 'datetime'];
    }
}
