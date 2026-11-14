<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation') {
    throw new RuntimeException('Use only the disposable browser validation database.');
}
$fixture = json_decode(file_get_contents('/tmp/nightshift-business-unit-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$owner = User::where('company_id', $fixture['company_id'])->where('username', $fixture['owner'])->firstOrFail();
$financial = [];
foreach (['orders', 'order_items', 'payments', 'payment_allocations', 'waiter_ledger_entries', 'stocks', 'stock_movements', 'treasury_entries', 'cash_handovers', 'cash_handover_events', 'shifts', 'shift_stock_snapshots', 'shift_stock_counts'] as $table) {
    $financial[$table] = hash('sha256', json_encode(DB::table($table)->where('company_id', $owner->company_id)->orderBy('id')->get(), JSON_THROW_ON_ERROR));
}
app(TenantContext::class)->run($owner, function () use ($fixture) {
    $branch = Branch::findOrFail($fixture['branch_id']);
    $unit = BusinessUnit::findOrFail($fixture['cases']['bar']['unit_id']);
    app(AuditService::class)->record('BUSINESS_UNIT_SAVED', 'Business Units', 'BusinessUnit', $unit->id, ['name' => 'Old UI example', 'active' => true], ['name' => 'Readable audit example', 'active' => true, 'business_unit_id' => $unit->id, 'branch_id' => $branch->id, 'nested' => ['pin' => 'SYNTHETIC_UI_PIN_SECRET', 'apiKey' => 'SYNTHETIC_UI_API_SECRET']], $branch->id);
});
file_put_contents('/tmp/nightshift-admin-ui-financial-baseline.json', json_encode($financial, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'PASS', 'financial_tables' => count($financial), 'audit_fixture' => 'synthetic, non-financial'], JSON_THROW_ON_ERROR).PHP_EOL;
