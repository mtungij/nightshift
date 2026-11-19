@php
    $t = fn ($label) => \App\Support\UiText::get($label);
    $money = fn ($value) => \App\Support\TicketAmountFormatter::format((string) $value, $ticket['currency']);
    $direct = $ticket['counter_sale'] ?? false;
    $invoiceReceipt = $invoiceReceipt ?? false;
    $paymentLines = $ticket['payments'] ?? [];
    $businessDetails = array_filter([
        $ticket['receipt_header'] ?? null,
        $ticket['address'] ?? null,
        $ticket['phone'] ?? null,
        filled($ticket['tin'] ?? null) ? $t('TIN').' '.$ticket['tin'] : null,
        filled($ticket['vrn'] ?? null) ? $t('VRN').' '.$ticket['vrn'] : null,
    ], fn ($value) => filled($value));
    $metadata = [
        'Invoice' => $ticket['invoice'],
        'Date/Time' => $ticket['issued_at'] ?? null,
        'Cashier' => $ticket['cashier'] ?? null,
        'Counter' => ($ticket['show_location'] ?? true) ? $ticket['counter'] : null,
        'Sale type' => $t($direct ? 'Counter Sale' : 'Waiter Order'),
        'Business Unit' => $ticket['business_unit'] ?? null,
        ...($direct ? [] : ['Waiter' => $ticket['waiter'] ?? null, 'Table' => $ticket['table'] ?? null]),
        'Entered via' => $ticket['entered_via'] ?? null,
    ];
@endphp
@foreach($copies as $copy)
    <article class="ticket" data-copy="{{ $copy }}">
        <header class="ticket-header">
            <h1>{{ $ticket['business'] }}</h1>
            <p class="ticket-branch">{{ $ticket['branch'] }}</p>
            <p class="copy-label">{{ $t($copy) }}</p>
            @if($reprint)<span class="reprint">{{ $t('REPRINT') }}</span>@endif
            @if($copy === 'CUSTOMER RECEIPT' && $businessDetails !== [])
                <address class="business-details">
                    @foreach($businessDetails as $detail)<div>{{ $detail }}</div>@endforeach
                </address>
            @endif
        </header>

        <dl class="ticket-metadata ticket-rows">
            @foreach($metadata as $label => $value)
                @if(filled($value))<div class="row"><dt>{{ $t($label) }}</dt><dd>{{ $value }}</dd></div>@endif
            @endforeach
        </dl>
        @if(filled($ticket['order_note'] ?? null))
            <div class="order-note"><strong>{{ $t('Order Note') }}</strong><div>{{ $ticket['order_note'] }}</div></div>
        @endif

        @include($paperWidth === '58mm' ? 'partials.sales-tickets-58' : 'partials.sales-tickets-80')

        <dl class="ticket-totals ticket-rows">
            @if($copy === 'WAITER COPY' || $copy === 'CUSTOMER RECEIPT')
                <div class="row"><dt>{{ $t('Subtotal') }}</dt><dd>{{ $money($ticket['subtotal']) }}</dd></div>
                <div class="row"><dt>{{ $t('Discount') }}</dt><dd>{{ $money($ticket['discount']) }}</dd></div>
            @endif
            @if($invoiceReceipt)
                <div class="row"><dt>{{ $t('Returns') }}</dt><dd>{{ $money($ticket['returns']) }}</dd></div>
            @endif
            <div class="row total"><dt>{{ $t('TOTAL') }} {{ $ticket['currency'] }}</dt><dd>{{ $money($ticket['total']) }}</dd></div>
        </dl>

        @if($paymentLines !== [])
            <section class="ticket-payment">
                <h2>{{ $t('Payment Method') }}</h2>
                <dl class="ticket-rows">
                    @foreach($paymentLines as $payment)
                        <div class="row"><dt>{{ $t($payment['method']) }}</dt><dd>{{ $money($payment['amount']) }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endif
        <dl class="ticket-settlement ticket-rows">
            @if($direct || $invoiceReceipt)
                <div class="row"><dt>{{ $t('Amount Paid') }}</dt><dd>{{ $money($ticket['amount_paid'] ?? $ticket['total']) }}</dd></div>
            @endif
            @if($direct)
                <div class="row"><dt>{{ $t('Change') }}</dt><dd>{{ $money('0') }}</dd></div>
            @elseif($invoiceReceipt)
                <div class="row"><dt>{{ $t('Balance') }} {{ $ticket['currency'] }}</dt><dd>{{ $money($ticket['balance']) }}</dd></div>
            @endif
            <div class="row"><dt>{{ $t('Status') }}</dt><dd class="ticket-status">@if($copy === 'CASHIER COPY' && $ticket['status'] !== 'PAID'){{ $t('ISSUED') }} / @endif{{ $t($ticket['status']) }}</dd></div>
            @if($copy === 'WAITER COPY')
                <div class="row outstanding"><dt>{{ $t($paperWidth === '58mm' ? 'Outstanding Balance' : 'Waiter outstanding after this order') }}</dt><dd>{{ $money($ticket['outstanding']) }}</dd></div>
            @endif
        </dl>

        @if($copy === 'CUSTOMER RECEIPT')
            <footer class="ticket-footer">
                @if(filled($ticket['receipt_footer'] ?? null))
                    <span class="configured-footer">{{ $ticket['receipt_footer'] }}</span>
                @else
                    <strong>{{ $t('Thank you!') }}</strong><br>{{ $t('Welcome again.') }}
                @endif
            </footer>
        @endif
    </article>
@endforeach
