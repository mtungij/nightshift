@php
    $t = fn ($text) => \App\Support\UiText::get($text);
    $sw = app()->getLocale() === 'sw';
@endphp

<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>{{ $report['number'] }} · {{ $sw ? 'Hesabu ya Stock ya Zamu' : 'Shift Stock Count' }}</title>

<style>
@page {
    size: A4 landscape;
    margin: 8mm;
}

* {
    box-sizing: border-box;
}

:root {
    --black: #111111;
    --black-soft: #1d1d1d;
    --gold: #d4a64a;
    --gold-dark: #a97819;
    --gold-soft: #f7edd8;
    --border: #d8d0c2;
    --muted: #6f6a60;
}

body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 9px;
    line-height: 1.4;
    color: var(--black);
    background: #ffffff;
    margin: 0;
}

.header {
    display: table;
    width: 100%;
    padding: 10px 12px;
    margin-bottom: 9px;
    background: var(--black);
    border-bottom: 4px solid var(--gold);
}

.header-left,
.header-right {
    display: table-cell;
    vertical-align: middle;
}

.header-left {
    width: 67%;
}

.header-right {
    width: 33%;
    text-align: right;
    color: #ffffff;
}

.brand {
    color: var(--gold);
    font-size: 18px;
    font-weight: 800;
    letter-spacing: 2px;
}

.title {
    color: #ffffff;
    font-size: 17px;
    font-weight: 800;
    margin-top: 3px;
}

.subtitle {
    color: #d9d9d9;
    font-size: 8px;
    margin-top: 2px;
}

.status {
    display: inline-block;
    margin-top: 4px;
    padding: 3px 9px;
    color: var(--black);
    background: var(--gold);
    border-radius: 12px;
    font-weight: 800;
}

.summary-grid {
    display: table;
    width: 100%;
    table-layout: fixed;
    margin: 8px 0 10px;
    border-spacing: 5px 0;
}

.summary-item {
    display: table-cell;
    padding: 7px 8px;
    background: var(--gold-soft);
    border: 1px solid var(--gold);
    border-top: 3px solid var(--gold-dark);
    vertical-align: top;
}

.summary-label {
    display: block;
    color: var(--muted);
    font-size: 7px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.summary-value {
    display: block;
    color: var(--black);
    font-size: 10px;
    font-weight: 800;
    margin-top: 2px;
}

.section-title {
    font-size: 11px;
    font-weight: 800;
    color: var(--black);
    margin: 11px 0 5px;
    padding: 5px 7px;
    border-left: 4px solid var(--gold);
    background: var(--gold-soft);
}

table {
    width: 100%;
    border-collapse: collapse;
}

.stock-table {
    table-layout: fixed;
    font-size: 7.4px;
}

.stock-table thead {
    display: table-header-group;
}

.stock-table th {
    background: var(--black);
    color: var(--gold);
    border: 1px solid #4b4233;
    padding: 5px 3px;
    text-align: center;
    font-size: 7px;
    font-weight: 800;
    vertical-align: middle;
}

.stock-table td {
    border: 1px solid var(--border);
    padding: 4px 3px;
    text-align: right;
    vertical-align: middle;
    overflow-wrap: anywhere;
}

.stock-table tbody tr:nth-child(even) td {
    background: #faf8f4;
}

.stock-table th:first-child,
.stock-table td:first-child {
    width: 14%;
    text-align: left;
}

.stock-table td:first-child {
    font-weight: 700;
}

.stock-table tr {
    break-inside: avoid;
}

.cash-table,
.waiter-table {
    font-size: 8px;
}

.cash-table th,
.waiter-table th {
    background: var(--black);
    color: var(--gold);
    border: 1px solid #4b4233;
    padding: 5px;
    text-align: left;
}

.cash-table td,
.waiter-table td {
    border: 1px solid var(--border);
    padding: 5px;
}

.cash-table td:not(:first-child) {
    text-align: right;
}

.totals {
    display: table;
    width: 100%;
    table-layout: fixed;
    border-spacing: 5px 0;
    margin-top: 7px;
}

.total-box {
    display: table-cell;
    padding: 7px;
    background: var(--gold-soft);
    border: 1px solid var(--gold);
    border-bottom: 3px solid var(--gold-dark);
}

.total-label {
    display: block;
    color: var(--muted);
    font-size: 7px;
    text-transform: uppercase;
}

.total-value {
    display: block;
    color: var(--black);
    font-size: 10px;
    font-weight: 800;
    margin-top: 2px;
}

.handover {
    margin-top: 10px;
    padding: 8px;
    border: 1px solid var(--gold);
    border-left: 4px solid var(--gold);
    background: #fffdfa;
}

.signatures {
    display: table;
    width: 100%;
    table-layout: fixed;
    margin-top: 18px;
}

.signature {
    display: table-cell;
    width: 50%;
    padding-right: 30px;
}

.signature-line {
    border-top: 1px solid var(--black);
    padding-top: 4px;
    margin-top: 18px;
    font-size: 8px;
    font-weight: 700;
}

.muted {
    color: var(--muted);
    font-size: 7px;
}

.note {
    padding: 6px 8px;
    background: #faf8f4;
    border-left: 3px solid var(--gold);
    font-size: 8px;
}

@media print {
    button,
    a {
        display: none;
    }
}
</style>
</head>

<body>

<div class="header">
    <div class="header-left">
        <div class="brand">NIGHTSHIFT</div>
        <div class="title">
            {{ $sw ? 'Hesabu ya Stock ya Zamu' : 'Shift Stock Count' }}
        </div>
        <div class="subtitle">
            {{ $report['company'] }}
        </div>
    </div>

    <div class="header-right">
        <strong>{{ $report['number'] }}</strong><br>
        {{ $report['operating_date'] ?? '—' }}<br>
        <span class="status">
            {{ $report['status'] === 'CLOSED'
                ? ($sw ? 'Imefungwa' : 'Finalized')
                : ($sw ? 'Bado Wazi' : 'Draft') }}
        </span>
    </div>
</div>

<div class="summary-grid">
    <div class="summary-item">
        <span class="summary-label">{{ $t('Branch') }}</span>
        <span class="summary-value">{{ $report['branch'] }}</span>
    </div>

    <div class="summary-item">
        <span class="summary-label">{{ $t('Business Unit') }}</span>
        <span class="summary-value">{{ $report['business_unit'] ?? '—' }}</span>
    </div>

    @if($report['show_location'] ?? true)
    <div class="summary-item">
        <span class="summary-label">{{ $t('Counter') }}</span>
        <span class="summary-value">{{ $report['counter'] }}</span>
    </div>
    @endif

    <div class="summary-item">
        <span class="summary-label">{{ $t('Cashier') }}</span>
        <span class="summary-value">{{ $report['cashier'] }}</span>
    </div>

    <div class="summary-item">
        <span class="summary-label">{{ $t('Total sales') }}</span>
        <span class="summary-value">TSh {{ $report['total_sales'] ?? '0' }}</span>
    </div>
</div>

@if(!empty($report['waiters']))
<div class="section-title">{{ $sw ? 'Wahudumu' : 'Waiters' }}</div>

<table class="waiter-table">
    <thead>
        <tr>
            <th>{{ $t('Waiter') }}</th>
            <th>{{ $t('Opened') }}</th>
            <th>{{ $t('Closed') }}</th>
        </tr>
    </thead>
    <tbody>
    @foreach($report['waiters'] as $session)
        <tr>
            <td>{{ $session['name'] }}</td>
            <td>{{ \Carbon\CarbonImmutable::parse($session['opened_at'])
        ->timezone('Africa/Dar_es_Salaam')
        ->format('d M Y, H:i') }}</td>
            <td>{{ $session['closed_at']
        ? \Carbon\CarbonImmutable::parse($session['closed_at'])
            ->timezone('Africa/Dar_es_Salaam')
            ->format('d M Y, H:i')
        : ($sw ? 'Bado wazi' : 'Open') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

<div class="section-title">
    {{ $sw ? 'Hesabu ya Stock' : 'Stock Count' }}
</div>

<table class="stock-table">
<thead>
<tr>
@foreach([
    'Product',
    'Opening',
    'Added',
    'Total Available',
    'System Sold',
    'Other Stock Out',
    'Valid Returns',
    'Expected Closing',
    'Physical Sold',
    'Physical Closing',
    'Variance',
    'Selling Price',
    'Expected Sales Value'
] as $label)
    <th>{{ $t($label) }}</th>
@endforeach
</tr>
</thead>

<tbody>
@foreach($report['rows'] as $row)
<tr>
    <td>
        {{ $row['product'] }}
        <br>
        <span class="muted">{{ $row['base_unit'] }}</span>
    </td>

    @foreach([
        'opening',
        'added',
        'total_available',
        'system_sold',
        'other_out',
        'returns',
        'expected',
        'physical_sold',
        'physical',
        'variance'
    ] as $field)
        @php
            $value = match ($field) {
                'physical' => $row['physical'] ?? $row['expected'],
                'variance' => $row['variance'] ?? 0,
                default => $row[$field],
            };
        @endphp
        <td>
            {{ $value === null
                ? '—'
                : \App\Support\QuantityFormatter::format($value) }}
        </td>
    @endforeach

    <td>{{ \App\Support\QuantityFormatter::format($row['price']) }}</td>
    <td>{{ $row['sales_value'] ?? '—' }}</td>
</tr>
@endforeach
</tbody>
</table>

<div class="totals">
    <div class="total-box">
        <span class="total-label">{{ $t('Expected Sales Value') }}</span>
        <span class="total-value">{{ $report['totals']['sales_value'] }}</span>
    </div>

    <div class="total-box">
        <span class="total-label">{{ $t('Stock Variance Value') }}</span>
        <span class="total-value">{{ $report['totals']['variance_value'] }}</span>
    </div>

    <div class="total-box">
        <span class="total-label">{{ $t('Waiter outstanding') }}</span>
        <span class="total-value">{{ $report['cash']['waiter_outstanding'] }}</span>
    </div>
</div>

<div class="section-title">
    {{ $sw ? 'Hesabu ya Fedha' : 'Cash Summary' }}
</div>

<table class="cash-table">
<thead>
<tr>
    <th>{{ $t('Payment method') }}</th>
    <th>{{ $t('Payments received') }}</th>
    <th>{{ $t('Refunds') }}</th>
    <th>{{ $t('Net') }}</th>
</tr>
</thead>

<tbody>
@foreach($report['cash']['methods'] as $method => $amounts)
    @if(
        \App\Domain\Decimal::cmp((string)$amounts['sales'], '0') !== 0 ||
        \App\Domain\Decimal::cmp((string)$amounts['refunds'], '0') !== 0 ||
        \App\Domain\Decimal::cmp((string)$amounts['net'], '0') !== 0
    )
    <tr>
        <td>{{ $t($method) }}</td>
        <td>{{ $amounts['sales'] }}</td>
        <td>{{ $amounts['refunds'] }}</td>
        <td>{{ $amounts['net'] }}</td>
    </tr>
    @endif
@endforeach
</tbody>
</table>

<div class="totals">
    <div class="total-box">
        <span class="total-label">{{ $t('Opening cash') }}</span>
        <span class="total-value">{{ $report['cash']['opening'] }}</span>
    </div>

    <div class="total-box">
        <span class="total-label">{{ $t('Expected cash') }}</span>
        <span class="total-value">{{ $report['cash']['expected'] }}</span>
    </div>

    <div class="total-box">
        <span class="total-label">{{ $t('Actual cash') }}</span>
        <span class="total-value">{{ $report['cash']['actual'] ?? '—' }}</span>
    </div>

    <div class="total-box">
        <span class="total-label">{{ $t('Cash variance') }}</span>
        <span class="total-value">{{ $report['cash']['difference'] ?? '—' }}</span>
    </div>
</div>

@if(
    !empty($report['reason']) ||
    !empty($report['approval_note']) ||
    !empty($report['closing_notes'])
)
<div class="section-title">
    {{ $sw ? 'Maelezo' : 'Notes' }}
</div>

<div class="note">
    {{ $report['reason'] }}
    {{ $report['approval_note'] }}
    {{ $report['closing_notes'] ?? '' }}
</div>
@endif

@include('partials.shift-custody')

<div class="signatures">
    <div class="signature">
        <div class="signature-line">
            {{ $sw ? 'Aliyekabidhi' : 'Handed over by' }}
            - {{ $report['cashier'] }}
        </div>
    </div>

    <div class="signature">
        <div class="signature-line">
            {{ $sw ? 'Aliyepokea' : 'Received by' }}
        </div>
    </div>
</div>

@unless(isset($pdfMetadata))
    <a href="/shift-stock/{{ $report['shift_id'] }}/pdf">
        {{ $t('Download PDF') }}
    </a>
    <button onclick="window.print()">
        {{ $t('Print closing sheet') }}
    </button>
@endunless

</body>
</html>
