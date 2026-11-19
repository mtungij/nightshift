@php($paperWidth = ($paperWidth ?? '80mm') === '58mm' ? '58mm' : '80mm')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $ticket['invoice'] }} · {{ \App\Support\UiText::get('Sales tickets') }}</title>
    @include('partials.sales-ticket-styles')
</head>
<body class="paper-{{ $paperWidth === '58mm' ? '58' : '80' }}" data-paper-width="{{ $paperWidth }}" data-committed-order="{{ $ticket['order_id'] }}" data-batch="{{ $batch?->token }}">
<main class="ticket-roll">
    @include('partials.sales-ticket-content')
</main>
<div class="controls">
    @if($invoiceReceipt ?? false)
        <button type="button" id="print-receipt">{{ \App\Support\UiText::get('Print receipt') }}</button>
    @else
        <button type="button" id="reprint">{{ \App\Support\UiText::get('Reprint sales tickets') }}</button>
    @endif
    <p>{{ \App\Support\UiText::get('In the browser print settings, set Headers and footers to Off.') }}</p>
    <p id="print-message" role="status"></p>
</div>
<script>
    const paperWidth = @json($paperWidth);
    function fitPaper() {
        const roll = document.querySelector('.ticket-roll');
        const tickets = [...roll.querySelectorAll('.ticket')];
        if (!tickets.length) return;

        // The 58 mm batch shares one roll. The 80 mm copies keep their own pages.
        const contentHeight = paperWidth === '58mm'
            ? roll.getBoundingClientRect().height
            : Math.max(...tickets.map((ticket) => ticket.getBoundingClientRect().height));
        // Stay within the PDF page-size limit even for unusually large batches.
        const height = Math.min(5000, Math.max(40, Math.ceil(contentHeight * 25.4 / 96 + 2)));
        let style = document.querySelector('#ticket-paper-size');
        if (!style) {
            style = document.createElement('style');
            style.id = 'ticket-paper-size';
            document.head.append(style);
        }
        style.textContent = '@page { size: ' + paperWidth + ' ' + height + 'mm; margin: 0; }';
    }
    window.addEventListener('beforeprint', fitPaper);
    window.addEventListener('load', fitPaper);
    fitPaper();
    document.querySelector('#print-receipt')?.addEventListener('click', () => window.print());
    // The server allows one browser print request per batch, including across tabs.
    const batch = @json($batch?->token);
    if (batch && new URLSearchParams(location.search).get('print') === '1') {
        window.addEventListener('load', async () => {
            const key = 'nightshift-ticket:' + batch;
            try {
                if (sessionStorage.getItem(key)) return;
            } catch (_) { /* Printing still works with browser storage disabled. */ }
            try {
                const response = await fetch(@json($batch ? route('nightshift.ticket-dispatch', $batch->token, false) : null), {
                    method: 'POST', headers: {'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content}
                });
                if (!response.ok) throw new Error('Print dispatch failed');
                if (!(await response.json()).print) return;
                try { sessionStorage.setItem(key, 'requested'); } catch (_) {}
                window.focus();
                window.print();
            } catch (_) {
                document.querySelector('#print-message').textContent = @json(\App\Support\UiText::get('Order saved. Printing failed; reprint from the order.'));
                if (window.parent !== window) window.parent.postMessage({type:'nightshift-print-failed'}, location.origin);
            }
        });
    }
    document.querySelector('#reprint')?.addEventListener('click', async function () {
        this.disabled = true;
        this.requestKey ||= crypto.randomUUID();
        try {
            const response = await fetch('/api/nightshift/commands/ticket-reprint', {
                method: 'POST', headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('[name=csrf-token]').content},
                body: JSON.stringify({id: @json($ticket['order_id']), idempotency_key: this.requestKey})
            });
            const result = await response.json();
            if (!response.ok || !result.print_url) throw new Error(result.message || @json(\App\Support\UiText::get('No ticket copies are enabled.')));
            location.href = result.print_url + '?print=1';
        } catch (error) {
            document.querySelector('#print-message').textContent = error.message;
            this.disabled = false;
        }
    });
</script>
</body>
</html>
