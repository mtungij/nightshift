<?php

use App\Domain\TenantContext;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation') {
    throw new RuntimeException('Disposable database only.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-report-shift-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$baseline = json_decode(file_get_contents('/tmp/nightshift-report-shift-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($baseline as $table => $before) {
    $rows = DB::table($table)->where('company_id', $f['company_id'])->where('id', '<=', $before['max_id'])->orderBy('id')->get();
    if (hash('sha256', $rows->toJson()) !== $before['hash']) {
        throw new RuntimeException('Historical changes: '.$table);
    }
}
$owner = User::where('username', $f['owner'])->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($f) {
    $report = fn ($name, $id) => app(ReportService::class)->generate($name, ['shift_id' => $id, 'from' => '2001-01-01', 'to' => '2001-01-02']);
    $sales = $report('Daily Sales', $f['shift_id']);
    $payments = $report('Payment Method Report', $f['shift_id']);
    $receipts = $report('Cash Received from Cashiers', $f['shift_id']);
    $stock = app(ReportService::class)->generate('Closing Stock', ['shift_id' => $f['shift_id'], 'product_id' => $f['product_id']]);
    if (array_sum(array_column($sales['rows'], 3)) !== 2000.0 || (float) $payments['rows'][0][1] !== 2000.0 || array_sum(array_column($receipts['rows'], 6)) !== 2000.0) {
        throw new RuntimeException('Cash reconciliation mismatch');
    }
    $balance = DB::table('stocks')->where('stock_location_id', $f['counter_id'])->where('product_id', $f['product_id'])->value('quantity');
    if ((float) $balance !== 6.0 || (float) $stock['rows'][0][2] !== 7.0) {
        throw new RuntimeException('Frozen/live stock mismatch');
    }
    $debt = DB::table('waiter_ledger_entries')->where('shift_id', $f['waiter_shift_id'])->selectRaw('SUM(debit-credit) AS debt')->value('debt');
    if ((float) $debt !== 0.0) {
        throw new RuntimeException('Closed waiter debt mismatch');
    }

    return ['sales' => 2000, 'cash_collected' => 2000, 'treasury_received' => 2000, 'frozen_closing_stock' => 7, 'physical_live_stock' => 6, 'closed_waiter_debt' => 0];
});
echo json_encode(['result' => 'PASS', 'historical_tables_unchanged' => count($baseline), 'reconciliation' => $result], JSON_PRETTY_PRINT).PHP_EOL;
