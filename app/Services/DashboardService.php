<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use App\Models\VoidRequest;
use App\Models\WaiterLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function metrics(?int $branch = null, string $period = 'Today'): array
    {
        [$from, $to] = $this->dates($period);

        return $this->metricsBetween($branch, $from, $to);
    }

    /** @return array<string, mixed> */
    public function metricsBetween(?int $branch, CarbonImmutable $from, CarbonImmutable $to, ?int $location = null, ?int $cashierShift = null): array
    {
        Gate::authorize('view-dashboard');
        $visibility = app(StaffVisibility::class);
        $actor = app(TenantContext::class)->user();
        $orders = $visibility->orders(Order::whereBetween('issued_at', [$from, $to])->whereNotIn('status', ['VOIDED', 'CANCELLED']));
        if ($visibility->settlementOnly()) {
            $orders->where(fn ($q) => $q->where('created_by', $actor->id)->orWhere('issued_by', $actor->id));
        }
        if ($branch) {
            $orders->where('branch_id', $branch);
        }
        if ($location) {
            $orders->where('stock_location_id', $location);
        }
        if ($cashierShift) {
            $orders->where('issued_shift_id', $cashierShift);
        }
        $payments = $visibility->payments(Payment::where('is_credit', false)->whereBetween('created_at', [$from, $to]));
        if ($branch) {
            $payments->where('branch_id', $branch);
        }
        if ($location) {
            $payments->whereIn('shift_id', Shift::where('stock_location_id', $location)->where('type', 'CASHIER')->whereBetween('closed_at', [$from, $to])->select('id'));
        }
        if ($cashierShift) {
            $payments->where('shift_id', $cashierShift);
        }
        $ledger = $visibility->ledger(WaiterLedgerEntry::query(), $location);
        if ($branch) {
            $ledger->where('branch_id', $branch);
        }
        $totals = $ledger->selectRaw('COALESCE(SUM(debit),0) as debits, COALESCE(SUM(credit),0) as credits')->toBase()->first();
        $open = $visibility->orders(Order::whereNotIn('status', ['PAID', 'VOIDED', 'CANCELLED', 'RETURNED']));
        if ($branch) {
            $open->where('branch_id', $branch);
        }
        if ($location) {
            $open->where('stock_location_id', $location);
        }
        $stocks = Stock::query();
        if ($location) {
            $stocks->where('stocks.stock_location_id', $location);
        }
        if ($branch) {
            $stocks->where('stocks.branch_id', $branch);
        }
        $value = $actor->hasPermission('manage-inventory') ? $stocks->join('products', function ($join) {
            $join->on('stocks.product_id', '=', 'products.id')->on('stocks.company_id', '=', 'products.company_id');
        })->selectRaw('COALESCE(SUM(stocks.quantity * products.purchase_cost),0) as value')->value('value') : '0';
        $adjustments = StockAdjustmentItem::query()->join('stock_adjustments', 'stock_adjustment_items.stock_adjustment_id', '=', 'stock_adjustments.id')->where('stock_adjustments.status', 'APPROVED')->whereBetween('stock_adjustments.decided_at', [$from, $to]);
        if ($branch) {
            $adjustments->where('stock_adjustments.branch_id', $branch);
        }
        if (! $visibility->broad()) {
            $adjustments->where('stock_adjustments.requested_by', $actor->id);
        }
        if ($location) {
            $adjustments->where('stock_adjustments.stock_location_id', $location);
        }
        $costs = (clone $adjustments)->selectRaw('stock_adjustments.reason, SUM(stock_adjustment_items.cost) as total')->groupBy('stock_adjustments.reason')->pluck('total', 'reason');
        $voids = VoidRequest::query()->where('void_requests.status', 'APPROVED')->whereBetween('void_requests.decided_at', [$from, $to]);
        if ($branch) {
            $voids->where('void_requests.branch_id', $branch);
        }
        if (! $visibility->broad()) {
            $voids->where('void_requests.requested_by', $actor->id);
        }
        if ($location) {
            $voids->whereIn('order_id', Order::where('stock_location_id', $location)->select('id'));
        }
        $voidValue = $voids->join('orders', 'void_requests.order_id', '=', 'orders.id')->selectRaw('COALESCE(SUM(orders.subtotal-orders.discount-orders.return_total),0) as value')->value('value');
        $shifts = $visibility->shifts(Shift::query());
        if ($branch) {
            $shifts->where('branch_id', $branch);
        }
        if ($location) {
            $shifts->where('stock_location_id', $location);
        }
        $shortages = (clone $shifts)->where('status', 'CLOSED')->whereBetween('closed_at', [$from, $to])->where('cash_difference', '<', 0)->sum('cash_difference');
        $variance = (clone $shifts)->where('status', 'CLOSED')->whereBetween('closed_at', [$from, $to])->sum('cash_difference');
        $activeWaiters = (clone $shifts)->where('type', 'WAITER')->where('status', 'OPEN')->count();
        if ($visibility->settlementOnly()) {
            $serving = $visibility->atCounter(Shift::where('type', 'WAITER')->where('status', 'OPEN'));
            if ($branch) {
                $serving->where('branch_id', $branch);
            }
            $activeWaiters = $visibility->waiters(User::query())->whereIn('id', $serving->select('user_id'))->count();
        }
        $hours = [];
        $daily = [];
        foreach ((clone $orders)->select(['issued_at', 'total'])->get() as $o) {
            $key = $o->issued_at->format('H:00');
            $hours[$key] = D::add($hours[$key] ?? '0', $o->total);
            $day = $o->issued_at->format('D');
            $daily[$day] = D::add($daily[$day] ?? '0', $o->total);
        }ksort($hours);
        $method = (clone $payments)->selectRaw('method, SUM(amount) as total')->groupBy('method')->pluck('total', 'method')->toArray();
        $byBranch = (clone $orders)->selectRaw('branch_id, SUM(total) as total')->groupBy('branch_id')->get()->map(fn ($r) => ['name' => Branch::whereKey($r->branch_id)->first()?->name, 'total' => D::money((string) $r->total)])->all();
        $byProduct = OrderItem::whereIn('order_id', (clone $orders)->select('orders.id'))->join('orders', 'order_items.order_id', '=', 'orders.id')->whereBetween('orders.issued_at', [$from, $to])->whereNotIn('orders.status', ['VOIDED', 'CANCELLED']);
        if ($branch) {
            $byProduct->where('orders.branch_id', $branch);
        }
        if ($location) {
            $byProduct->where('orders.stock_location_id', $location);
        }
        $top = (clone $byProduct)->selectRaw('order_items.product_id, MAX(order_items.product_name) as name, SUM(order_items.quantity-order_items.returned_quantity) as quantity, SUM(order_items.total-order_items.discount_share-order_items.returned_value) as total')->groupBy('order_items.product_id')->orderByDesc('total')->limit(5)->get()->toArray();

        return ['sales' => D::money((string) $orders->sum('total')), 'collected' => D::money((string) $payments->sum('amount')), 'outstanding' => D::sub((string) $totals->debits, (string) $totals->credits), 'open_orders' => $open->count(), 'stock_value' => D::money((string) $value), 'complimentary' => D::money((string) ($costs['COMPLIMENTARY'] ?? 0)), 'breakages' => D::money((string) ($costs['BREAKAGE'] ?? 0)), 'voids' => D::money((string) $voidValue), 'cash_shortage' => D::sub('0', (string) $shortages), 'cash_difference' => D::money((string) $variance), 'active_waiters' => $activeWaiters, 'hourly' => $hours, 'daily' => $daily, 'payment_methods' => $method, 'branches' => $byBranch, 'top_products' => $top];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dates(string $period): array
    {
        $date = now();

        return match ($period) {
            'Yesterday' => [$date->subDay()->startOfDay(), $date->subDay()->endOfDay()],'This week' => [$date->startOfWeek(), $date->endOfWeek()],default => [$date->startOfDay(), $date->endOfDay()]
        };
    }
}
