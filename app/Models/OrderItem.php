<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $order_id
 * @property int $product_id
 * @property int $product_unit_id
 * @property string $product_name
 * @property string $unit_name
 * @property numeric-string $quantity
 * @property numeric-string $base_quantity
 * @property numeric-string $conversion_quantity
 * @property numeric-string $returned_quantity
 * @property numeric-string $unit_price
 * @property numeric-string $purchase_cost
 * @property numeric-string $total
 * @property numeric-string $discount_share
 * @property numeric-string $returned_value
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class OrderItem extends TenantModel
{
    protected $table = 'order_items';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
