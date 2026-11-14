<?php

namespace Tests\Support;

use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;

trait SettlesCounterWaiters
{
    protected function settleCounterWaiters(int $counter): void
    {
        foreach (Order::where('stock_location_id', $counter)->whereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE'])->get() as $order) {
            app(OrderService::class)->cancel($order->id);
        }
        foreach (Order::where('stock_location_id', $counter)->whereIn('status', ['ISSUED', 'PARTIALLY_PAID'])->where('balance', '>', 0)->get() as $order) {
            app(PaymentService::class)->receive($order->id, [['method' => 'Cash', 'amount' => $order->balance]]);
        }
        foreach (Shift::where('stock_location_id', $counter)->where('type', 'WAITER')->where('status', 'OPEN')->get() as $shift) {
            app(TenantContext::class)->run(User::findOrFail($shift->user_id), fn () => app(ShiftService::class)->close($shift->id, []));
        }
    }
}
