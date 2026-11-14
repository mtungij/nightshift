<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use App\Services\StaffPinService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Treasury fixtures require their separate disposable MySQL database on port 3340.');
}
$app->make(Kernel::class)->call('migrate', ['--force' => true]);
$app->make(Kernel::class)->call('db:seed', ['--class' => NightshiftDemoSeeder::class, '--force' => true]);
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($owner) {
    app(StaffPinService::class)->set($owner->id, '0123');
    $branch = Branch::firstOrFail();
    $result = ['owner' => $owner->username, 'owner_id' => $owner->id, 'branch_id' => $branch->id, 'cases' => []];
    foreach (['desktop', 'mobile'] as $kind) {
        $suffix = strtolower(Str::random(8));
        $counter = StockLocation::create(['branch_id' => $branch->id, 'name' => 'Treasury '.$kind.' '.$suffix, 'type' => 'COUNTER']);
        $users = [];
        foreach (['cashier' => 'Cashier', 'next' => 'Cashier', 'accountant' => 'Accountant', 'waiter' => 'Waiter'] as $slug => $role) {
            $user = new User;
            $user->forceFill(['company_id' => $owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'name' => 'Treasury '.$slug.' '.$suffix, 'username' => 'treasury-'.$slug.'-'.$suffix, 'email' => $slug.'-'.$suffix.'@treasury.test', 'password' => Str::password(64), 'active' => true])->save();
            $user->branches()->attach($branch->id, ['company_id' => $owner->company_id]);
            app(StaffPinService::class)->set($user->id, '0123');
            $users[$slug] = $user;
        }
        $product = Product::create(['category_id' => Category::firstOrFail()->id, 'base_unit_id' => Unit::firstOrFail()->id, 'name' => 'Treasury validation '.$suffix, 'sku' => 'TREASURY-'.$suffix, 'purchase_cost' => '100000', 'minimum_stock' => '0']);
        $unit = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'conversion_quantity' => '1', 'selling_price' => '650000']);
        $grn = app(GoodsReceiptService::class)->post(['stock_location_id' => $counter->id, 'supplier_id' => Supplier::firstOrFail()->id, 'supplier_invoice' => 'TREASURY-'.$suffix, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $unit->id, 'quantity' => '2', 'unit_cost' => '100000']]]);
        $shift = app(ShiftService::class)->open(['user_id' => $users['cashier']->id, 'stock_location_id' => $counter->id, 'type' => 'CASHIER']);
        $waiterShift = app(TenantContext::class)->run($users['waiter'], fn () => app(ShiftService::class)->open(['stock_location_id' => $counter->id, 'type' => 'WAITER']));
        $sent = app(TenantContext::class)->run($users['waiter'], fn () => app(OrderService::class)->send(['waiter_id' => $users['waiter']->id, 'pin' => '0123', 'stock_location_id' => $counter->id, 'discount' => '0', 'items' => [['product_unit_id' => $unit->id, 'quantity' => '1']]], (string) Str::uuid()));
        app(TenantContext::class)->run($users['cashier'], fn () => app(PaymentService::class)->receive($sent['id'], [['method' => 'Cash', 'amount' => '650000']]));
        app(TenantContext::class)->run($users['waiter'], fn () => app(ShiftService::class)->close($waiterShift->id, []));
        $result['cases'][$kind] = ['shift_id' => $shift->id, 'cashier' => $users['cashier']->username, 'cashier_id' => $users['cashier']->id, 'next' => $users['next']->username, 'next_id' => $users['next']->id, 'accountant' => $users['accountant']->username, 'accountant_id' => $users['accountant']->id, 'counter_id' => $counter->id, 'product_id' => $product->id, 'grn_id' => $grn->id, 'order_id' => $sent['id']];
    }
    $result['before'] = ['orders' => Order::count(), 'payments' => Payment::count(), 'ledger' => WaiterLedgerEntry::count(), 'movements' => StockMovement::count(), 'payment_total' => (string) Payment::sum('amount'), 'waiter_debits' => (string) WaiterLedgerEntry::sum('debit'), 'waiter_credits' => (string) WaiterLedgerEntry::sum('credit')];

    return $result;
});
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
