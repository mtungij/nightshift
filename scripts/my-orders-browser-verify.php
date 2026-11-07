<?php

use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesTicketBatch;
use App\Models\Shift;
use App\Models\Stock;
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
    throw new RuntimeException('My Orders verification requires the isolated MySQL validation database on port 3340.');
}
$fixture = json_decode(file_get_contents('/tmp/nightshift-my-orders-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($fixture) {
    $effects = ['orders' => Order::count(), 'stock_movements' => StockMovement::count(), 'ledger' => WaiterLedgerEntry::count(), 'payments' => Payment::count(), 'allocations' => PaymentAllocation::count(), 'print_batches' => SalesTicketBatch::count(), 'stocks' => Stock::orderBy('id')->pluck('quantity', 'id')->all(), 'debit' => WaiterLedgerEntry::sum('debit'), 'credit' => WaiterLedgerEntry::sum('credit')];
    if ($effects !== $fixture['effects']) {
        throw new RuntimeException('My Orders changed financial, print, order or stock data.');
    }
    $verified = AuditLog::where('action', 'MY_ORDERS_VERIFIED')->count();
    $failed = AuditLog::where('action', 'MY_ORDERS_PIN_FAILED')->count();
    if ($verified < 14 || $failed < 8) {
        throw new RuntimeException('My Orders security audit is incomplete.');
    }
    foreach (AuditLog::whereIn('action', ['MY_ORDERS_VERIFIED', 'MY_ORDERS_PIN_FAILED'])->get() as $event) {
        if (! isset($event->new_values['selected_staff_id'], $event->new_values['terminal_user_id'], $event->new_values['stock_location_id']) || isset($event->new_values['pin']) || isset($event->new_values['token'])) {
            throw new RuntimeException('My Orders security audit has missing or sensitive fields.');
        }
    }
    $shift = Shift::where('user_id', $fixture['accounts']['cashier']['id'])->where('type', 'CASHIER')->where('status', 'OPEN')->firstOrFail();
    $report = app(ShiftStockService::class)->report($shift->id);
    if ($report['totals']['ledger_mismatches'] !== 0) {
        throw new RuntimeException('Cashier reconciliation has ledger mismatches.');
    }

    return ['result' => 'PASS', 'orders_unchanged' => $effects['orders'], 'stock_movements_unchanged' => $effects['stock_movements'], 'ledger_entries_unchanged' => $effects['ledger'], 'payments_unchanged' => $effects['payments'], 'allocations_unchanged' => $effects['allocations'], 'print_batches_unchanged' => $effects['print_batches'], 'stock_balances_unchanged' => true, 'ledger_totals_unchanged' => true, 'successful_verifications' => $verified, 'failed_pin_verifications' => $failed, 'cashier_ledger_mismatches' => $report['totals']['ledger_mismatches']];
});
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
