<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $stock_location_id
 * @property string $number
 * @property string $reason
 * @property string $notes
 * @property string $status
 * @property int $requested_by
 * @property int|null $approved_by
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, StockAdjustmentItem> $items
 */
class StockAdjustment extends TenantModel
{
    protected $table = 'stock_adjustments';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }

    /** @return HasMany<StockAdjustmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }
}
