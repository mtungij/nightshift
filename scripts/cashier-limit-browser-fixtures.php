<?php

use App\Domain\TenantContext;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\BusinessUnitService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Use only disposable browser validation database.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-business-unit-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$owner = User::where('company_id', $f['company_id'])->where('username', $f['owner'])->firstOrFail();
$baseline = [];
foreach (['orders', 'order_items', 'payments', 'payment_allocations', 'waiter_ledger_entries', 'stocks', 'stock_movements', 'treasury_entries', 'cash_handovers', 'cash_handover_events', 'shifts', 'shift_stock_snapshots', 'shift_stock_counts'] as $table) {
    $rows = DB::table($table)->where('company_id', $f['company_id'])->orderBy('id')->get();
    $baseline[$table] = ['max_id' => $rows->max('id') ?? 0, 'hash' => hash('sha256', $rows->toJson())];
}
$result = DB::transaction(fn () => app(TenantContext::class)->run($owner, function () use ($owner, $f) {
    $tag = strtolower((string) Str::ulid());
    $counter = StockLocation::create(['branch_id' => $f['branch_id'], 'name' => 'Cashier capacity '.$tag, 'type' => 'COUNTER']);
    $store = StockLocation::create(['branch_id' => $f['branch_id'], 'name' => 'Capacity store '.$tag, 'type' => 'STORE']);
    $cashiers = [];
    foreach (range(1, 3) as $i) {
        $u = User::factory()->create(['company_id' => $owner->company_id, 'role_id' => Role::where('name', 'Cashier')->firstOrFail()->id, 'name' => 'Capacity cashier '.$i, 'username' => 'capacity-'.$i.'-'.$tag, 'operational_pin' => Hash::make('0123'), 'business_units_configured' => true, 'counters_configured' => true]);
        $u->branches()->attach($counter->branch_id, ['company_id' => $u->company_id]);
        $u->businessUnits()->attach($counter->business_unit_id, ['company_id' => $u->company_id]);
        app(BusinessUnitService::class)->counters($u, [$counter->id]);
        $cashiers[] = ['id' => $u->id, 'username' => $u->username];
    }

    return ['owner' => $owner->username, 'company_id' => $owner->company_id, 'branch_id' => $counter->branch_id, 'unit_id' => $counter->business_unit_id, 'counter_id' => $counter->id, 'counter_name' => $counter->name, 'store_id' => $store->id, 'cashiers' => $cashiers];
}));
file_put_contents('/tmp/nightshift-cashier-limit-fixtures.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents('/tmp/nightshift-cashier-limit-baseline.json', json_encode($baseline, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'PASS', 'counter_id' => $result['counter_id'], 'max_active_cashiers' => 1, 'financial_tables' => count($baseline)], JSON_THROW_ON_ERROR).PHP_EOL;
