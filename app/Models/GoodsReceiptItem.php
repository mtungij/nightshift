<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $goods_receipt_id
 * @property int $product_id
 * @property int $product_unit_id
 * @property numeric-string $quantity
 * @property numeric-string $base_quantity
 * @property numeric-string $unit_cost
 * @property numeric-string $total_cost
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class GoodsReceiptItem extends ImmutableModel
{
    protected $table = 'goods_receipt_items';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
