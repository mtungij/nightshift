<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $company }} · {{ \App\Support\UiText::get('Product List') }}</title>

<style>
@page {
    size: A4 portrait;
    margin: 11mm;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    color: #151515;
    background: #fff;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 10px;
}

.header {
    background: #111;
    color: #fff;
    padding: 16px 18px;
    border-bottom: 4px solid #d7a94b;
    margin-bottom: 14px;
}

.brand {
    color: #e2b257;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: 2px;
}

.title {
    font-size: 17px;
    font-weight: 800;
    margin-top: 3px;
}

.meta {
    margin-top: 7px;
    color: #ddd;
    line-height: 1.5;
}

.category {
    margin-top: 15px;
    padding: 8px 10px;
    background: #111;
    color: #e2b257;
    font-size: 13px;
    font-weight: 800;
    page-break-after: avoid;
}

.business-unit {
    margin-top: 8px;
    padding: 7px 9px;
    background: #fbf3e4;
    border-left: 4px solid #d7a94b;
    font-size: 11px;
    font-weight: 800;
    page-break-after: avoid;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 8px;
}

thead {
    display: table-header-group;
}

th {
    background: #292929;
    color: #fff;
    padding: 7px;
    text-align: left;
    font-size: 9px;
}

td {
    padding: 7px;
    border-bottom: 1px solid #dedede;
}

tr {
    page-break-inside: avoid;
}

.number {
    text-align: right;
}

.empty {
    padding: 25px;
    text-align: center;
    color: #777;
}

.footer {
    margin-top: 18px;
    padding-top: 7px;
    border-top: 1px solid #ddd;
    color: #777;
    font-size: 8px;
}
</style>
</head>

<body>

<header class="header">
    <div class="brand">NIGHTSHIFT</div>

    <div class="title">
        {{ \App\Support\UiText::get('Product List') }}
    </div>

    <div class="meta">
        {{ $company }} · {{ $branch }}

        @if($unit)
            · {{ $unit }}
        @endif

        @if($categoryFilter)
            · {{ $categoryFilter }}
        @endif

        <br>

        {{ \App\Support\UiText::get('Generated') }}:
        {{ $generatedAt }}
    </div>
</header>

@forelse($groups as $category => $businessUnits)

    <section class="category">
        {{ $category }}
    </section>

    @foreach($businessUnits as $businessUnit => $products)

        <section class="business-unit">
            {{ \App\Support\UiText::get('Business Unit') }}:
            {{ $businessUnit }}
        </section>

        <table>
            <thead>
                <tr>
                    <th>{{ \App\Support\UiText::get('Product') }}</th>
                    <th>{{ \App\Support\UiText::get('Unit') }}</th>
                    <th class="number">
                        {{ \App\Support\UiText::get('Selling price') }}
                    </th>
                    <th class="number">
                        {{ \App\Support\UiText::get('Available stock') }}
                    </th>
                </tr>
            </thead>

            <tbody>
                @foreach($products as $product)
                    <tr>
                        <td><strong>{{ $product['name'] }}</strong></td>
                        <td>{{ $product['base_unit'] }}</td>
                        <td class="number">
                            {{ $currency }}
                            {{ number_format((float) $product['price'], 0) }}
                        </td>
                        <td class="number">
                            {{ \App\Support\QuantityFormatter::format($product['stock']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

    @endforeach

@empty

    <div class="empty">
        {{ \App\Support\UiText::get('No products found.') }}
    </div>

@endforelse

<footer class="footer">
    NIGHTSHIFT · {{ $company }}
</footer>

</body>
</html>
