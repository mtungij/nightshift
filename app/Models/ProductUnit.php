<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $product_id
 * @property int $unit_id
 * @property numeric-string $conversion_quantity
 * @property numeric-string $selling_price
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Unit $unit
 * @property-read Product $product
 */
class ProductUnit extends TenantModel
{
    protected $table = 'product_units';

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
