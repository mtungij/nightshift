<?php

use App\Domain\TenantContext;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\ShiftService;
use App\Services\StaffPinService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Reconciliation fixtures require the isolated validation database on port 3340.');
}
$app->make(Kernel::class)->call('migrate', ['--force' => true]);
$app->make(Kernel::class)->call('db:seed', ['--class' => NightshiftDemoSeeder::class, '--force' => true]);
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($owner) {
    app(StaffPinService::class)->set($owner->id, '0123');
    $branch = StockLocation::where('name', 'Main Counter')->firstOrFail()->branch_id;
    $unit = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', 'Piece'))->firstOrFail();
    $fixtures = ['owner' => $owner->username, 'cases' => []];
    foreach (['desktop', 'mobile'] as $kind) {
        $suffix = strtolower(Str::random(8));
        $counter = StockLocation::create(['branch_id' => $branch, 'name' => 'Reconciliation '.$kind.' '.$suffix, 'type' => 'COUNTER']);
        $users = [];
        foreach (['cashier' => 'Cashier', 'waiter' => 'Waiter'] as $slug => $role) {
            $user = new User;
            $user->forceFill(['company_id' => $owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'name' => 'Reconciliation '.$slug.' '.$suffix, 'username' => 'recon-'.$slug.'-'.$suffix, 'email' => $slug.'-'.$suffix.'@recon.test', 'password' => Str::password(64), 'active' => true])->save();
            $user->branches()->attach($branch, ['company_id' => $owner->company_id]);
            app(StaffPinService::class)->set($user->id, '0123');
            $users[$slug] = $user;
        }
        app(GoodsReceiptService::class)->post(['stock_location_id' => $counter->id, 'supplier_id' => Supplier::firstOrFail()->id, 'supplier_invoice' => 'recon-'.$suffix, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $unit->id, 'quantity' => '48', 'unit_cost' => '1200']]]);
        $shift = app(ShiftService::class)->open(['user_id' => $users['cashier']->id, 'stock_location_id' => $counter->id, 'type' => 'CASHIER', 'opening_cash' => '1000']);
        app(ShiftService::class)->open(['user_id' => $users['waiter']->id, 'stock_location_id' => $counter->id, 'type' => 'WAITER']);
        app(TenantContext::class)->run($users['cashier'], function () use ($counter, $users, $unit) {
            $order = app(OrderService::class)->create(['stock_location_id' => $counter->id, 'waiter_id' => $users['waiter']->id, 'items' => [['product_unit_id' => $unit->id, 'quantity' => '6']]]);
            app(OrderService::class)->issue($order->id);
        });
        $fixtures['cases'][$kind] = ['shift_id' => $shift->id, 'cashier' => $users['cashier']->username, 'product_id' => $unit->product_id, 'counter_id' => $counter->id];
    }
    $fixtures['before'] = ['movements' => DB::table('stock_movements')->count(), 'ledger' => DB::table('waiter_ledger_entries')->count(), 'payments' => DB::table('payments')->count()];

    return $fixtures;
});
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
