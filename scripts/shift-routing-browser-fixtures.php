<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Shift fixtures require temporary MySQL on port 3340.');
}
$app->make(Kernel::class)->call('db:seed', ['--class' => NightshiftDemoSeeder::class, '--force' => true]);
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($owner) {
    $main = StockLocation::where('name', 'Main Counter')->firstOrFail();
    $vip = StockLocation::where('name', 'VIP Counter')->firstOrFail();
    $branch = Branch::firstOrCreate(['name' => 'Unassigned shift validation branch']);
    $outside = StockLocation::firstOrCreate(['branch_id' => $branch->id, 'name' => 'Unassigned shift validation counter'], ['type' => 'COUNTER']);
    $accounts = [];
    foreach (['waiter' => 'Waiter', 'cashier' => 'Cashier', 'manager' => 'Manager', 'owner' => 'Company Admin / Owner'] as $slug => $role) {
        $u = User::firstOrCreate(['email' => $slug.'@shift-routing.test'], ['name' => 'Shift '.$slug, 'username' => 'shift-'.$slug, 'password' => Str::password(64)]);
        $u->forceFill(['company_id' => $owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'operational_pin' => Hash::make('0123'), 'active' => true, 'pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
        $u->branches()->sync([$main->branch_id => ['company_id' => $owner->company_id]]);
        $accounts[$slug] = ['id' => $u->id, 'username' => $u->username];
    }

    return ['accounts' => $accounts, 'main' => $main->id, 'vip' => $vip->id, 'outside' => $outside->id];
});
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
