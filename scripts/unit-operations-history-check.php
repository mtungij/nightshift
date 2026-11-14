<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Disposable browser database only.');
}
$tables = ['orders', 'order_items', 'payments', 'payment_allocations', 'waiter_ledger_entries', 'stocks', 'stock_movements', 'treasury_entries', 'cash_handovers', 'cash_handover_events', 'shifts', 'shift_stock_snapshots', 'shift_stock_counts', 'sales_ticket_batches', 'operational_unit_contexts'];
$fingerprint = function () use ($tables) {
    $result = [];
    foreach ($tables as $table) {
        $rows = DB::table($table)->orderBy('id')->get();
        $result[$table] = ['rows' => $rows->count(), 'hash' => hash('sha256', $rows->toJson())];
    }

    return $result;
};
$path = '/tmp/unit-operations-history-baseline.json';
if (in_array('--migrate', $_SERVER['argv'], true)) {
    $before = $fingerprint();
    file_put_contents($path, json_encode($before, JSON_THROW_ON_ERROR));
    Artisan::call('migrate', ['--force' => true]);
    if ($before !== $fingerprint()) {
        throw new RuntimeException('Migration altered operational history or balances.');
    }
} else {
    $before = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $after = $fingerprint();
    if (in_array('--allow-configuration-audits', $_SERVER['argv'], true)) {
        // The explicit fixture preparation creates current configuration audits.
        // Verify every original context row, and allow only appended audit contexts.
        $original = DB::table('operational_unit_contexts')->orderBy('id')->limit($before['operational_unit_contexts']['rows'])->get();
        $added = DB::table('operational_unit_contexts')->where('id', '>', $original->last()?->id ?? 0)->get();
        if ($original->count() !== $before['operational_unit_contexts']['rows'] || hash('sha256', $original->toJson()) !== $before['operational_unit_contexts']['hash'] || $added->contains(fn ($row) => $row->record_type !== 'audit_logs')) {
            throw new RuntimeException('Original attribution changed or unexpected operational rows were added.');
        }
        $after['operational_unit_contexts'] = $before['operational_unit_contexts'];
        echo 'Allowed '.count($added).' appended configuration audit contexts; all original contexts preserved.'.PHP_EOL;
    }
    if ($before !== $after) {
        throw new RuntimeException('History changed during read-only validation.');
    }
}
echo 'PASS: all original rows unchanged in '.count($tables).' operational/history tables.'.PHP_EOL;
