<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BusinessUnitService;
use App\Services\GoodsReceiptService;
use App\Services\ShiftService;
use App\Services\StaffPinService;
use App\Services\StockTransferService;
use App\Services\TransactionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Use the separate disposable browser validation database on port 3340.');
}
$before = ['orders' => DB::table('orders')->count(), 'payments' => DB::table('payments')->count(), 'ledger' => DB::table('waiter_ledger_entries')->count(), 'treasury' => DB::table('treasury_entries')->count(), 'payment_total' => (string) DB::table('payments')->sum('amount')];
$app->make(Kernel::class)->call('migrate', ['--force' => true]);
$after = ['orders' => DB::table('orders')->count(), 'payments' => DB::table('payments')->count(), 'ledger' => DB::table('waiter_ledger_entries')->count(), 'treasury' => DB::table('treasury_entries')->count(), 'payment_total' => (string) DB::table('payments')->sum('amount')];
if ($before !== $after) {
    throw new RuntimeException('Legacy financial rows changed at cutover.');
}
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($owner, $before) {
    $service = app(BusinessUnitService::class);
    $branch = Branch::firstOrFail();
    $suffix = strtolower(Str::random(6));
    $result = ['owner' => $owner->username, 'company_id' => $owner->company_id, 'branch_id' => $branch->id, 'legacy_before' => $before, 'legacy_cutover_unchanged' => true, 'cases' => []];
    $units = [];
    foreach (['Bar', 'Restaurant'] as $name) {
        $unit = $service->save(['branch_id' => $branch->id, 'name' => $name.' '.$suffix, 'code' => strtoupper($name.'_'.$suffix), 'active' => true]);
        $units[strtolower($name)] = $unit;
    }
    $central = StockLocation::create(['branch_id' => $branch->id, 'name' => 'Shared central '.$suffix, 'type' => 'STORE']);
    $service->location(['id' => $central->id, 'unit_ids' => array_map(fn ($u) => $u->id, $units), 'is_shared' => true]);
    $p = Product::create(['category_id' => Category::firstOrFail()->id, 'base_unit_id' => Unit::firstOrFail()->id, 'name' => 'Shared Coca-Cola '.$suffix, 'sku' => 'BU-'.$suffix, 'purchase_cost' => '1000', 'minimum_stock' => '1', 'active' => true]);
    $selling = ProductUnit::create(['product_id' => $p->id, 'unit_id' => $p->base_unit_id, 'conversion_quantity' => '1', 'selling_price' => '2000']);
    app(GoodsReceiptService::class)->post(['stock_location_id' => $central->id, 'supplier_id' => Supplier::firstOrFail()->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $selling->id, 'quantity' => '100', 'unit_cost' => '1000']]]);
    foreach ($units as $kind => $unit) {
        $counter = StockLocation::create(['branch_id' => $branch->id, 'business_unit_id' => $unit->id, 'name' => ucfirst($kind).' POS '.$suffix, 'type' => 'COUNTER']);
        $result['cases'][$kind] = ['unit_id' => $unit->id, 'unit_name' => $unit->name, 'counter_id' => $counter->id, 'counter_name' => $counter->name];
        foreach (['cashier' => 'Cashier', 'waiter' => 'Waiter', 'terminal' => 'POS Terminal'] as $slug => $role) {
            $user = User::factory()->create(['company_id' => $owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'name' => ucfirst($kind).' '.$slug.' '.$suffix, 'username' => 'bu-'.$kind.'-'.$slug.'-'.$suffix, 'terminal_stock_location_id' => $role === 'POS Terminal' ? $counter->id : null]);
            $user->branches()->attach($branch->id, ['company_id' => $owner->company_id]);
            app(TransactionService::class)->run(fn () => $service->assign($user, [$unit->id]));
            app(StaffPinService::class)->set($user->id, '0123');
            if ($slug !== 'terminal') {
                app(ShiftService::class)->open(['user_id' => $user->id, 'stock_location_id' => $counter->id, 'type' => strtoupper($slug)]);
            }
            $result['cases'][$kind][$slug] = $user->username;
            $result['cases'][$kind][$slug.'_id'] = $user->id;
        }
        $transfer = app(StockTransferService::class)->create(['source_location_id' => $central->id, 'destination_location_id' => $counter->id, 'items' => [['product_unit_id' => $selling->id, 'quantity' => '10']]]);
        app(StockTransferService::class)->send($transfer->id);
        app(StockTransferService::class)->receive($transfer->id);
        app(TransactionService::class)->run(fn () => $service->product(['product_id' => $p->id, 'business_unit_id' => $unit->id, 'available' => true, 'prices' => [['product_unit_id' => $selling->id, 'selling_price' => $kind === 'restaurant' ? '2500' : null]]]));
    }
    foreach (['bar_manager' => [$units['bar']->id], 'multi_manager' => array_map(fn ($u) => $u->id, $units)] as $slug => $ids) {
        $user = User::factory()->create(['company_id' => $owner->company_id, 'role_id' => Role::where('name', 'Manager')->firstOrFail()->id, 'name' => $slug.' '.$suffix, 'username' => 'bu-'.$slug.'-'.$suffix]);
        $user->branches()->attach($branch->id, ['company_id' => $owner->company_id]);
        app(TransactionService::class)->run(fn () => $service->assign($user, $ids));
        app(StaffPinService::class)->set($user->id, '0123');
        $result[$slug] = $user->username;
    }

    return [...$result, 'central_id' => $central->id, 'product_id' => $p->id, 'selling_id' => $selling->id];
});
file_put_contents('/tmp/nightshift-business-unit-fixtures.json', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'PASS', 'legacy_cutover_unchanged' => true, 'units' => count($result['cases'])]).PHP_EOL;
