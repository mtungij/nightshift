<?php

return [
    'type_labels' => ['DAILY' => 'Daily Report', 'WEEKLY' => 'Weekly Report', 'MONTHLY' => 'Monthly Report', 'TREASURY_SUMMARY' => 'Treasury Summary', 'SHIFT' => 'Shift Close Report', 'CASH_HANDOVER' => 'Cash Handover Alert', 'LARGE_SALE' => 'Large Sale Alert', 'CASH_SHORTAGE' => 'Cash Shortage Alert', 'WAITER_OUTSTANDING' => 'Waiter Outstanding Alert', 'LOW_STOCK' => 'Low Stock Alert', 'VOID' => 'Void Alert', 'BREAKAGE' => 'Breakage Alert', 'ADJUSTMENT' => 'Adjustment Alert', 'COMPLIMENTARY' => 'Complimentary Alert'],
    'types' => ['DAILY', 'WEEKLY', 'MONTHLY', 'TREASURY_SUMMARY', 'SHIFT', 'CASH_HANDOVER', 'LARGE_SALE', 'CASH_SHORTAGE', 'WAITER_OUTSTANDING', 'LOW_STOCK', 'VOID', 'BREAKAGE', 'ADJUSTMENT', 'COMPLIMENTARY'],
    'permissions' => ['whatsapp.view_settings', 'whatsapp.manage_settings', 'whatsapp.manage_recipients', 'whatsapp.view_logs', 'whatsapp.send_test', 'whatsapp.send_manual_report'],
    'manager_permissions' => ['whatsapp.view_settings', 'whatsapp.manage_recipients', 'whatsapp.view_logs', 'whatsapp.send_test', 'whatsapp.send_manual_report'],
    'defaults' => [
        'enabled' => false, 'daily_report' => false, 'daily_time' => '23:00',
        'report_mode' => 'consolidated', 'language' => 'sw', 'shift_report' => false,
        'waiter_outstanding' => false, 'waiter_threshold' => '300000',
        'cash_shortage' => false, 'cash_threshold' => '50000',
        'low_stock' => false, 'low_stock_drop_percent' => 25,
        'void' => false, 'breakage' => false, 'breakage_threshold' => '30000',
        'complimentary' => false, 'complimentary_threshold' => '100000',
        'large_sale' => false, 'large_sale_threshold' => '500000',
        'cooldown_minutes' => 360, 'operating_cutoff' => '05:00', 'cash_handover' => false, 'adjustment' => false, 'adjustment_threshold' => '30000',
    ],
    'audit_actions' => ['DRINKS_ISSUED', 'PAYMENT_RECEIVED', 'RETURN_PROCESSED', 'SHIFT_CLOSED', 'VOID_APPROVED', 'STOCK_ADJUSTED', 'GRN_POSTED', 'TRANSFER_SENT', 'TRANSFER_RECEIVED', 'CASH_HANDOVER_PENDING', 'CASH_HANDOVER_RECEIVED'],
];
