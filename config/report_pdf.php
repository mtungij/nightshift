<?php

return [
    'node' => env('REPORT_PDF_NODE_BINARY', 'node'),
    'chrome' => env('REPORT_PDF_CHROME_BINARY', 'google-chrome'),
    'no_sandbox' => (bool) env('REPORT_PDF_NO_SANDBOX', false),
    'timeout' => 90,
    'max_rows' => 3000,
    'max_bytes' => 30 * 1024 * 1024,
    'filters' => [
        'Business Unit Performance' => ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'shift_id'],
        'Waiter Outstanding' => ['branch_id', 'business_unit_id', 'stock_location_id', 'waiter_id', 'shift_id'],
        'Waiter Settlement' => ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'waiter_id', 'shift_id'],
        'Payment Method Report' => ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'issued_by', 'shift_id'],
        'Payments' => ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'issued_by', 'shift_id'],
        'Opening Stock' => ['from', 'branch_id', 'business_unit_id', 'stock_location_id', 'product_id', 'category_id', 'shift_id'],
        'Closing Stock' => ['to', 'branch_id', 'business_unit_id', 'stock_location_id', 'product_id', 'category_id', 'shift_id'],
        'Stock Movement' => ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'product_id', 'category_id', 'shift_id'],
        ...array_fill_keys(['Cashier Shift', 'Waiter Shift', 'Shift Report', 'Cash Shortage / Excess'], ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'waiter_id', 'issued_by', 'shift_id']),
        ...array_fill_keys(['Stock Transfer', 'Goods Received', 'Breakages', 'Breakage', 'Complimentary Drinks', 'Complimentary', 'Staff Consumption', 'Voided Sales'], ['from', 'to', 'branch_id', 'business_unit_id']),
        'Returns' => ['from', 'to', 'branch_id', 'business_unit_id', 'shift_id'],
        'Cash Custody Balance' => ['branch_id', 'custodian_id'],
        'Cashier Handover' => ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'shift_id'],
        'Cash Received from Cashiers' => ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'custodian_id', 'shift_id'],
        ...array_fill_keys(['Treasury Ledger', 'Cash Used for Purchases', 'Expenses', 'Salaries and Wages', 'Salaries/Wages', 'Bank Deposits', 'Reversals'], ['from', 'to', 'branch_id', 'custodian_id']),
    ],
    'sales_reports' => ['Daily Sales', 'Order Report', 'Paid Orders', 'Unpaid Orders', 'Sales by Waiter', 'Sales by Cashier', 'Sales by Product', 'Sales by Category', 'Sales by Table', 'Sales by Counter', 'Sales by Branch', 'Profit Report', 'Best Selling Products', 'Slow Moving Products'],
    'default_filters' => ['from', 'to', 'branch_id', 'business_unit_id', 'stock_location_id', 'waiter_id', 'issued_by', 'product_id', 'category_id', 'shift_id'],
];
