<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $source_location_id
 * @property int $destination_location_id
 * @property string $number
 * @property string $status
 * @property string|null $notes
 * @property int $created_by
 * @property int|null $received_by
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $received_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, StockTransferItem> $items
 */
class StockTransfer extends TenantModel
{
    protected $table = 'stock_transfers';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }

    /** @return HasMany<StockTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
