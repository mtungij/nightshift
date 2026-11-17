<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $stock_location_id
 * @property int $order_id
 * @property int $cashier_shift_id
 * @property int|null $collected_by
 * @property string $status
 * @property numeric-string $amount
 * @property-read Order $order
 * @property-read Branch $branch
 * @property-read BusinessUnit $businessUnit
 * @property-read StockLocation $counter
 */
class TicketCollection extends TenantModel
{
    protected function casts(): array
    {
        return [...parent::casts(), 'collected_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<BusinessUnit, $this> */
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }
}
