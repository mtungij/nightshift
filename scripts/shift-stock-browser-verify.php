<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Read-only browser verification requires the isolated validation database.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-reconciliation-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$counts = ['movements' => DB::table('stock_movements')->count(), 'ledger' => DB::table('waiter_ledger_entries')->count(), 'payments' => DB::table('payments')->count()];
if ($counts !== $f['before']) {
    throw new RuntimeException('Reconciliation changed stock, ledger or payment transaction counts.');
}
foreach ($f['cases'] as $kind => $case) {
    $snapshot = DB::table('shift_stock_snapshots')->where('shift_id', $case['shift_id'])->where('kind', 'CLOSING')->first();
    if (! $snapshot || DB::table('shifts')->where('id', $case['shift_id'])->value('status') !== 'CLOSED') {
        throw new RuntimeException('Missing closed shift snapshot.');
    }
    $report = json_decode($snapshot->data, true, flags: JSON_THROW_ON_ERROR);
    foreach ($report['rows'] as $row) {
        $current = DB::table('stocks')->where('stock_location_id', $case['counter_id'])->where('product_id', $row['product_id'])->value('quantity') ?? '0';
        $ledger = (string) DB::table('stock_movements')->where('stock_location_id', $case['counter_id'])->where('product_id', $row['product_id'])->sum('quantity');
        if (bccomp($current, $ledger, 6) !== 0 || bccomp($current, $row['expected'], 6) !== 0) {
            throw new RuntimeException('Stock and movement ledger disagree.');
        }
    }
    if ($report['totals']['variance_value'] !== ($kind === 'mobile' ? '6000.00' : '0.00')) {
        throw new RuntimeException('Unexpected stored variance value.');
    }
    if (! $report['counted_at'] || ! $report['closed_by_id']) {
        throw new RuntimeException('Missing immutable closing attribution.');
    }
}
echo json_encode(['result' => 'PASS', 'counter_shifts' => 2, 'transaction_counts_unchanged' => $counts, 'stock_matches_ledger' => true, 'variance_did_not_adjust_stock' => true], JSON_THROW_ON_ERROR)."\n";
