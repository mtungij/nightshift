<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $category_id
 * @property int $base_unit_id
 * @property string $name
 * @property string $sku
 * @property string|null $barcode
 * @property string|null $brand
 * @property numeric-string $purchase_cost
 * @property numeric-string $minimum_stock
 * @property string|null $image_path
 * @property string|null $image_url
 * @property string $visual_style
 * @property bool $active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, ProductUnit> $sellingUnits
 * @property-read Category $category
 * @property-read Unit $baseUnit
 */
class Product extends TenantModel
{
    protected $table = 'products';

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }

    /** @return HasMany<ProductUnit, $this> */
    public function sellingUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }
}
