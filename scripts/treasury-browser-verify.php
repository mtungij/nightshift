<?php

use App\Domain\TenantContext;
use App\Models\CashHandover;
use App\Models\CashHandoverEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\ShiftStockSnapshot;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\TreasuryEntry;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\TreasuryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Treasury verification requires the separate disposable browser database.');
}
$result = json_decode(file_get_contents('/tmp/nightshift-treasury-browser/result.json'), true, flags: JSON_THROW_ON_ERROR);
if ($result['result'] !== 'PASS') {
    throw new RuntimeException('Browser scenarios did not pass.');
}
$f = $result['fixtures'];
$owner = User::findOrFail($f['owner_id']);
$result = app(TenantContext::class)->run($owner, function () use ($f) {
    $current = ['orders' => Order::count(), 'payments' => Payment::count(), 'ledger' => WaiterLedgerEntry::count(), 'movements' => StockMovement::count(), 'payment_total' => (string) Payment::sum('amount'), 'waiter_debits' => (string) WaiterLedgerEntry::sum('debit'), 'waiter_credits' => (string) WaiterLedgerEntry::sum('credit')];
    if ($current !== $f['before']) {
        throw new RuntimeException('Custody movement changed POS sales, stock, payments or waiter ledger.');
    }
    $balances = [];
    foreach ($f['cases'] as $kind => $case) {
        $expected = $kind === 'desktop' ? '150000.00' : '145000.00';
        $balance = app(TreasuryService::class)->balance($case['accountant_id'], $f['branch_id']);
        if (bccomp($balance, $expected, 2) !== 0) {
            throw new RuntimeException('Unexpected treasury custody balance.');
        }
        if (TreasuryEntry::where('custodian_id', $case['accountant_id'])->count() !== 5) {
            throw new RuntimeException('Duplicate or missing cash movement.');
        }
        foreach (['PURCHASE', 'EXPENSE', 'SALARY', 'BANK_DEPOSIT'] as $type) {
            if (TreasuryEntry::where('custodian_id', $case['accountant_id'])->where('transaction_type', $type)->count() !== 1) {
                throw new RuntimeException('Expected exactly one authorized outflow of each kind.');
            }
        }
        $shift = Shift::findOrFail($case['closed_shift_id']);
        $snapshot = ShiftStockSnapshot::where('shift_id', $shift->id)->where('kind', 'CLOSING')->firstOrFail()->data;
        if ($shift->status !== 'CLOSED' || $snapshot['cash']['actual'] !== '650000.00' || $snapshot['cash']['waiter_outstanding'] !== '0.00' || $snapshot['totals']['ledger_mismatches'] !== 0) {
            throw new RuntimeException('Cash or stock reconciliation did not remain intact.');
        }
        $quantity = Stock::where('stock_location_id', $case['counter_id'])->where('product_id', $case['product_id'])->value('quantity');
        $movementBalance = (string) StockMovement::where('stock_location_id', $case['counter_id'])->where('product_id', $case['product_id'])->sum('quantity');
        if (bccomp((string) $quantity, '1', 6) !== 0 || bccomp((string) $quantity, $movementBalance, 6) !== 0) {
            throw new RuntimeException('Physical stock basis or stock movement ledger changed.');
        }
        $h = CashHandover::where('source_shift_id', $shift->id)->where('destination', 'TREASURY')->firstOrFail();
        if ($h->amount !== '650000.00') {
            throw new RuntimeException('Expected handover was overwritten.');
        }
        if ($kind === 'mobile' && ! CashHandoverEvent::where('handover_id', $h->id)->where('status', 'APPROVED')->where('difference', '-5000')->exists()) {
            throw new RuntimeException('Missing approved receipt discrepancy.');
        }
        if ($kind === 'desktop') {
            if ($shift->opening_cash !== '650000.00' || ! CashHandoverEvent::where('related_shift_id', $shift->id)->where('status', 'OPENED')->exists()) {
                throw new RuntimeException('Next cashier opening cash lost its handover lineage.');
            }
        }
        $balances[$kind] = $balance;
    }

    return ['result' => 'PASS', 'treasury_balances' => $balances, 'cash_movements' => TreasuryEntry::count(), 'pos_stock_payment_waiter_ledger_unchanged' => true, 'stock_matches_movements' => true, 'cash_discrepancy_preserved' => true, 'confirmed_receipt_used_for_opening' => true];
});
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
