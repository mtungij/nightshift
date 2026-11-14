<?php

use App\Domain\TenantContext;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\BusinessUnitService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Disposable browser database only.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-pos-admin-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = User::whereKey($f['owner_id'])->firstOrFail();
app(TenantContext::class)->run($owner, function () use (&$f) {
    $unit = BusinessUnit::where('branch_id', $f['branch_id'])->where('code', 'SIMPLE_BROWSER')->first() ?? app(BusinessUnitService::class)->save(['branch_id' => $f['branch_id'], 'name' => 'Simple operational unit', 'code' => 'SIMPLE_BROWSER', 'active' => true]);
    app(BusinessUnitService::class)->product(['product_id' => $f['products']['bar']['id'], 'business_unit_id' => $unit->id, 'available' => true, 'prices' => []]);
    $f['simple_unit_id'] = $unit->id;
});
file_put_contents('/tmp/nightshift-unit-operations-fixtures.json', json_encode($f, JSON_THROW_ON_ERROR));
echo "PASS: simple-mode browser configuration prepared without stock or financial writes.\n";
