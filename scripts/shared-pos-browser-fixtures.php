<?php

use App\Domain\TenantContext;
use App\Models\User;
use App\Services\StaffPinService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Shared POS fixtures require the isolated MySQL validation database on port 3340.');
}
$app->make(Kernel::class)->call('migrate', ['--force' => true]);
$app->make(Kernel::class)->call('db:seed', ['--class' => NightshiftDemoSeeder::class, '--force' => true]);
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$accounts = app(TenantContext::class)->run($owner, function () {
    $accounts = [];
    foreach (['admin' => '0123', 'manager' => '0123', 'cashier' => '4567', 'asha' => '0123', 'james' => '6789'] as $slug => $pin) {
        $user = User::where('email', $slug.'@nightshift.test')->firstOrFail();
        app(StaffPinService::class)->set($user->id, $pin);
        $accounts[$slug] = ['id' => $user->id, 'username' => $user->username];
    }

    return $accounts;
});
echo json_encode($accounts, JSON_THROW_ON_ERROR)."\n";
