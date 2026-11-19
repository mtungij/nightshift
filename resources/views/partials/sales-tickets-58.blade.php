<div class="ticket-items">
    <div class="item-detail item-head" aria-hidden="true">
        <span>{{ $t('Qty × Unit') }}</span><span>{{ $t('Unit price') }}</span><span>{{ $t('Line total') }}</span>
    </div>
    @foreach($ticket['items'] as $item)
        <div class="ticket-item">
            <div class="product-name">{{ $item['product_name'] }}</div>
            <div class="item-detail">
                <span>{{ \App\Support\QuantityFormatter::format($item['quantity']) }} × {{ $item['unit_name'] }}</span>
                <span>{{ $money($item['unit_price']) }}</span>
                <strong>{{ $money($item['total']) }}</strong>
            </div>
            @if(($item['returned_quantity'] ?? 0) > 0)
                <small class="ticket-return">{{ $t('Returned') }} {{ \App\Support\QuantityFormatter::format($item['returned_quantity']) }}</small>
            @endif
        </div>
    @endforeach
</div>
