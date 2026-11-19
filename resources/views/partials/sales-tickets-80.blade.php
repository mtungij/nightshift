<table class="ticket-items">
    <colgroup><col class="item-name-col"><col class="item-quantity-col"><col class="item-price-col"><col class="item-total-col"></colgroup>
    <thead><tr>
        <th scope="col">{{ $t('Item') }}</th><th scope="col">{{ $t('Qty × Unit') }}</th><th scope="col">{{ $t('Unit price') }}</th><th scope="col">{{ $t('Line total') }}</th>
    </tr></thead>
    <tbody>
        @foreach($ticket['items'] as $item)
            <tr class="ticket-item">
                <td>
                    <span class="product-name">{{ $item['product_name'] }}</span>
                    @if(($item['returned_quantity'] ?? 0) > 0)
                        <small class="ticket-return">{{ $t('Returned') }} {{ \App\Support\QuantityFormatter::format($item['returned_quantity']) }}</small>
                    @endif
                </td>
                <td>{{ \App\Support\QuantityFormatter::format($item['quantity']) }} × {{ $item['unit_name'] }}</td>
                <td>{{ $money($item['unit_price']) }}</td>
                <td><strong>{{ $money($item['total']) }}</strong></td>
            </tr>
        @endforeach
    </tbody>
</table>
