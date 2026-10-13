<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property bool $active
 * @property int $sort_order
 * @property string $icon
 * @property string $color
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Category extends TenantModel
{
    protected $table = 'categories';

    protected bool $branchScoped = false;

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean', 'sort_order' => 'integer'];
    }
}
