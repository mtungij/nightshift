<?php

$whatsapp = require __DIR__.'/whatsapp.php';

return [
    'demo_bulk_prices' => ['BR-001' => ['bucket' => '16000', 'crate' => '62000']],
    'demo_password' => env('NIGHTSHIFT_DEMO_PASSWORD', 'Nightshift!2026'),
    'permissions' => [...$whatsapp['permissions'], 'view-staff-operations', 'view-dashboard', 'create-orders', 'view-orders', 'issue-drinks', 'receive-payments', 'view-waiter-balances', 'manage-inventory', 'receive-stock', 'transfer-stock', 'adjust-stock', 'approve-adjustments', 'request-void', 'approve-void', 'open-shifts', 'close-shifts', 'override-shifts', 'view-reports', 'manage-users', 'manage-products', 'manage-branches', 'manage-counters', 'view-audit', 'all-branches', 'manage-company', 'return-items'],
    'roles' => [
        'Super Admin' => ['*'], 'Company Admin / Owner' => ['*'],
        'Manager' => [...$whatsapp['manager_permissions'], 'view-staff-operations', 'view-dashboard', 'create-orders', 'view-orders', 'issue-drinks', 'receive-payments', 'view-waiter-balances', 'manage-inventory', 'receive-stock', 'transfer-stock', 'adjust-stock', 'approve-adjustments', 'request-void', 'approve-void', 'open-shifts', 'close-shifts', 'override-shifts', 'view-reports', 'manage-users', 'manage-products', 'manage-branches', 'manage-counters', 'view-audit', 'manage-company', 'return-items'],
        'Supervisor' => ['view-staff-operations', 'view-dashboard', 'view-orders', 'view-waiter-balances', 'manage-inventory', 'view-reports', 'view-audit', 'open-shifts', 'close-shifts', 'request-void'],
        'Store Keeper' => ['manage-inventory', 'receive-stock', 'transfer-stock', 'adjust-stock', 'manage-products', 'view-reports', 'open-shifts', 'close-shifts'],
        'Cashier' => ['view-dashboard', 'create-orders', 'view-orders', 'issue-drinks', 'receive-payments', 'view-waiter-balances', 'request-void', 'open-shifts', 'close-shifts', 'return-items'],
        'Waiter' => ['create-orders', 'view-orders', 'view-waiter-balances', 'request-void', 'open-shifts', 'close-shifts'],
    ],
];
