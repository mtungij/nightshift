@php
    $ticket = [
        ...$receiptDetails,
        'order_id' => $order->id,
        'business' => $company->trading_name ?: $company->name,
        'branch' => $branch->name,
        'invoice' => $order->invoice_number,
        'issued_at' => $order->created_at->copy()->timezone($company->timezone ?: 'Africa/Dar_es_Salaam')->format('d M Y H:i'),
        'waiter' => $waiter->name,
        'cashier' => $cashier?->name ?? \App\Support\UiText::get('Not issued'),
        'table' => $table?->name ?? \App\Support\UiText::get('Takeaway'),
        'counter' => $counter->name,
        'business_unit' => $businessUnit,
        'order_note' => $order->order_note,
        'items' => $order->items->map(fn ($item) => $item->only(['product_name', 'quantity', 'unit_name', 'unit_price', 'total', 'returned_quantity']))->all(),
        'currency' => $company->currency,
        'subtotal' => $order->subtotal,
        'discount' => $order->discount,
        'returns' => $order->return_total,
        'total' => $order->total,
        'payments' => $payments,
        'amount_paid' => $order->amount_paid,
        'balance' => $order->balance,
        'status' => str_replace('_', ' ', $order->status),
    ];
@endphp

@include('issue-ticket', ['ticket' => $ticket, 'copies' => ['CUSTOMER RECEIPT'], 'batch' => null, 'reprint' => false, 'invoiceReceipt' => true])
