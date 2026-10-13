<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $stock_location_id
 * @property int $waiter_id
 * @property int|null $service_table_id
 * @property int $shift_id
 * @property string $invoice_number
 * @property numeric-string $subtotal
 * @property numeric-string $discount
 * @property numeric-string $return_total
 * @property numeric-string $total
 * @property numeric-string $amount_paid
 * @property numeric-string $balance
 * @property numeric-string $credit_amount
 * @property string $status
 * @property int $created_by
 * @property int|null $issued_by
 * @property CarbonInterface|null $issued_at
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int|null $issued_shift_id
 * @property-read Collection<int, OrderItem> $items
 * @property-read User $waiter
 * @property-read Collection<int, PaymentAllocation> $payments
 */
class Order extends TenantModel
{
    protected $table = 'orders';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'issued_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
