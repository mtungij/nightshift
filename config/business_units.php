<?php

return [
    // Append-only context preserves the meaning of existing financial records.
    'records' => [
        'shifts', 'orders', 'order_items', 'payments', 'payment_allocations',
        'waiter_ledger_entries', 'stock_movements', 'goods_receipts', 'goods_receipt_items',
        'stock_transfers', 'stock_transfer_items', 'stock_adjustments', 'stock_adjustment_items',
        'returns', 'return_items', 'void_requests', 'shift_stock_snapshots', 'shift_stock_counts',
        'cash_handovers', 'cash_handover_events', 'treasury_entries', 'notifications', 'audit_logs',
    ],
];
