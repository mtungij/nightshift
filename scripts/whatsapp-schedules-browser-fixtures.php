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
use App\Services\OrderService;
use App\Services\ShiftService;
use App\Services\StaffPinService;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\ScheduleService;
use App\Services\WhatsApp\SettingsService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Disposable browser database only');
}
Http::preventStrayRequests();
Queue::fake();
$baseline = [];
foreach (['orders', 'order_items', 'payments', 'payment_allocations', 'waiter_ledger_entries', 'stocks', 'stock_movements', 'treasury_entries', 'cash_handovers', 'cash_handover_events', 'shifts', 'shift_stock_snapshots', 'shift_stock_counts'] as $table) {
    $rows = DB::table($table)->orderBy('id')->get();
    $baseline[$table] = ['max_id' => $rows->max('id') ?? 0, 'hash' => hash('sha256', $rows->toJson())];
}
Artisan::call('migrate', ['--force' => true]);
$tag = strtolower((string) Str::ulid());
$owner = User::factory()->create(['name' => 'Scheduled Report Owner', 'username' => 'wa-owner-'.$tag]);
$result = DB::transaction(fn () => app(TenantContext::class)->run($owner, function () use ($owner, $tag) {
    app(StaffPinService::class)->set($owner->id, '0123');
    $owner->company->update(['name' => 'Nightshift Scheduled Reports Validation', 'timezone' => 'Africa/Dar_es_Salaam']);
    $branch = Branch::create(['name' => 'WhatsApp Main Branch']);
    $other = Branch::create(['name' => 'WhatsApp Other Branch']);
    $counter = StockLocation::create(['branch_id' => $branch->id, 'type' => 'COUNTER', 'name' => 'Overnight WhatsApp Counter']);
    $bar = app(BusinessUnitService::class)->save(['branch_id' => $branch->id, 'name' => 'Bar', 'code' => 'BAR', 'active' => true]);
    $rest = app(BusinessUnitService::class)->save(['branch_id' => $branch->id, 'name' => 'Restaurant', 'code' => 'RESTAURANT', 'active' => true]);
    $staff = [];
    foreach (['Cashier', 'Waiter', 'Manager', 'POS Terminal'] as $i => $role) {
        $u = User::factory()->create(['company_id' => $owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'name' => 'WhatsApp '.$role, 'username' => 'wa-'.$i.'-'.$tag, 'terminal_stock_location_id' => $role === 'POS Terminal' ? $counter->id : null]);
        $u->branches()->attach($branch->id, ['company_id' => $owner->company_id]);
        app(StaffPinService::class)->set($u->id, '0123');
        $staff[] = $u;
    }
    $product = Product::create(['category_id' => Category::create(['name' => 'WA goods'])->id, 'base_unit_id' => Unit::create(['name' => 'WA piece'])->id, 'name' => 'WA reconciliation product', 'sku' => 'WA-'.$tag, 'purchase_cost' => '1000', 'minimum_stock' => '3', 'active' => true]);
    $unit = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'conversion_quantity' => 1, 'selling_price' => '3000']);
    app(BusinessUnitService::class)->product(['product_id' => $product->id, 'business_unit_id' => $counter->business_unit_id, 'available' => true, 'prices' => []]);
    Carbon::setTestNow('2026-11-10 17:00:00');
    app(GoodsReceiptService::class)->post(['stock_location_id' => $counter->id, 'supplier_id' => Supplier::create(['name' => 'WA supplier'])->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $unit->id, 'quantity' => '10', 'unit_cost' => '1000']]]);
    Carbon::setTestNow('2026-11-10 18:00:00');
    $shift = app(ShiftService::class)->open(['user_id' => $staff[0]->id, 'stock_location_id' => $counter->id, 'type' => 'CASHIER']);
    app(ShiftService::class)->open(['user_id' => $staff[1]->id, 'stock_location_id' => $counter->id, 'type' => 'WAITER']);
    $invoices = [];
    foreach (['2026-11-10 23:30:00', '2026-11-11 02:30:00'] as $time) {
        Carbon::setTestNow($time);
        $order = app(OrderService::class)->create(['waiter_id' => $staff[1]->id, 'stock_location_id' => $counter->id, 'items' => [['product_unit_id' => $unit->id, 'quantity' => '1']]]);
        app(TenantContext::class)->run($staff[0], fn () => app(OrderService::class)->issue($order->id));
        $invoices[] = $order->invoice_number;
    }
    Carbon::setTestNow();
    $settings = app(SettingsService::class)->current();
    $settings->update(['device_id' => 'browser-validation-'.$owner->company_id, 'provisioned' => true, 'connection_status' => 'CONNECTED', 'options' => [...$settings->preferences(), 'enabled' => true, 'daily_report' => false, 'shift_report' => true, 'large_sale' => true, 'large_sale_threshold' => '1000']]);
    $recipient = app(RecipientService::class)->save(['name' => 'Owner James', 'phone' => '0712345678', 'label' => 'Owner', 'all_branches' => true, 'branch_ids' => [], 'all_business_units' => true, 'business_unit_ids' => [], 'daily_report' => true, 'shift_reports' => true, 'critical_alerts' => true, 'active' => true, 'enabled_types' => config('whatsapp.types'), 'language' => 'en']);
    $manager = app(RecipientService::class)->save(['name' => 'Manager Fatma', 'phone' => '0712345679', 'label' => 'Manager', 'all_branches' => false, 'branch_ids' => [$branch->id], 'all_business_units' => false, 'business_unit_ids' => [$counter->business_unit_id], 'daily_report' => true, 'shift_reports' => true, 'critical_alerts' => true, 'active' => true, 'enabled_types' => ['DAILY', 'SHIFT', 'LOW_STOCK'], 'language' => 'sw']);
    $schedule = app(ScheduleService::class)->save(['name' => 'Overnight owner daily', 'report_type' => 'DAILY', 'enabled' => true, 'send_time' => '06:00', 'timezone' => 'Africa/Dar_es_Salaam', 'cutoff_time' => '05:00', 'weekday' => 1, 'month_day' => 1, 'month_end' => false, 'grace_minutes' => 60, 'format' => 'TEXT_PDF', 'language' => 'en', 'scope' => 'COMPANY', 'branch_id' => null, 'business_unit_id' => null, 'stock_location_id' => null, 'per_branch' => false, 'unit_breakdown' => true, 'recipient_ids' => [$recipient->id, $manager->id]]);

    return ['owner' => $owner->username, 'owner_id' => $owner->id, 'company_id' => $owner->company_id, 'branch_id' => $branch->id, 'unit_id' => $counter->business_unit_id, 'other_branch_id' => $other->id, 'bar_id' => $bar->id, 'restaurant_id' => $rest->id, 'counter_id' => $counter->id, 'shift_id' => $shift->id, 'schedule_id' => $schedule->id, 'recipient_id' => $recipient->id, 'manager_recipient_id' => $manager->id, 'staff' => array_map(fn ($u) => ['username' => $u->username, 'id' => $u->id], $staff), 'invoices' => $invoices, 'expected_sales' => '6000.00', 'expected_stock' => '8.000000'];
}));
foreach ($baseline as $table => $before) {
    $hash = hash('sha256', DB::table($table)->where('id', '<=', $before['max_id'])->orderBy('id')->get()->toJson());
    if ($hash !== $before['hash']) {
        throw new RuntimeException('Existing history changed: '.$table);
    }
}
file_put_contents('/tmp/nightshift-whatsapp-schedules-fixtures.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents('/tmp/nightshift-whatsapp-schedules-baseline.json', json_encode($baseline, JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'PASS', 'company_id' => $result['company_id'], 'historical_tables_unchanged' => count($baseline)]).PHP_EOL;
