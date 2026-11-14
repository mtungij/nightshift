<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Disposable browser database only.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-pos-admin-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$tables = ['orders', 'order_items', 'payments', 'payment_allocations', 'waiter_ledger_entries', 'stocks', 'stock_movements', 'treasury_entries', 'cash_handovers', 'cash_handover_events', 'shifts', 'shift_stock_snapshots', 'shift_stock_counts'];
$state = [];
foreach ($tables as $table) {
    $rows = DB::table($table)->orderBy('id')->get();
    $state[$table] = ['rows' => $rows->count(), 'hash' => hash('sha256', $rows->toJson())];
}
$path = '/tmp/pos-admin-history-baseline.json';
if (in_array('--capture', $_SERVER['argv'] ?? [], true)) {
    // Explicitly configured narrow pivots must still be ignored by the owner's
    // existing all-business-units grant. Original metadata is saved for restore.
    User::findOrFail($f['owner_id'])->forceFill(['business_units_configured' => true])->save();
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    echo "PASS: captured all rows in 13 financial/history tables.\n";
    exit;
}
$before = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
if ($before !== $state) {
    throw new RuntimeException('Financial/history state changed during read-only POS checks.');
}
$prior = json_decode(file_get_contents('/tmp/pos-context-reconciliation.json'), true, flags: JSON_THROW_ON_ERROR);
$order = DB::table('orders')->where('company_id', $f['company_id'])->where('id', $prior['order_id'])->first();
$quantity = DB::table('stocks')->where('company_id', $f['company_id'])->where('stock_location_id', $f['bar']['counter_id'])->where('product_id', $f['products']['bar']['id'])->value('quantity');
$movement = DB::table('stock_movements')->where('company_id', $f['company_id'])->where('stock_location_id', $f['bar']['counter_id'])->where('product_id', $f['products']['bar']['id'])->sum('quantity');
$ledger = DB::table('waiter_ledger_entries')->where('company_id', $f['company_id'])->where('order_id', $order->id)->selectRaw('SUM(debit) AS debit, SUM(credit) AS credit')->first();
$allocated = DB::table('payment_allocations')->where('company_id', $f['company_id'])->where('order_id', $order->id)->sum('amount');
if ($order->status !== 'PAID' || (float) $order->total !== 5000.0 || (float) $order->amount_paid !== 5000.0 || (float) $allocated !== 5000.0 || (float) $quantity !== 9.0 || (float) $movement !== 9.0 || (float) $ledger->debit !== 5000.0 || (float) $ledger->credit !== 5000.0) {
    throw new RuntimeException('Existing order/stock/payment/ledger reconciliation failed.');
}
$result = ['result' => 'PASS', 'unchanged_financial_tables' => count($tables), 'order_id' => $order->id, 'total' => $order->total, 'paid' => $order->amount_paid, 'payment_allocated' => (string) $allocated, 'stock' => (string) $quantity, 'movement_stock' => (string) $movement, 'waiter_debit' => (string) $ledger->debit, 'waiter_credit' => (string) $ledger->credit];
file_put_contents('/tmp/pos-admin-reconciliation.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($result).PHP_EOL;
