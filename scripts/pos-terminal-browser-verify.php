<?php

use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\SalesTicketBatch;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\ShiftStockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Terminal verification requires the isolated MySQL validation database on port 3340.');
}
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () {
    $terminal = User::where('username', 'pos-main-counter')->firstOrFail();
    $orders = Order::where('created_by', $terminal->id)->get();
    if ($orders->count() < 4) {
        throw new RuntimeException('Run the desktop/mobile multi-waiter terminal browser checks first.');
    }
    foreach ($orders as $order) {
        $movements = StockMovement::where('reference_type', 'Order')->where('reference_id', $order->id)->where('movement_type', 'ISSUE')->get();
        $ledger = WaiterLedgerEntry::where('order_id', $order->id)->where('entry_type', 'ISSUE')->get();
        $batches = SalesTicketBatch::where('order_id', $order->id)->where('reprint', false)->get();
        $audit = AuditLog::where('action', 'ORDER_CREATED')->where('reference_id', $order->id)->firstOrFail();
        if ($movements->count() !== 1 || bccomp((string) $movements->first()->quantity, '-1', 6) !== 0 || $ledger->count() !== 1 || $ledger->first()->waiter_id !== $order->waiter_id || bccomp((string) $ledger->first()->debit, $order->total, 2) !== 0 || $batches->count() !== 1 || $batches->first()->copies !== ['WAITER COPY', 'CASHIER COPY'] || ! $batches->first()->dispatched_at || $order->issued_by !== $terminal->id || $audit->user_id !== $terminal->id || $audit->new_values['waiter_id'] !== $order->waiter_id || $audit->new_values['stock_location_id'] !== $order->stock_location_id) {
            throw new RuntimeException('Terminal order accounting/print/audit mismatch: '.$order->id);
        }
    }
    $shift = Shift::where('stock_location_id', $terminal->terminal_stock_location_id)->where('type', 'CASHIER')->where('status', 'OPEN')->firstOrFail();
    $reconciliation = app(ShiftStockService::class)->report($shift->id);
    if ($reconciliation['totals']['ledger_mismatches'] !== 0) {
        throw new RuntimeException('Cashier reconciliation has ledger mismatches.');
    }

    return ['result' => 'PASS', 'orders' => $orders->count(), 'waiters' => $orders->pluck('waiter_id')->unique()->values()->all(), 'stock_issues' => $orders->count(), 'ledger_debits' => $orders->count(), 'two_copy_batches' => $orders->count(), 'terminal_user_id' => $terminal->id, 'counter_id' => $terminal->terminal_stock_location_id, 'cashier_ledger_mismatches' => $reconciliation['totals']['ledger_mismatches']];
});
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
