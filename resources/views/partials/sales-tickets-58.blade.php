@php($currency = $ticket['currency'] === 'TZS' ? 'TSh' : $ticket['currency'])
@foreach($copies as $copy)
    <article class="ticket" data-copy="{{ $copy }}">
        <h1>{{ $ticket['business'] }}</h1>
        <div class="center">{{ $ticket['branch'] }}</div>
        <div class="copy-label">{{ \App\Support\UiText::get($copy) }}</div>
        @if($reprint)<div class="reprint">{{ \App\Support\UiText::get('REPRINT') }}</div>@endif
        <div class="line"></div>
        @foreach(['Invoice' => 'invoice', 'Date/time' => 'issued_at', 'Waiter' => 'waiter', 'Table' => 'table', 'Counter' => 'counter'] as $label => $field)
            <div class="row meta-row"><span>{{ \App\Support\UiText::get($label) }}</span><strong>{{ $ticket[$field] }}</strong></div>
        @endforeach
        @if(filled($ticket['entered_via'] ?? null))
            <div class="row meta-row"><span>{{ \App\Support\UiText::get('Entered via') }}</span><strong>{{ $ticket['entered_via'] }}</strong></div>
        @endif
        @if(filled($ticket['order_note'] ?? null))
            <div class="order-note"><strong>{{ \App\Support\UiText::get('Order Note') }}</strong><div>{{ $ticket['order_note'] }}</div></div>
        @endif
        <div class="line"></div>
        <div class="ticket-items">
            @foreach($ticket['items'] as $item)
                <div class="ticket-item">
                    <div class="product-name">{{ $item['product_name'] }}</div>
                    <div class="quantity-unit">{{ \App\Support\QuantityFormatter::format($item['quantity']) }} × {{ $item['unit_name'] }}</div>
                    <div class="item-price"><span>{{ \App\Support\UiText::get('Unit price') }}</span><span class="amount">{{ $currency }} {{ \App\Support\TicketAmountFormatter::format($item['unit_price']) }}</span></div>
                    <div class="item-total amount">{{ $currency }} {{ \App\Support\TicketAmountFormatter::format($item['total']) }}</div>
                </div>
            @endforeach
        </div>
        <div class="line"></div>
        @if($copy === 'WAITER COPY')
            <div class="row"><span>{{ \App\Support\UiText::get('Subtotal') }}</span><span class="amount">{{ \App\Support\TicketAmountFormatter::format($ticket['subtotal']) }}</span></div>
            <div class="row"><span>{{ \App\Support\UiText::get('Discount') }}</span><span class="amount">{{ \App\Support\TicketAmountFormatter::format($ticket['discount']) }}</span></div>
        @endif
        <div class="row total"><span>{{ \App\Support\UiText::get('TOTAL') }} {{ $currency }}</span><span class="amount">{{ \App\Support\TicketAmountFormatter::format($ticket['total']) }}</span></div>
        <div class="row status"><span>{{ \App\Support\UiText::get('Status') }}</span><strong>@if($copy === 'CASHIER COPY'){{ \App\Support\UiText::get('ISSUED') }} / @endif{{ \App\Support\UiText::get($ticket['status']) }}</strong></div>
        @if($copy === 'WAITER COPY')
            <div class="row outstanding"><span>{{ \App\Support\UiText::get('Outstanding Balance') }}</span><strong class="amount">{{ $currency }} {{ \App\Support\TicketAmountFormatter::format($ticket['outstanding']) }}</strong></div>
        @endif
    </article>
@endforeach
