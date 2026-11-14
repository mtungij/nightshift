<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation') {
    throw new RuntimeException('Use only disposable browser validation database.');
}
$baseline = json_decode(file_get_contents('/tmp/nightshift-admin-ui-financial-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$fixture = json_decode(file_get_contents('/tmp/nightshift-business-unit-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = [];
foreach ($baseline as $table => $hash) {
    $checks[$table] = hash_equals($hash, hash('sha256', json_encode(DB::table($table)->where('company_id', $fixture['company_id'])->orderBy('id')->get(), JSON_THROW_ON_ERROR)));
}
$pass = ! in_array(false, $checks, true);
echo json_encode(['result' => $pass ? 'PASS' : 'FAIL', 'unchanged_tables' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
exit($pass ? 0 : 1);
