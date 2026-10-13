<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Staff browser fixtures require the temporary MySQL database on port 3340.');
}
$app->make(Kernel::class)->call('db:seed', ['--class' => NightshiftDemoSeeder::class, '--force' => true]);
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$fixtures = app(TenantContext::class)->run($owner, function () use ($owner) {
    $main = StockLocation::where('name', 'Main Counter')->firstOrFail();
    $vip = StockLocation::where('name', 'VIP Counter')->firstOrFail();
    $privateBranch = Branch::firstOrCreate(['name' => 'Private validation branch']);
    $privateCounter = StockLocation::firstOrCreate(['name' => 'Private validation counter', 'branch_id' => $privateBranch->id], ['type' => 'COUNTER']);
    $users = [];
    foreach (['owner' => 'Company Admin / Owner', 'manager' => 'Manager', 'supervisor' => 'Supervisor', 'cashier-a' => 'Cashier', 'cashier-b' => 'Cashier', 'waiter-a' => 'Waiter', 'waiter-b' => 'Waiter', 'waiter-vip' => 'Waiter', 'cashier-private' => 'Cashier'] as $slug => $role) {
        $u = User::firstOrCreate(['email' => $slug.'@staff-scope.test'], ['name' => 'Scope '.$slug, 'username' => 'scope-'.$slug, 'password' => Str::password(64)]);
        $u->forceFill(['company_id' => $owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'active' => true, 'operational_pin' => Hash::make('0123'), 'pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
        $branchId = $slug === 'cashier-private' ? $privateBranch->id : $main->branch_id;
        $u->branches()->sync([$branchId => ['company_id' => $owner->company_id]]);
        $users[$slug] = $u;
    }
    $shifts = [];
    foreach (['owner', 'cashier-a', 'cashier-b', 'waiter-a', 'waiter-b', 'waiter-vip', 'cashier-private'] as $slug) {
        $counter = $slug === 'waiter-vip' ? $vip : ($slug === 'cashier-private' ? $privateCounter : $main);
        $type = str_starts_with($slug, 'waiter') ? 'WAITER' : 'CASHIER';
        $shift = Shift::where('user_id', $users[$slug]->id)->where('status', 'OPEN')->first();
        $shifts[$slug] = ($shift ?? app(ShiftService::class)->open(['user_id' => $users[$slug]->id, 'stock_location_id' => $counter->id, 'type' => $type, 'opening_cash' => $type === 'CASHIER' ? '10000' : '0']))->id;
    }
    $unit = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->firstOrFail();
    $orders = [];
    foreach (['waiter-a', 'waiter-b', 'waiter-vip'] as $slug) {
        $orders[$slug] = app(OrderService::class)->send(['waiter_id' => $users[$slug]->id, 'pin' => '0123', 'stock_location_id' => $slug === 'waiter-vip' ? $vip->id : $main->id, 'discount' => '0', 'items' => [['product_unit_id' => $unit->id, 'quantity' => '3']]], (string) Str::uuid())['id'];
    }
    foreach (['a', 'b'] as $suffix) {
        app(TenantContext::class)->run($users['cashier-'.$suffix], fn () => app(PaymentService::class)->receiveWaiter($users['waiter-'.$suffix]->id, $main->id, '100', 'Cash'));
    }

    return ['accounts' => array_map(fn ($u) => ['id' => $u->id, 'username' => $u->username, 'name' => $u->name], $users), 'shifts' => $shifts, 'orders' => $orders, 'main' => $main->id, 'vip' => $vip->id, 'private_branch' => $privateBranch->id, 'unit' => $unit->id];
});
$foreignCompany = Company::firstOrCreate(['name' => 'Staff scope foreign company']);
$foreign = User::firstOrCreate(['email' => 'foreign@staff-scope.test'], ['name' => 'Scope foreign owner', 'username' => 'scope-foreign-owner', 'password' => Str::password(64)]);
$foreign->forceFill(['company_id' => $foreignCompany->id, 'role_id' => $owner->role_id, 'operational_pin' => Hash::make('0123'), 'active' => true])->save();
$fixtures['foreign_shift'] = app(TenantContext::class)->run($foreign, function () use ($foreign) {
    $branch = Branch::firstOrCreate(['name' => 'Foreign validation branch']);
    $foreign->branches()->syncWithoutDetaching([$branch->id => ['company_id' => $foreign->company_id]]);
    $counter = StockLocation::firstOrCreate(['name' => 'Foreign validation counter', 'branch_id' => $branch->id], ['type' => 'COUNTER']);

    $existing = Shift::where('user_id', $foreign->id)->where('status', 'OPEN')->first();

    return ($existing ?? app(ShiftService::class)->open(['user_id' => $foreign->id, 'stock_location_id' => $counter->id, 'type' => 'CASHIER', 'opening_cash' => '765432']))->id;
});
echo json_encode($fixtures, JSON_THROW_ON_ERROR)."\n";
