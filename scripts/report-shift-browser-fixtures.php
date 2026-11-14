<?php

use App\Domain\TenantContext;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BusinessUnitService;
use App\Services\CashHandoverService;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use App\Services\ShiftStockService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Disposable local database only.');
}
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$tables = ['orders', 'order_items', 'payments', 'payment_allocations', 'waiter_ledger_entries', 'stocks', 'stock_movements', 'treasury_entries', 'cash_handovers', 'cash_handover_events', 'shifts', 'shift_stock_snapshots', 'shift_stock_counts'];
$baseline = [];
foreach ($tables as $table) {
    $rows = DB::table($table)->where('company_id', $owner->company_id)->orderBy('id')->get();
    $baseline[$table] = ['max_id' => $rows->max('id') ?? 0, 'hash' => hash('sha256', $rows->toJson())];
}
$result = DB::transaction(fn () => app(TenantContext::class)->run($owner, function () use ($owner) {
    $tag = strtolower((string) Str::ulid());
    $counter = StockLocation::create(['branch_id' => 1, 'type' => 'COUNTER', 'name' => 'Report overnight '.$tag, 'max_active_cashiers' => 2]);
    $staff = [];
    foreach (['Cashier', 'Cashier', 'Waiter'] as $i => $role) {
        $u = User::factory()->create(['company_id' => $owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'name' => 'Report '.$role.' '.$i, 'username' => 'report-'.$i.'-'.$tag, 'operational_pin' => Hash::make('0123')]);
        $u->branches()->attach(1, ['company_id' => $owner->company_id]);
        $staff[] = $u;
    }
    $product = Product::create(['category_id' => Category::firstOrFail()->id, 'base_unit_id' => Unit::firstOrFail()->id, 'name' => 'Report reconciliation '.$tag, 'sku' => 'RPT-'.$tag, 'purchase_cost' => '100', 'minimum_stock' => 1, 'active' => true]);
    $unit = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'conversion_quantity' => 1, 'selling_price' => '1000']);
    app(BusinessUnitService::class)->product(['product_id' => $product->id, 'business_unit_id' => $counter->business_unit_id, 'available' => true, 'prices' => []]);
    Carbon::setTestNow('2026-11-09 17:00:00');
    app(GoodsReceiptService::class)->post(['stock_location_id' => $counter->id, 'supplier_id' => Supplier::firstOrFail()->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $unit->id, 'quantity' => '10', 'unit_cost' => '100']]]);
    Carbon::setTestNow('2026-11-09 18:00:00');
    $shifts = [];
    foreach ($staff as $i => $u) {
        $shifts[] = app(ShiftService::class)->open(['user_id' => $u->id, 'stock_location_id' => $counter->id, 'type' => $i === 2 ? 'WAITER' : 'CASHIER']);
    }
    $sale = function (int $cashier) use ($staff, $counter, $unit) {
        $order = app(OrderService::class)->create(['waiter_id' => $staff[2]->id, 'stock_location_id' => $counter->id, 'items' => [['product_unit_id' => $unit->id, 'quantity' => 1]]]);
        app(TenantContext::class)->run($staff[$cashier], function () use ($order) {
            app(OrderService::class)->issue($order->id);
            app(PaymentService::class)->receive($order->id, [['method' => 'Cash', 'amount' => '1000']]);
        });

        return $order->invoice_number;
    };
    Carbon::setTestNow('2026-11-09 23:30:00');
    $invoices = [$sale(0)];
    Carbon::setTestNow('2026-11-10 02:30:00');
    $other = $sale(1);
    Carbon::setTestNow('2026-11-10 02:45:00');
    $invoices[] = $sale(0);
    Carbon::setTestNow('2026-11-10 03:10:00');
    app(TenantContext::class)->run($staff[2], fn () => app(ShiftService::class)->close($shifts[2]->id, []));
    foreach ([0, 1] as $i) {
        Carbon::setTestNow('2026-11-10 03:'.($i === 0 ? '20' : '25').':00');
        $h = app(TenantContext::class)->run($staff[$i], function () use ($shifts, $i, $owner) {
            $service = app(ShiftStockService::class);
            $r = $service->report($shifts[$i]->id);
            $service->count($shifts[$i]->id, ['actual_cash' => $i === 0 ? '2000' : '1000', 'basis' => $r['basis'], 'revision' => $r['revision'], 'counts' => array_map(fn ($row) => ['product_id' => $row['product_id'], 'quantity' => $row['expected']], $r['rows'])]);

            return app(CashHandoverService::class)->stage($shifts[$i]->id, ['destination' => 'TREASURY', 'to_user_id' => $owner->id, 'amount' => $i === 0 ? '2000' : '1000']);
        });
        app(CashHandoverService::class)->receive($h->id, $h->amount);
        app(TenantContext::class)->run($staff[$i], fn () => app(ShiftService::class)->close($shifts[$i]->id, ['actual_cash' => $i === 0 ? '2000' : '1000']));
    }
    Carbon::setTestNow();
    $live = app(ShiftService::class)->open(['user_id' => $staff[0]->id, 'stock_location_id' => $counter->id, 'type' => 'CASHIER']);
    app(ShiftService::class)->open(['user_id' => $staff[2]->id, 'stock_location_id' => $counter->id, 'type' => 'WAITER']);
    $liveInvoice = $sale(0);

    return ['owner' => $owner->username, 'company_id' => $owner->company_id, 'branch_id' => 1, 'unit_id' => $counter->business_unit_id, 'counter_id' => $counter->id, 'shift_id' => $shifts[0]->id, 'shift_number' => $shifts[0]->number, 'other_shift_id' => $shifts[1]->id, 'waiter_shift_id' => $shifts[2]->id, 'live_shift_id' => $live->id, 'invoices' => $invoices, 'other_invoice' => $other, 'live_invoice' => $liveInvoice, 'product_id' => $product->id, 'expected_sales' => '2000.00', 'expected_stock' => '6.000000'];
}));
foreach ($baseline as $table => $before) {
    $hash = hash('sha256', DB::table($table)->where('company_id', $owner->company_id)->where('id', '<=', $before['max_id'])->orderBy('id')->get()->toJson());
    if ($hash !== $before['hash']) {
        throw new RuntimeException('Existing history changed: '.$table);
    }
}
file_put_contents('/tmp/nightshift-report-shift-fixtures.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents('/tmp/nightshift-report-shift-baseline.json', json_encode($baseline, JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'PASS', 'old_tables_unchanged' => count($baseline), 'closed' => $result['shift_id'], 'live' => $result['live_shift_id']]).PHP_EOL;
