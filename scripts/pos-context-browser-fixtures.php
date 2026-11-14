<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BusinessUnitService;
use App\Services\GoodsReceiptService;
use App\Services\SalesTicketService;
use App\Services\StaffPinService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Disposable browser database only.');
}
Http::preventStrayRequests();
Queue::fake();
$base = json_decode(file_get_contents('/tmp/nightshift-business-unit-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = User::findOrFail($base['company_id'] === 1 ? 1 : 0);
$baseline = [];
foreach (['orders', 'order_items', 'payments', 'payment_allocations', 'waiter_ledger_entries', 'stocks', 'stock_movements', 'treasury_entries', 'cash_handovers', 'cash_handover_events', 'shifts', 'shift_stock_snapshots', 'shift_stock_counts'] as $table) {
    $rows = DB::table($table)->orderBy('id')->get();
    $baseline[$table] = ['max_id' => $rows->max('id') ?? 0, 'hash' => hash('sha256', $rows->toJson())];
}
$result = app(TenantContext::class)->run($owner, function () use ($base, $owner, $baseline) {
    $service = app(BusinessUnitService::class);
    $tag = strtolower((string) Str::ulid());
    $bar = $base['cases']['bar'];
    $restaurant = $base['cases']['restaurant'];
    foreach ([$owner->id, $bar['waiter_id'], $bar['cashier_id'], $bar['terminal_id']] as $id) {
        app(StaffPinService::class)->set($id, '0123');
    }
    $second = StockLocation::create(['branch_id' => $base['branch_id'], 'business_unit_id' => $bar['unit_id'], 'name' => 'Second Bar Counter '.$tag, 'type' => 'COUNTER']);
    $empty = $service->save(['branch_id' => $base['branch_id'], 'name' => 'Empty POS '.$tag, 'code' => 'EMPTY-'.substr($tag, -6), 'active' => true]);
    $branch = Branch::create(['name' => 'POS Annex '.$tag]);
    $category = Category::create(['name' => 'POS context '.$tag]);
    $unit = Unit::create(['name' => 'POS item '.$tag]);
    $products = [];
    foreach (['bar' => '3000', 'restaurant' => '4000', 'shared' => '2000'] as $kind => $price) {
        $p = Product::create(['category_id' => $category->id, 'base_unit_id' => $unit->id, 'name' => 'POS '.$kind.' only '.$tag, 'sku' => 'POS-'.$kind.'-'.$tag, 'purchase_cost' => '1000', 'minimum_stock' => '1', 'active' => true]);
        $selling = ProductUnit::create(['product_id' => $p->id, 'unit_id' => $unit->id, 'conversion_quantity' => '1', 'selling_price' => $price]);
        foreach (['bar' => $bar, 'restaurant' => $restaurant] as $area => $context) {
            $service->product(['product_id' => $p->id, 'business_unit_id' => $context['unit_id'], 'available' => $kind === 'shared' || $kind === $area, 'prices' => $kind === 'bar' && $area === 'bar' ? [['product_unit_id' => $selling->id, 'selling_price' => '5000']] : []]);
        }
        $products[$kind] = ['id' => $p->id, 'selling_id' => $selling->id, 'name' => $p->name];
    }
    $supplier = Supplier::create(['name' => 'POS fixture supplier '.$tag]);
    foreach ([$bar['counter_id'] => ['bar', 'shared'], $second->id => ['bar', 'shared'], $restaurant['counter_id'] => ['restaurant', 'shared']] as $counter => $kinds) {
        app(GoodsReceiptService::class)->post(['stock_location_id' => $counter, 'supplier_id' => $supplier->id, 'received_on' => now()->toDateString(), 'items' => array_map(fn ($kind) => ['product_unit_id' => $products[$kind]['selling_id'], 'quantity' => '10', 'unit_cost' => '1000'], $kinds)]);
    }
    app(SalesTicketService::class)->savePreferences(['auto_print' => false, 'waiter_copy' => true, 'cashier_copy' => true, 'number_of_copies' => 2, 'paper_width' => '58mm']);

    return ['owner' => $owner->username, 'owner_id' => $owner->id, 'company_id' => $owner->company_id, 'branch_id' => $base['branch_id'], 'bar' => $bar, 'restaurant' => $restaurant, 'manager' => $base['bar_manager'], 'second_counter_id' => $second->id, 'empty_unit_id' => $empty->id, 'annex_branch_id' => $branch->id, 'products' => $products, 'baseline' => $baseline];
});
file_put_contents('/tmp/nightshift-pos-context-fixtures.json', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'PASS', 'products' => count($result['products']), 'history_tables' => count($baseline)]).PHP_EOL;
