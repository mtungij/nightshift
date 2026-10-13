<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $stock_location_id
 * @property int $product_id
 * @property numeric-string $quantity
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Stock extends TenantModel
{
    protected $table = 'stocks';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
