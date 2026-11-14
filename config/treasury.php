<?php

$accountant = ['view-treasury', 'receive-treasury', 'treasury-purchases', 'treasury-expenses', 'treasury-wages', 'treasury-bank-deposits'];
$oversight = ['review-cash-handovers', 'approve-cash-discrepancies', 'manage-cash-handovers', 'authorize-opening-cash'];
$all = [...$accountant, ...$oversight, 'oversee-treasury', 'reverse-treasury'];

return [
    'permissions' => $all,
    'grants' => ['Accountant' => $accountant, 'Manager' => $oversight, 'Company Admin / Owner' => $all, 'Super Admin' => $all],
    'expense_categories' => ['Transport', 'Electricity', 'Water', 'Cleaning', 'Security', 'Maintenance', 'Petty cash', 'Other'],
    'reports' => ['Cashier Handover', 'Treasury Ledger', 'Cash Received from Cashiers', 'Cash Used for Purchases', 'Expenses', 'Salaries and Wages', 'Bank Deposits', 'Reversals', 'Cash Custody Balance'],
];
