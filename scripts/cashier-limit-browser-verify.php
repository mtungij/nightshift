<?php

use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Use disposable browser validation schema only.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-cashier-limit-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$baseline = json_decode(file_get_contents('/tmp/nightshift-cashier-limit-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = [];
foreach ($baseline as $table => $before) {
    $rows = DB::table($table)->where('company_id', $f['company_id'])->where('id', '<=', $before['max_id'])->orderBy('id')->get();
    $checks[$table.'_original_rows_unchanged'] = hash_equals($before['hash'], hash('sha256', $rows->toJson()));
}
foreach (['orders', 'order_items', 'payments', 'payment_allocations', 'waiter_ledger_entries', 'stocks', 'stock_movements', 'treasury_entries'] as $table) {
    $checks[$table.'_no_unexpected_rows'] = DB::table($table)->where('company_id', $f['company_id'])->where('id', '>', $baseline[$table]['max_id'])->count() === 0;
}
$shifts = DB::table('shifts')->where('company_id', $f['company_id'])->where('stock_location_id', $f['counter_id'])->get();
$checks['at_least_three_real_cashier_sessions'] = $shifts->count() >= 3;
$checks['individual_sessions_closed_zero_cash'] = $shifts->every(fn ($s) => $s->type === 'CASHIER' && $s->status === 'CLOSED' && bccomp($s->opening_cash, '0', 2) === 0 && bccomp($s->expected_cash, '0', 2) === 0 && bccomp($s->actual_cash, '0', 2) === 0 && bccomp($s->cash_difference, '0', 2) === 0);
foreach ($shifts as $shift) {
    foreach (['OPENING', 'CLOSING'] as $kind) {
        $checks['shift_'.$shift->id.'_'.$kind.'_exactly_once'] = DB::table('shift_stock_snapshots')->where('company_id', $f['company_id'])->where('shift_id', $shift->id)->where('kind', $kind)->count() === 1;
    }
}
$handovers = DB::table('cash_handovers')->where('company_id', $f['company_id'])->whereIn('source_shift_id', $shifts->pluck('id'))->get();
$checks['independent_zero_cash_dispositions'] = $handovers->count() === $shifts->count() && $handovers->every(fn ($h) => $h->destination === 'NONE' && bccomp($h->amount, '0', 2) === 0);
$owner = User::where('company_id', $f['company_id'])->where('username', $f['owner'])->firstOrFail();
$checks += app(TenantContext::class)->run($owner, function () use ($f, $owner) {
    $c = StockLocation::findOrFail($f['counter_id']);

    app()->setLocale('sw');
    $audit = AuditLog::where('action', 'CASHIER_LIMIT_UPDATED')->where('reference_id', (string) $c->id)->latest('id')->firstOrFail();
    $shown = app(AuditTrailService::class)->present(collect([$audit]))[0];

    return ['audit_action_localized' => $shown['action'] === 'Kikomo cha Makashia Kimebadilishwa', 'audit_field_localized' => $shown['changes'][0]['field'] === 'Idadi ya Juu ya Makashia', 'no_cashiers_left_open' => $c->active_cashiers === 0, 'capacity_restored_to_default' => $c->max_active_cashiers === 1, 'setting_change_audit_recorded' => DB::table('audit_logs')->where('company_id', $f['company_id'])->where('action', 'CASHIER_LIMIT_UPDATED')->where('reference_id', (string) $c->id)->where('user_id', $owner->id)->exists()];
});
$pass = ! in_array(false, $checks, true);
echo json_encode(['result' => $pass ? 'PASS' : 'FAIL', 'checks' => $checks, 'synthetic_closed_shifts' => $shifts->count()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($pass ? 0 : 1);
