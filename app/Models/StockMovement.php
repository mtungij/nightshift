<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $stock_location_id
 * @property int $product_id
 * @property int|null $shift_id
 * @property string $movement_type
 * @property numeric-string $quantity
 * @property numeric-string $balance_after
 * @property numeric-string $unit_cost
 * @property string $reference_type
 * @property int $reference_id
 * @property int|null $reversal_of
 * @property int $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class StockMovement extends ImmutableModel
{
    protected $table = 'stock_movements';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
