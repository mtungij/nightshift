<?php

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\BusinessUnitService;
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
$path = '/tmp/nightshift-pos-admin-fixtures.json';
if (in_array('--add-inactive-counter', $_SERVER['argv'] ?? [], true)) {
    $f = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    DB::transaction(fn () => app(TenantContext::class)->run(User::findOrFail($f['owner_id']), function () use ($f) {
        $unit = BusinessUnit::where('branch_id', $f['no_units_branch_id'])->firstOrFail();
        if (! StockLocation::where('branch_id', $f['no_units_branch_id'])->exists()) {
            $unit->update(['active' => true]);
            StockLocation::create(['branch_id' => $unit->branch_id, 'business_unit_id' => $unit->id, 'name' => 'Counter of inactive unit', 'type' => 'COUNTER', 'active' => true]);
            $unit->update(['active' => false]);
        }
    }));
    echo "PASS: inactive-unit counter metadata prepared.\n";
    exit;
}
if (in_array('--restore', $_SERVER['argv'] ?? [], true)) {
    $f = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    app(TenantContext::class)->run(User::findOrFail($f['owner_id']), function () use ($f) {
        if ($f['restore']['staff_operations']) {
            Role::findOrFail($f['restore']['role_id'])->permissions()->syncWithoutDetaching([Permission::where('name', 'view-staff-operations')->value('id')]);
        }
        $owner = User::findOrFail($f['owner_id']);
        $owner->businessUnits()->sync(array_fill_keys($f['restore']['unit_ids'], ['company_id' => $owner->company_id]));
        $owner->forceFill(['business_units_configured' => $f['restore']['configured']])->save();
    });
    echo "PASS: disposable owner role and assignments restored.\n";
    exit;
}
$f = json_decode(file_get_contents('/tmp/nightshift-pos-context-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = User::findOrFail($f['owner_id']);
$result = app(TenantContext::class)->run($owner, function () use ($f, $owner) {
    $restore = ['role_id' => $owner->role_id, 'staff_operations' => $owner->hasPermission('view-staff-operations'), 'unit_ids' => $owner->businessUnits()->pluck('business_units.id')->all(), 'configured' => $owner->business_units_configured];
    $owner->role->permissions()->detach(Permission::where('name', 'view-staff-operations')->value('id'));
    $owner->businessUnits()->sync([$f['bar']['unit_id'] => ['company_id' => $owner->company_id]]);
    $owner->forceFill(['business_units_configured' => true])->save();
    $tag = strtolower((string) Str::ulid());
    $service = app(BusinessUnitService::class);
    $noProducts = $service->save(['branch_id' => $f['branch_id'], 'name' => 'No Products '.$tag, 'code' => 'NP-'.substr($tag, -6), 'active' => true]);
    $counter = StockLocation::create(['branch_id' => $f['branch_id'], 'business_unit_id' => $noProducts->id, 'name' => 'Empty Catalogue Counter', 'type' => 'COUNTER']);
    $branch = Branch::create(['name' => 'No Active Units '.$tag]);
    BusinessUnit::where('branch_id', $branch->id)->update(['active' => false]);
    $inactive = $service->save(['branch_id' => $f['branch_id'], 'name' => 'Inactive Admin Unit', 'code' => 'IA-'.substr($tag, -6), 'active' => false]);
    $accounts = [];
    foreach (['owner_no_shift' => 'Company Admin / Owner', 'super_admin' => 'Super Admin'] as $key => $role) {
        $user = User::factory()->create(['company_id' => $owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'name' => $key.' '.$tag, 'username' => 'pos-admin-'.$key.'-'.$tag]);
        app(StaffPinService::class)->set($user->id, '0123');
        $accounts[$key] = $user->username;
    }
    $foreign = DB::table('business_units')->where('company_id', '!=', $owner->company_id)->value('id');
    $options = BusinessUnit::where('branch_id', $f['branch_id'])->where('active', true)->orderBy('id')->pluck('id')->all();

    return [...$f, ...$accounts, 'restore' => $restore, 'no_products_unit_id' => $noProducts->id, 'no_products_counter_id' => $counter->id, 'no_units_branch_id' => $branch->id, 'no_units_branch_name' => $branch->name, 'inactive_unit_id' => $inactive->id, 'foreign_unit_id' => $foreign, 'expected_options' => $options];
});
file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode(['result' => 'PASS', 'branch_unit_options' => count($result['expected_options']), 'legacy_permission_case' => true]).PHP_EOL;
