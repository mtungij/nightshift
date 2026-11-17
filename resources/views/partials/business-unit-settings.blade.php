@include('partials.order-collection-mode', [
    'mode' => $unit?->order_collection_mode ?? \App\Domain\OrderCollectionMode::WaiterAccount,
    'descriptionId' => 'collection-mode-'.($unit?->id ?? 'new'),
])
<label class="form-field">
    {{ $t('Maximum Active Cashiers') }}
    <input name="max_active_cashiers" type="number" min="1" max="65535" required value="{{ $unit?->max_active_cashiers ?? 1 }}">
</label>
<label class="form-field">
    {{ $t('Advanced locations enabled') }}
    <select name="advanced_locations_enabled">
        <option value="0" @selected(!$unit?->advanced_locations_enabled)>{{ $t('No') }}</option>
        <option value="1" @selected($unit?->advanced_locations_enabled)>{{ $t('Yes') }}</option>
    </select>
</label>
