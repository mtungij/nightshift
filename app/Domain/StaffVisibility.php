<?php

namespace App\Domain;

use App\Models\BusinessNotification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Read scopes are separate from accounting queries, which must retain complete history. */
final class StaffVisibility
{
    public function __construct(private TenantContext $context) {}

    public function broad(?User $user = null): bool
    {
        return ($user ?? $this->context->user())->hasPermission('view-staff-operations');
    }

    public function settlementOnly(?User $user = null): bool
    {
        $user ??= $this->context->user();

        return ! $this->broad($user) && ($user->hasPermission('issue-drinks') || $user->hasPermission('receive-payments'));
    }

    /** @return list<int> */
    public function counters(?User $user = null): array
    {
        $user ??= $this->context->user();
        $query = Shift::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->where('type', 'CASHIER');
        if (! $user->hasPermission('all-branches')) {
            $query->whereIn('branch_id', $user->branches()->pluck('branches.id'));
        }
        $open = (clone $query)->where('status', 'OPEN')->pluck('stock_location_id')->map(fn ($id) => (int) $id)->values()->all();
        if ($open !== []) {
            return array_values($open);
        }
        $latest = $query->latest('id')->value('stock_location_id');

        return $latest ? [(int) $latest] : [];
    }

    /** @template T of \Illuminate\Database\Eloquent\Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function atCounter(Builder $query, string $column = 'stock_location_id'): Builder
    {
        return $this->settlementOnly() ? $query->whereIn($column, $this->counters()) : $query;
    }

    public function assertLocation(int $id): void
    {
        StockLocation::findOrFail($id);
        if ($this->settlementOnly()) {
            $counters = $this->counters();
            // Before the first shift, permitted counter metadata is available
            // for opening that shift; no other staff operations are returned.
            abort_if($counters !== [] && ! in_array($id, $counters, true), 403, 'Counter is outside your assigned shift.');
        }
    }

    public function assertOperationalLocation(int $id): void
    {
        $this->assertLocation($id);
        if ($this->settlementOnly()) {
            abort_unless(in_array($id, $this->counters(), true), 403, 'Open your cashier shift at this counter first.');
        }
    }

    /** @param array<string, mixed> $filters */
    public function assertFilters(array $filters): void
    {
        $actor = $this->context->user();
        if (! empty($filters['stock_location_id'])) {
            $this->assertLocation((int) $filters['stock_location_id']);
        }
        if (! $this->broad() && ! empty($filters['waiter_id'])) {
            if ($this->settlementOnly()) {
                abort_unless($this->waiters(User::query())->whereKey($filters['waiter_id'])->exists(), 403);
            } else {
                abort_unless((int) $filters['waiter_id'] === $actor->id, 403);
            }
        }
        if (! $this->broad() && $this->settlementOnly() && ! empty($filters['issued_by'])) {
            abort_unless((int) $filters['issued_by'] === $actor->id, 403);
        }
    }

    /** @param Builder<Order> $query
     * @return Builder<Order>
     */
    public function orders(Builder $query): Builder
    {
        if ($this->broad()) {
            return $query;
        }
        $actor = $this->context->user();
        if (! $this->settlementOnly()) {
            return $query->where('waiter_id', $actor->id);
        }

        return $this->atCounter($query)->whereHas('waiter.branches', fn ($q) => $q->whereIn('branches.id', StockLocation::whereIn('id', $this->counters())->select('branch_id')))->where(function ($q) use ($actor) {
            $q->where('created_by', $actor->id)->orWhere('issued_by', $actor->id)
                ->orWhereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE', 'ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED'])
                ->orWhereIn('id', Payment::where('received_by', $actor->id)->select('order_id'));
        });
    }

    /** @param Builder<Shift> $query
     * @return Builder<Shift>
     */
    public function shifts(Builder $query): Builder
    {
        if ($this->broad()) {
            return $query;
        }

        return $query->where('user_id', $this->context->user()->id)->where('type', $this->settlementOnly() ? 'CASHIER' : 'WAITER');
    }

    /** @param Builder<Payment> $query
     * @return Builder<Payment>
     */
    public function payments(Builder $query): Builder
    {
        if ($this->broad()) {
            return $query;
        }
        $actor = $this->context->user();
        if ($this->settlementOnly()) {
            return $query->where('received_by', $actor->id);
        }
        $orders = Order::where('waiter_id', $actor->id)->select('id');

        return $query->where(fn ($q) => $q->whereIn('order_id', clone $orders)->orWhereIn('id', PaymentAllocation::whereIn('order_id', clone $orders)->select('payment_id')));
    }

    /** @template T of \Illuminate\Database\Eloquent\Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function ledger(Builder $query, ?int $counter = null): Builder
    {
        if (! $this->broad() && ! $this->settlementOnly()) {
            $query->where('waiter_id', $this->context->user()->id);
        }
        if ($this->settlementOnly() || $counter) {
            $orders = Order::query();
            if ($counter) {
                $this->assertLocation($counter);
                $orders->where('stock_location_id', $counter);
            }
            $query->whereIn('order_id', $this->atCounter($orders)->select('id'));
            if ($this->settlementOnly()) {
                $query->whereIn('waiter_id', $this->waiters(User::query())->select('users.id'));
            }
        }

        return $query;
    }

    /** @param Builder<User> $query
     * @return Builder<User>
     */
    public function waiters(Builder $query): Builder
    {
        $query->where('company_id', $this->context->companyId())->whereHas('role', fn ($q) => $q->where('name', 'Waiter'));
        if ($this->broad()) {
            return $query;
        }
        if (! $this->settlementOnly()) {
            return $query->whereKey($this->context->user()->id);
        }
        $shifts = $this->atCounter(Shift::where('type', 'WAITER')->where('status', 'OPEN'))->select('user_id');
        $orders = $this->atCounter(Order::whereIn('status', ['ISSUED', 'PARTIALLY_PAID']))->select('waiter_id');

        return $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', StockLocation::whereIn('id', $this->counters())->select('branch_id')))->where(fn ($q) => $q->whereIn('id', $shifts)->orWhereIn('id', $orders));
    }

    /** @param Builder<BusinessNotification> $query
     * @return Builder<BusinessNotification>
     */
    public function notifications(Builder $query): Builder
    {
        if ($this->broad()) {
            return $query;
        }

        return $this->settlementOnly() ? $this->atCounter($query) : $query->where('user_id', $this->context->user()->id);
    }

    public function canViewShift(User $user, Shift $shift): bool
    {
        return $user->company_id === $shift->company_id && $user->canAccessBranch($shift->branch_id)
            && ($this->broad($user) || ($shift->user_id === $user->id && ($shift->type !== 'CASHIER' || $this->settlementOnly($user))));
    }

    public function canViewOrder(User $user, Order $order): bool
    {
        if ($user->company_id !== $order->company_id || ! $user->canAccessBranch($order->branch_id)) {
            return false;
        }
        if ($this->broad($user)) {
            return true;
        }
        if (! $this->settlementOnly($user)) {
            return $order->waiter_id === $user->id;
        }

        return in_array($order->stock_location_id, $this->counters($user), true)
            && User::where('company_id', $user->company_id)->find($order->waiter_id)?->canAccessBranch($order->branch_id)
            && ($order->created_by === $user->id || $order->issued_by === $user->id
                || in_array($order->status, ['DRAFT', 'WAITING_FOR_ISSUE', 'ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED'], true)
                || Payment::withoutGlobalScopes()->where('company_id', $user->company_id)->where('order_id', $order->id)->where('received_by', $user->id)->exists());
    }
}
