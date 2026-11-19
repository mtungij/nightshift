<?php

namespace App\Models;

use App\Domain\OrderCollectionMode;
use App\Domain\OrderSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $stock_location_id
 * @property int|null $waiter_id
 * @property int|null $service_table_id
 * @property int $shift_id
 * @property string|null $order_note
 * @property string $invoice_number
 * @property numeric-string $subtotal
 * @property numeric-string $discount
 * @property numeric-string $return_total
 * @property numeric-string $total
 * @property numeric-string $amount_paid
 * @property numeric-string $balance
 * @property numeric-string $credit_amount
 * @property OrderCollectionMode $collection_mode
 * @property OrderSource $order_source
 * @property string $status
 * @property int $created_by
 * @property int|null $issued_by
 * @property CarbonInterface|null $issued_at
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property int|null $issued_shift_id
 * @property-read Collection<int, OrderItem> $items
 * @property-read User|null $waiter
 * @property-read Collection<int, PaymentAllocation> $payments
 */
class Order extends TenantModel
{
    protected $table = 'orders';

    protected $attributes = ['collection_mode' => OrderCollectionMode::WaiterAccount->value, 'order_source' => OrderSource::Waiter->value];

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'collection_mode' => OrderCollectionMode::class, 'order_source' => OrderSource::class, 'issued_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (Order $order) {
            if ($order->exists && $order->isDirty('order_source')) {
                throw new \LogicException('An order source cannot be changed.');
            }
            if ($order->isCounterSale() !== ($order->waiter_id === null)) {
                throw new \LogicException('Order source and waiter must agree.');
            }
        });
    }

    public function isCounterSale(): bool
    {
        return $this->order_source === OrderSource::CashierDirect;
    }

    public function usesWaiterAccount(): bool
    {
        return ! $this->isCounterSale() && $this->collection_mode === OrderCollectionMode::WaiterAccount;
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
