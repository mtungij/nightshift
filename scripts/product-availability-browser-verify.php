<?php

use App\Domain\BusinessUnitContext;
use App\Domain\TenantContext;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\BusinessUnitService;
use App\Services\ReportService;
use App\Services\TransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Use isolated browser validation database.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-business-unit-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$baseline = json_decode(file_get_contents('/tmp/nightshift-admin-ui-financial-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$owner = User::where('company_id', $f['company_id'])->where('username', $f['owner'])->firstOrFail();
$checks = app(TenantContext::class)->run($owner, function () use ($f) {
    app(BusinessUnitContext::class)->select(null);
    $unit = ProductUnit::findOrFail($f['selling_id']);
    $filters = ['from' => '2026-01-01', 'to' => '2026-12-31'];
    $reports = app(ReportService::class);
    $before = [];
    foreach (['Daily Sales', 'Business Unit Performance'] as $name) {
        $before[$name] = $reports->generate($name, $filters);
    }
    $lines = DB::table('order_items')->where('company_id', $unit->company_id)->where('product_id', $unit->product_id)->orderBy('id')->get()->toJson();
    DB::beginTransaction();
    try {
        app(TransactionService::class)->run(fn () => app(BusinessUnitService::class)->product(['product_id' => $unit->product_id, 'business_unit_id' => $f['cases']['restaurant']['unit_id'], 'available' => false, 'prices' => [['product_unit_id' => $unit->id, 'selling_price' => '9999']]]));
        $checks = ['current_availability_disabled' => ! app(BusinessUnitService::class)->available($unit->product_id, $f['cases']['restaurant']['unit_id']), 'historical_lines_unchanged' => $lines === DB::table('order_items')->where('company_id', $unit->company_id)->where('product_id', $unit->product_id)->orderBy('id')->get()->toJson()];
        foreach ($before as $name => $data) {
            $checks[$name.'_unchanged'] = $data === $reports->generate($name, $filters);
        }
    } finally {
        DB::rollBack();
    }

    return $checks;
});
foreach ($baseline as $table => $hash) {
    $checks[$table.'_fingerprint_unchanged'] = hash_equals($hash, hash('sha256', json_encode(DB::table($table)->where('company_id', $f['company_id'])->orderBy('id')->get(), JSON_THROW_ON_ERROR)));
}
$pass = ! in_array(false, $checks, true);
echo json_encode(['result' => $pass ? 'PASS' : 'FAIL', 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($pass ? 0 : 1);
