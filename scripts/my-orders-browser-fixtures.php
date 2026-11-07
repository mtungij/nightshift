<?php

use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SalesTicketBatch;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// This existing guarded fixture enforces testing, mysql_validation and port 3340.
ob_start();
require __DIR__.'/shared-pos-browser-fixtures.php';
$accounts = json_decode(trim(ob_get_clean()), true, 512, JSON_THROW_ON_ERROR);
$result = app(TenantContext::class)->run($owner, function () use ($accounts) {
    $counter = StockLocation::where('name', 'Main Counter')->firstOrFail();
    $vip = StockLocation::where('name', 'VIP Counter')->firstOrFail();
    $unit = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', 'Piece'))->firstOrFail();
    $employee = function (string $role, string $username, string $pin, StockLocation $location): User {
        $user = User::where('username', $username)->first() ?? User::factory()->create(['username' => $username, 'name' => $username, 'company_id' => auth()->user()->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'active' => true, 'operational_pin' => Hash::make($pin)]);
        $user->branches()->sync([$location->branch_id => ['company_id' => $user->company_id]]);
        if (! Shift::where('user_id', $user->id)->where('status', 'OPEN')->exists()) {
            app(ShiftService::class)->open(['user_id' => $user->id, 'stock_location_id' => $location->id, 'type' => $role === 'Waiter' ? 'WAITER' : 'CASHIER', 'opening_cash' => '0']);
        }

        return $user;
    };
    $other = $employee('Cashier', 'my-orders-other-cashier', '1122', $counter);
    $vipWaiter = $employee('Waiter', 'my-orders-vip-waiter', '1133', $vip);
    $vipCashier = $employee('Cashier', 'my-orders-vip-cashier', '1144', $vip);
    $inactive = $employee('Waiter', 'my-orders-inactive', '1155', $counter);
    $inactive->forceFill(['active' => false])->save();
    $foreign = User::factory()->create(['role_id' => Role::where('name', 'Waiter')->firstOrFail()->id]);
    $issue = function (int $waiter, string $pin, string $quantity = '1', ?int $location = null) use ($counter, $unit): Order {
        $response = app(OrderService::class)->send(['waiter_id' => $waiter, 'pin' => $pin, 'stock_location_id' => $location ?? $counter->id, 'discount' => '0', 'order_note' => 'Mteja karibu na TV <b>private note</b>', 'items' => [['product_unit_id' => $unit->id, 'quantity' => $quantity]]], (string) Str::uuid());

        return Order::findOrFail($response['id']);
    };
    $unpaid = $issue($accounts['asha']['id'], '0123');
    $partial = $issue($accounts['asha']['id'], '0123', '2');
    $paid = $issue($accounts['asha']['id'], '0123');
    $james = $issue($accounts['james']['id'], '6789');
    $restricted = $issue($accounts['james']['id'], '6789');
    $vipOrder = $issue($vipWaiter->id, '1133', '1', $vip->id);
    $cashier = User::findOrFail($accounts['cashier']['id']);
    app(TenantContext::class)->run($cashier, function () use ($partial, $paid) {
        app(PaymentService::class)->receive($partial->id, [['method' => 'Cash', 'amount' => '3000']]);
        app(PaymentService::class)->receive($paid->id, [['method' => 'Cash', 'amount' => '3000']]);
    });
    app(TenantContext::class)->run($other, fn () => app(PaymentService::class)->receive($restricted->id, [['method' => 'Cash', 'amount' => '3000']]));
    $effects = ['orders' => Order::count(), 'stock_movements' => StockMovement::count(), 'ledger' => WaiterLedgerEntry::count(), 'payments' => Payment::count(), 'allocations' => PaymentAllocation::count(), 'print_batches' => SalesTicketBatch::count(), 'stocks' => Stock::orderBy('id')->pluck('quantity', 'id')->all(), 'debit' => WaiterLedgerEntry::sum('debit'), 'credit' => WaiterLedgerEntry::sum('credit')];

    return ['accounts' => $accounts, 'counter_id' => $counter->id, 'orders' => ['unpaid' => $unpaid->id, 'partial' => $partial->id, 'paid' => $paid->id, 'james' => $james->id, 'restricted_paid' => $restricted->id, 'vip' => $vipOrder->id], 'excluded_staff' => [$vipWaiter->id, $vipCashier->id, $inactive->id, $foreign->id], 'other_cashier_id' => $other->id, 'effects' => $effects];
});
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
