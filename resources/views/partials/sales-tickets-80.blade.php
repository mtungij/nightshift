@foreach($copies as $copy)
    <article class="ticket" data-copy="{{ $copy }}">
        <h1>{{ $ticket['business'] }}</h1>
        <div class="center">{{ $ticket['branch'] }}</div>
        <div class="copy-label">{{ \App\Support\UiText::get($copy) }}</div>
        @if($reprint)<div class="reprint">{{ \App\Support\UiText::get('REPRINT') }}</div>@endif
        <div class="line"></div>
        @foreach(['Invoice' => 'invoice', 'Date/time' => 'issued_at', 'Waiter' => 'waiter', 'Table' => 'table', 'Counter' => 'counter'] as $label => $field)
            @if($field !== 'counter' || ($ticket['show_location'] ?? true))
            <div class="row"><span>{{ \App\Support\UiText::get($label) }}</span><strong>{{ $ticket[$field] }}</strong></div>
            @endif
        @endforeach
        @if(filled($ticket['business_unit'] ?? null))
            <div class="row meta-row"><span>{{ \App\Support\UiText::get('Business Unit') }}</span><strong>{{ $ticket['business_unit'] }}</strong></div>
        @endif
        @if(filled($ticket['entered_via'] ?? null))
            <div class="row"><span>{{ \App\Support\UiText::get('Entered via') }}</span><strong>{{ $ticket['entered_via'] }}</strong></div>
        @endif
        @if(filled($ticket['order_note'] ?? null))
            <div class="order-note"><strong>{{ \App\Support\UiText::get('Order Note') }}</strong><div>{{ $ticket['order_note'] }}</div></div>
        @endif
        <div class="line"></div>
        <table><thead><tr><th>{{ \App\Support\UiText::get('Item / Quantity / Unit') }}</th><th>{{ \App\Support\UiText::get('Unit price') }}</th><th>{{ \App\Support\UiText::get('Line total') }}</th></tr></thead>
            <tbody>@foreach($ticket['items'] as $item)<tr><td>{{ $item['product_name'] }}<br>{{ \App\Support\QuantityFormatter::format($item['quantity']) }} × {{ $item['unit_name'] }}</td><td>{{ $item['unit_price'] }}</td><td>{{ $item['total'] }}</td></tr>@endforeach</tbody>
        </table>
        <div class="line"></div>
        @if($copy === 'WAITER COPY')
            <div class="row"><span>{{ \App\Support\UiText::get('Subtotal') }}</span><span class="amount">{{ $ticket['subtotal'] }}</span></div>
            <div class="row"><span>{{ \App\Support\UiText::get('Discount') }}</span><span class="amount">{{ $ticket['discount'] }}</span></div>
        @endif
        <div class="row total"><span>{{ \App\Support\UiText::get('TOTAL') }} {{ $ticket['currency'] }}</span><span class="amount">{{ $ticket['total'] }}</span></div>
        <div class="row"><span>{{ \App\Support\UiText::get('Status') }}</span><strong>@if($copy === 'CASHIER COPY'){{ \App\Support\UiText::get('ISSUED') }} / @endif{{ \App\Support\UiText::get($ticket['status']) }}</strong></div>
        @if($copy === 'WAITER COPY')
            <div class="row"><span>{{ \App\Support\UiText::get('Waiter outstanding after this order') }}</span><strong class="amount">{{ $ticket['outstanding'] }}</strong></div>
        @endif
    </article>
@endforeach
