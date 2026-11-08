<?php

use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
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
    throw new RuntimeException('Payment verification requires the disposable MySQL validation database on port 3340.');
}
$fixture = json_decode(file_get_contents('/tmp/nightshift-my-orders-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($fixture) {
    $before = $fixture['effects'];
    $payments = Payment::where('id', '>', $fixture['payment_baseline']['max_payment_id'])->get();
    $amount = (string) $payments->sum('amount');
    if ($payments->count() < 2 || bccomp($amount, '2000', 2) !== 0 || Order::count() !== $before['orders'] || StockMovement::count() !== $before['stock_movements'] || Stock::orderBy('id')->pluck('quantity', 'id')->all() !== $before['stocks'] || bccomp((string) WaiterLedgerEntry::sum('debit'), (string) $before['debit'], 2) !== 0 || bccomp(bcsub((string) WaiterLedgerEntry::sum('credit'), (string) $before['credit'], 2), '2000', 2) !== 0 || bccomp(bcsub((string) PaymentAllocation::sum('amount'), (string) $fixture['payment_baseline']['allocation_total'], 2), '2000', 2) !== 0 || bccomp(bcsub((string) Order::sum('amount_paid'), (string) $fixture['payment_baseline']['orders_paid_total'], 2), '2000', 2) !== 0) {
        throw new RuntimeException('Frontend payments failed accounting/stock reconciliation.');
    }
    foreach ($payments as $payment) {
        if ($payment->received_by !== $fixture['accounts']['cashier']['id'] || $payment->method !== 'Cash' || $payment->is_credit || bccomp((string) PaymentAllocation::where('payment_id', $payment->id)->sum('amount'), $payment->amount, 2) !== 0 || bccomp((string) WaiterLedgerEntry::where('payment_id', $payment->id)->where('entry_type', 'PAYMENT')->sum('credit'), $payment->amount, 2) !== 0) {
            throw new RuntimeException('Cashier attribution/allocation mismatch.');
        }
    }
    $shift = Shift::where('user_id', $fixture['accounts']['cashier']['id'])->where('type', 'CASHIER')->where('status', 'OPEN')->firstOrFail();
    $report = app(ShiftStockService::class)->report($shift->id);
    if ($report['totals']['ledger_mismatches'] !== 0) {
        throw new RuntimeException('Cashier reconciliation has ledger mismatches.');
    }

    return ['result' => 'PASS', 'received_by' => $fixture['accounts']['cashier']['id'], 'payment_rows' => $payments->count(), 'total_paid' => $amount, 'ledger_credit_delta' => '2000.00', 'allocation_delta' => '2000.00', 'stock_unchanged' => true, 'orders_not_created' => true, 'cashier_ledger_mismatches' => 0];
});
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
