<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $supplier_id
 * @property int $stock_location_id
 * @property string $number
 * @property string|null $supplier_invoice
 * @property string $received_on
 * @property numeric-string $total_cost
 * @property string $status
 * @property int $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, GoodsReceiptItem> $items
 */
class GoodsReceipt extends TenantModel
{
    protected $table = 'goods_receipts';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }

    /** @return HasMany<GoodsReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
