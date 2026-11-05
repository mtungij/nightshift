@php($paperWidth = ($paperWidth ?? '80mm') === '58mm' ? '58mm' : '80mm')
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $ticket['invoice'] }} · {{ \App\Support\UiText::get('Sales tickets') }}</title>
    <style>
        :root{color-scheme:light}*{box-sizing:border-box}body{font:12px/1.35 monospace;color:#000;background:#fff;margin:0 auto}body.paper-80{width:80mm;padding:4mm}h1{font-size:17px;margin:4px 0;text-align:center;overflow-wrap:anywhere}.copy-label{font-size:20px;font-weight:900;text-align:center;margin:8px 0}.reprint{font-size:17px;font-weight:bold;text-align:center;border:2px solid #000;padding:4px}.center{text-align:center}.row{display:flex;justify-content:space-between;gap:8px;margin:4px 0}.row>*{overflow-wrap:anywhere;min-width:0}.row>:last-child{text-align:right}.row>.amount{flex-shrink:0;white-space:nowrap}.line{border-top:1px dashed #000;margin:7px 0}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{text-align:right;padding:3px 0;vertical-align:top;overflow-wrap:anywhere}th:first-child,td:first-child{text-align:left;width:48%}th{font-size:10px}td{font-size:11px}.total{font-size:16px;font-weight:bold}button{background:#fff;color:#000;border:1px solid #000;width:100%;padding:12px;margin:12px 0}.ticket+.ticket{border-top:2px dashed #000;margin-top:20px;padding-top:15px}
        .order-note{margin:5px 0;white-space:pre-wrap;overflow-wrap:anywhere;word-break:normal}.order-note>strong{display:block}
        @page{size:{{ $paperWidth }} 200mm;margin:{{ $paperWidth === '58mm' ? '0' : '4mm' }}}
        @media print {
    html, body {
        background: #fff !important;
        color: #000 !important;
    }
    body.paper-80 {
        width: 72mm;
        padding: 0;
        margin: 0;
    }
    .ticket {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .ticket + .ticket {
        break-before: page;
        page-break-before: always;
        border: 0;
        margin: 0;
        padding: 0;
    }
    body.paper-58 .ticket {
        break-inside: auto;
        page-break-inside: auto;
    }
    body.paper-58 .ticket + .ticket {
    break-before: auto;
    page-break-before: auto;
    border-top: 1px dashed #000;
    margin-top: 1mm;
    padding-top: 1mm;
}
    .controls {
        display: none;
    }
}
        /* Dedicated 58mm structure: 48mm of content inside the printer's safe area. */
        body.paper-58{width:58mm;padding:0 5mm;font-size:11px;line-height:1.35}
        .paper-58 .ticket{width:48mm;padding:2mm 0;overflow-wrap:anywhere}
        .paper-58 h1{font-size:15px;line-height:1.2;margin:3px 0}
        .paper-58 .copy-label{font-size:16px;line-height:1.2;margin:6px 0;overflow-wrap:anywhere}
        .paper-58 .reprint{font-size:14px;padding:3px}
        .paper-58 .meta-row{display:block;margin:4px 0}
        .paper-58 .meta-row>span{display:block;font-size:10px}
        .paper-58 .meta-row>strong{display:block;text-align:left;overflow-wrap:anywhere}
        .paper-58 .line{margin:5px 0}
        .paper-58 .ticket-item{padding:5px 0;border-bottom:1px dotted #000}
        .paper-58 .product-name{font-weight:bold;overflow-wrap:anywhere}
        .paper-58 .quantity-unit{overflow-wrap:anywhere}
        .paper-58 .item-price{display:block;font-size:10px;margin:2px 0;overflow-wrap:anywhere}
        .paper-58 .item-price>.amount{display:block;text-align:right;white-space:nowrap}
        .paper-58 .item-total{text-align:right;font-weight:bold;white-space:nowrap}
        .paper-58 .row{gap:4px}
        .paper-58 .total,.paper-58 .status,.paper-58 .outstanding{display:block}
        .paper-58 .total{font-size:16px;margin:6px 0}
        .paper-58 .total>.amount,.paper-58 .outstanding>.amount{display:block;text-align:right;white-space:nowrap}
        .paper-58 .status>strong{display:block;text-align:left}
        .paper-58 .outstanding>span{display:block}
        .paper-58 .outstanding>.amount{font-size:14px;font-weight:bold}
        @media print {
    body.paper-58 {
        width: 58mm;
        padding: 0 5mm;
        margin: 0;
    }

    .paper-58 .ticket + .ticket {
        padding-top: 1mm;
        padding-bottom: 0;
    }
}
    </style>
</head>
<body class="paper-{{ $paperWidth === '58mm' ? '58' : '80' }}" data-paper-width="{{ $paperWidth }}" data-committed-order="{{ $ticket['order_id'] }}" data-batch="{{ $batch?->token }}">
@include($paperWidth === '58mm' ? 'partials.sales-tickets-58' : 'partials.sales-tickets-80')
<div class="controls">
    <button id="reprint">{{ \App\Support\UiText::get('Reprint sales tickets') }}</button>
    <p id="print-message" role="status"></p>
</div>
<script>
    // Use valid CSS page dimensions and fit roll length to the longest ticket.
    const paperWidth = @json($paperWidth);
    function fitPaper() {
        const tickets = [...document.querySelectorAll('.ticket')];
        if (!tickets.length) return;

        let height;

        if (paperWidth === '58mm') {
            const firstTop = tickets[0].getBoundingClientRect().top;
            const lastBottom = tickets[tickets.length - 1]
                .getBoundingClientRect().bottom;

            height = Math.ceil(
                (lastBottom - firstTop) * 25.4 / 96 + 6
            );
        } else {
            const longest = Math.max(
                ...tickets.map(el => el.getBoundingClientRect().height)
            );

            height = Math.ceil(longest * 25.4 / 96 + 12);
        }

        let style = document.querySelector('#ticket-paper-size');

        if (!style) {
            style = document.createElement('style');
            style.id = 'ticket-paper-size';
            document.head.append(style);
        }

        style.textContent =
            '@page{size:' + paperWidth + ' ' +
            Math.max(70, height) +
            'mm;margin:' +
            (paperWidth === '58mm' ? '0' : '4mm') + '}';
    }
    window.addEventListener('beforeprint', fitPaper);
    fitPaper();
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
    document.querySelector('#reprint').addEventListener('click', async function () {
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
