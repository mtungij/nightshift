<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $return_id
 * @property int $order_item_id
 * @property numeric-string $quantity
 * @property numeric-string $base_quantity
 * @property numeric-string $value
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ReturnItem extends ImmutableModel
{
    protected $table = 'return_items';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
