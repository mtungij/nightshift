@php
    $t = fn ($text) => \App\Support\UiText::get($text);
    $moneyColumns = ['Unit Price', 'Sales', 'Cost', 'Gross Profit'];
    $financialColumns = ['Cost', 'Gross Profit', 'Margin %'];
    $profitValue = function ($column, $value) use ($metadata, $t, $moneyColumns, $financialColumns) {
        if ($value === null) {
            return in_array($column, $financialColumns, true) ? $t('Unavailable') : '—';
        }
        if (in_array($column, $moneyColumns, true)) {
            return $metadata['currency'].' '.\App\Support\TicketAmountFormatter::format((string) $value, $metadata['currency'] === 'TSh' ? 'TZS' : $metadata['currency']);
        }

        return $column === 'Margin %' ? \App\Support\QuantityFormatter::format($value).'%' : $value;
    };
@endphp
<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><title>{{ $metadata['company'] }} · {{ $metadata['title'] }}</title><style>
@page {size:A4 {{ $orientation }};margin:13mm 10mm 17mm}
*{box-sizing:border-box}body{font:10px Arial,sans-serif;color:#202630;background:#fff;margin:0}h1{font-size:20px;margin:12px 0}h2{font-size:13px;margin:18px 0 8px}.pdf-brand{display:flex;align-items:center;gap:10px;border-bottom:2px solid #956015;padding-bottom:10px}.pdf-brand img{width:28px;height:28px}.pdf-brand strong{letter-spacing:2px;font-size:15px}.pdf-brand span{margin-left:auto}.pdf-meta{color:#555;font-size:9px}.pdf-filters{display:flex;flex-wrap:wrap;gap:8px 18px;margin:12px 0}.pdf-filters div{display:flex;gap:6px}.pdf-filters dt{font-weight:bold}.pdf-filters dd{margin:0;overflow-wrap:anywhere}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #dce1e8;text-align:left;padding:6px 5px;overflow-wrap:anywhere;white-space:normal;vertical-align:top;font-size:{{ $orientation==='landscape'?8:10 }}px}th{background:#f0f2f5;font-weight:bold}thead{display:table-header-group}tr{break-inside:avoid}.pdf-note{color:#555;font-size:9px}
.pdf-profit-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:12px 0;break-inside:avoid}
.pdf-profit-summary div{padding:7px;border:1px solid #dce1e8;overflow-wrap:anywhere}
.pdf-profit-summary dd{margin:4px 0 0;font-weight:bold;font-size:12px}
.pdf-profit-table th:first-child{width:16%}
.pdf-profit-amount{text-align:right;font-variant-numeric:tabular-nums}
</style></head><body>@include('reports.pdf-header')
@foreach($sections as $section)
    <section>
        @if(!empty($section['title']))<h2>{{ $t($section['title']) }}</h2>@endif
        @if(!empty($section['note']))<p class="pdf-note">{{ $t($section['note']) }}</p>@endif
        @if(!empty($section['summary']) && !empty($section['profitability']))
            <dl class="pdf-profit-summary">
                @foreach(['Sales' => 'Total Sales', 'Cost' => 'Cost of Goods Sold', 'Gross Profit' => 'Gross Profit', 'Margin %' => 'Gross Margin'] as $column => $label)
                    <div><dt>{{ $t($label) }}</dt><dd>{{ $profitValue($column, $section['summary'][$column]) }}</dd></div>
                @endforeach
            </dl>
        @endif
        <table @class(['pdf-profit-table' => !empty($section['profitability'])])>
            <thead><tr>@foreach($section['headers'] as $header)<th scope="col">{{ $t($header) }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse($section['rows'] as $row)
                    <tr>
                        @foreach($row as $index => $value)
                            @php($header = $section['headers'][$index])
                            @if(!empty($section['profitability']))
                                <td @class(['pdf-profit-amount' => in_array($header, [...$moneyColumns, 'Margin %'], true)])>{{ $profitValue($header, $value) }}</td>
                            @else
                                <td>{{ is_scalar($value) ? (in_array($header, ['Transaction type', 'Direction', 'Receipt status', 'Sale type'], true) ? $t((string) $value) : (string) $value) : '—' }}</td>
                            @endif
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($section['headers']) }}">{{ $t('No records found') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="pdf-note">{{ $t('Rows') }}: {{ count($section['rows']) }}</p>
    </section>
@endforeach
</body></html>
