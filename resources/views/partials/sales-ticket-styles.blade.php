<style>
    :root {
        color-scheme: only light;
        background: #fff;
    }
    * { box-sizing: border-box; }
    body {
        --paper-width: 80mm;
        --content-width: 72mm;
        margin: 0 auto;
        width: min(100%, var(--paper-width));
        background: #fff;
        color: #000;
        font: 12px/1.3 Arial, Helvetica, sans-serif;
        font-variant-numeric: tabular-nums;
    }
    body.paper-58 {
        --paper-width: 58mm;
        --content-width: 48mm;
        font-size: 11px;
    }
    h1, h2, p, dl, dd { margin: 0; }
    .ticket, .controls {
        width: min(calc(100% - 4mm), var(--content-width));
        margin-inline: auto;
        overflow-wrap: anywhere;
    }
    .ticket { padding-block: 2mm; }
    .ticket + .ticket {
        border-top: 1px dashed #000;
        margin-top: 2mm;
    }
    .ticket-header {
        text-align: center;
        padding-bottom: 1.5mm;
        border-bottom: 1px solid #000;
    }
    .ticket-header h1 {
        font-size: 17px;
        font-weight: 700;
        line-height: 1.15;
    }
    .paper-58 .ticket-header h1 { font-size: 15px; }
    .ticket-branch { margin-top: 1mm; }
    .copy-label {
        margin-top: 1.6mm;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: .045em;
    }
    .reprint {
        display: inline-block;
        margin-top: .8mm;
        padding: .25mm 1.2mm;
        border: 1px solid #000;
        font-size: 9px;
        line-height: 1.2;
        font-weight: 700;
    }
    .business-details {
        margin-top: 1mm;
        font-size: .95em;
        font-style: normal;
    }
    .business-details > div { white-space: pre-line; }
    .ticket-metadata { padding-block: 1.4mm; }
    .ticket-rows .row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, .9fr);
        gap: 1.5mm;
        align-items: baseline;
        padding: .32mm 0;
    }
    .ticket-rows dd { text-align: right; }
    .ticket-metadata .row { grid-template-columns: minmax(0, .78fr) minmax(0, 1.22fr); }
    .ticket-metadata dd { text-align: left; font-weight: 600; }
    .order-note {
        border-top: 1px dashed #000;
        padding-block: 1mm;
        white-space: pre-wrap;
        overflow-wrap:anywhere;
    }
    .order-note > strong { display: block; white-space: normal; }
    .ticket-items { width: 100%; border-top: 1px solid #000; }
    .ticket-items tbody td, .ticket-items thead th {
        padding: 1mm .6mm;
        vertical-align: top;
        text-align: right;
    }
    table.ticket-items { border-collapse: collapse; table-layout: fixed; }
    .ticket-items .item-name-col { width: 37%; }
    .ticket-items .item-quantity-col { width: 23%; }
    .ticket-items .item-price-col { width: 19%; }
    .ticket-items .item-total-col { width: 21%; }
    .ticket-items td:first-child, .ticket-items th:first-child { padding-left: 0; text-align: left; }
    .ticket-items th:last-child, .ticket-items td:last-child { padding-right: 0; }
    .ticket-items thead th { border-bottom: 1px solid #000; }
    .product-name { font-weight: 700; }
    .paper-58 .ticket-item { padding-block: .95mm; }
    .ticket-item + .ticket-item { border-top: 1px dotted #555; }
    .item-detail {
        display: grid;
        grid-template-columns: minmax(0, 1.05fr) minmax(0, .95fr) minmax(0, 1fr);
        gap: 1mm;
    }
    .item-detail > :not(:first-child) { text-align: right; }
    .item-head, .ticket-items th { font-size: 10px; font-weight: 700; }
    .item-head { padding: .85mm 0; border-bottom: 1px solid #000; }
    .ticket-return { display: block; margin-top: .5mm; }
    .ticket-totals {
        border-top: 1px solid #000;
        padding-top: .8mm;
    }
    .ticket-totals .total {
        border-block: 1px solid #000;
        padding-block: 1mm;
        margin-top: .6mm;
        font-size: 15px;
        font-weight: 700;
    }
    .ticket-payment, .ticket-settlement { padding-top: 1mm; }
    .ticket-payment h2 { font-size: 1em; font-weight: 700; }
    .ticket-status, .outstanding { font-weight: 700; }
    .ticket-footer {
        border-top: 1px dashed #000;
        padding-top: 1.2mm;
        margin-top: 1.4mm;
        text-align: center;
    }
    .configured-footer { white-space: pre-line; }
    .controls {
        border-top: 1px solid #000;
        margin-block: 2mm 4mm;
        padding-top: 2mm;
    }
    .controls button {
        background: #fff;
        color: #000;
        border: 1px solid #000;
        width: 100%;
        min-height: 44px;
        padding: 6px;
        cursor: pointer;
    }
    .controls p { margin-top: 1.4mm; }
    @page { size: {{ $paperWidth }} 200mm; margin: 0; }
    @media print {
        html, body { background: #fff !important; color: #000 !important; }
        body { width: var(--paper-width); margin: 0; }
        .controls { display: none !important; }
        .ticket-header, .ticket-item, .ticket-rows .row, .ticket-totals, .ticket-settlement, .ticket-footer {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .ticket-payment h2, .item-head { break-after: avoid; }
        .paper-80 .ticket + .ticket {
            break-before: page;
            page-break-before: always;
            border: 0;
            margin-top: 0;
        }
    }
</style>
