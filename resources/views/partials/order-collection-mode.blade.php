<div class="form-field" data-collection-mode>
    <label for="{{ $descriptionId }}-select">{{ $t('Order Collection Mode') }}</label>
    <select id="{{ $descriptionId }}-select" name="order_collection_mode" aria-describedby="{{ $descriptionId }}">
        <option value="WAITER_ACCOUNT" @selected($mode === \App\Domain\OrderCollectionMode::WaiterAccount)>{{ $t('Waiter Account') }}</option>
        <option value="PAID_TICKET" @selected($mode === \App\Domain\OrderCollectionMode::PaidTicket)>{{ $t('Paid Ticket') }}</option>
    </select>
    <small id="{{ $descriptionId }}" class="unit-help" aria-live="polite">
        <span data-mode-description="WAITER_ACCOUNT" @if($mode !== \App\Domain\OrderCollectionMode::WaiterAccount) hidden @endif>{{ $t('Order becomes waiter debt until cashier settlement.') }}</span>
        <span data-mode-description="PAID_TICKET" @if($mode !== \App\Domain\OrderCollectionMode::PaidTicket) hidden @endif>{{ $t('Order is paid immediately; cashier collects cash using the ticket number.') }}</span>
    </small>
</div>
