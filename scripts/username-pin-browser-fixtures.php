<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
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
    throw new RuntimeException('PIN browser fixtures require the temporary MySQL instance on port 3340.');
}
$app->make(Kernel::class)->call('db:seed', ['--class' => NightshiftDemoSeeder::class, '--force' => true]);
$admin = User::where('email', 'admin@nightshift.test')->firstOrFail();
$accounts = app(TenantContext::class)->run($admin, function () use ($admin) {
    $branch = Branch::firstOrFail();
    $accounts = [];
    foreach (['owner' => 'Company Admin / Owner', 'manager' => 'Manager', 'supervisor' => 'Supervisor', 'storekeeper' => 'Store Keeper', 'cashier' => 'Cashier', 'waiter' => 'Waiter', 'superadmin' => 'Super Admin'] as $slug => $role) {
        $user = User::firstOrCreate(['email' => $slug.'@username-pin.test'], ['name' => 'PIN '.$slug, 'username' => 'pin-'.$slug, 'password' => Str::password(64)]);
        $user->forceFill(['company_id' => $admin->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'active' => true, 'operational_pin' => Hash::make('0123'), 'pin_failed_attempts' => 0, 'pin_locked_until' => null, 'email_verified_at' => null])->save();
        $user->branches()->syncWithoutDetaching([$branch->id => ['company_id' => $admin->company_id]]);
        $accounts[$slug] = ['id' => $user->id, 'username' => $user->username, 'role' => $role];
    }

    return $accounts;
});
$foreignCompany = Company::firstOrCreate(['name' => 'PIN foreign company']);
$foreign = User::firstOrCreate(['email' => 'foreign@username-pin.test'], ['name' => 'PIN foreign owner', 'username' => 'pin-foreign-owner', 'password' => Str::password(64)]);
$foreign->forceFill(['company_id' => $foreignCompany->id, 'role_id' => $admin->role_id, 'active' => true, 'operational_pin' => Hash::make('0123'), 'pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
$foreignBranch = app(TenantContext::class)->run($foreign, fn () => Branch::firstOrCreate(['name' => 'PIN foreign branch']));
$foreign->branches()->syncWithoutDetaching([$foreignBranch->id => ['company_id' => $foreignCompany->id]]);
$inactive = User::firstOrCreate(['email' => 'inactive@username-pin.test'], ['name' => 'PIN inactive user', 'username' => 'pin-inactive', 'password' => Str::password(64)]);
$inactive->forceFill(['company_id' => $admin->company_id, 'role_id' => $admin->role_id, 'active' => false, 'operational_pin' => Hash::make('0123')])->save();
$lockout = User::firstOrCreate(['email' => 'lockout@username-pin.test'], ['name' => 'PIN lockout account', 'username' => 'pin-lockout', 'password' => Str::password(64)]);
$lockout->forceFill(['company_id' => $admin->company_id, 'role_id' => $admin->role_id, 'active' => true, 'operational_pin' => Hash::make('0123'), 'pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
echo json_encode(['accounts' => $accounts, 'foreign' => ['company_id' => $foreignCompany->id, 'username' => $foreign->username], 'inactive' => $inactive->username, 'lockout' => $lockout->username], JSON_THROW_ON_ERROR)."\n";
