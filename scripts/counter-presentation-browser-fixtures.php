<?php

use App\Domain\TenantContext;
use App\Models\StockLocation;
use App\Services\BusinessUnitService;

// Reuse the existing isolated, guarded cashier fixture setup and immutable-row baseline.
require __DIR__.'/cashier-limit-browser-fixtures.php';
$shared = app(TenantContext::class)->run($owner, function () use ($result) {
    $store = StockLocation::create(['branch_id' => $result['branch_id'], 'type' => 'STORE', 'name' => 'Shared presentation store']);
    app(BusinessUnitService::class)->location(['id' => $store->id, 'is_shared' => true, 'unit_ids' => [$result['unit_id']]]);

    return $store->id;
});
$result['shared_store_id'] = $shared;
file_put_contents('/tmp/nightshift-counter-binding-fixtures.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
copy('/tmp/nightshift-cashier-limit-baseline.json', '/tmp/nightshift-counter-binding-baseline.json');
