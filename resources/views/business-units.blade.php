@component('layouts.nightshift')
@php($t = fn ($text) => \App\Support\UiText::get($text))
@include('partials.workspace-mount')
<div class="business-units-page" data-workspace-page="business-units" hidden>
<div class="page-heading">
    <div>
        <div class="eyebrow">{{ $t('Business') }}</div>
        <h1>{{ $t('Business Units') }}</h1>
        <p>{{ $t('Organize Bar, Restaurant and other operating areas.') }}</p>
    </div>
    <a class="button" href="/dashboard">{{ $t('Dashboard') }}</a>
</div>
<div role="status" aria-live="polite" data-business-unit-status></div>
<section class="panel unit-panel" aria-labelledby="add-unit-heading">
    <div class="unit-section-heading"><h2 id="add-unit-heading">{{ $t('Add Business Unit') }}</h2></div>
    <form data-business-unit-action="save" class="unit-form unit-create">
        <input type="hidden" name="active" value="1">
        <div class="unit-field-grid">
            <label class="form-field">
                {{ $t('Branch') }}
                <select name="branch_id" required>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="form-field">{{ $t('Name') }}<input name="name" required maxlength="100"></label>
            <label class="form-field">{{ $t('Code') }}<input name="code" required pattern="[A-Z0-9_-]+" maxlength="30"></label>
            @include('partials.business-unit-settings', ['unit' => null])
        </div>
        <div class="unit-actions">
            <button class="button primary" type="submit">{{ $t('Save Business Unit') }}</button>
        </div>
    </form>
</section>
<section class="panel unit-panel" aria-labelledby="saved-units-heading">
    <div class="unit-section-heading"><h2 id="saved-units-heading">{{ $t('Business Units') }}</h2></div>
    <div class="unit-list">
        @forelse($units as $unit)
            <form data-business-unit-action="save" class="unit-record">
                <input type="hidden" name="id" value="{{ $unit->id }}">
                <input type="hidden" name="branch_id" value="{{ $unit->branch_id }}">
                <div class="unit-record-header">
                    <div class="unit-record-identity">
                        <h3>{{ $unit->name }}</h3>
                        <p>{{ $branches->firstWhere('id', $unit->branch_id)?->name }}</p>
                    </div>
                    <div class="unit-badges">
                        <span class="badge {{ $unit->active ? 'green' : 'gray' }}">{{ $t($unit->active ? 'Active' : 'Inactive') }}</span>
                        @php($paidTicket = $unit->order_collection_mode === \App\Domain\OrderCollectionMode::PaidTicket)
                        <span class="badge {{ $paidTicket ? 'amber' : 'blue' }}">{{ $t($paidTicket ? 'Paid Ticket' : 'Waiter Account') }}</span>
                    </div>
                </div>
                <div class="unit-field-grid">
                    <label class="form-field">{{ $t('Name') }}<input name="name" value="{{ $unit->name }}" required></label>
                    <label class="form-field">{{ $t('Code') }}<input name="code" value="{{ $unit->code }}" required></label>
                    <label class="form-field">
                        {{ $t('Status') }}
                        <select name="active">
                            <option value="1" @selected($unit->active)>{{ $t('Active') }}</option>
                            <option value="0" @selected(!$unit->active)>{{ $t('Inactive') }}</option>
                        </select>
                    </label>
                    @include('partials.business-unit-settings', ['unit' => $unit])
                </div>
                <div class="unit-record-footer">
                    <div class="unit-record-identity">
                        <span class="field-caption">{{ $t('Default operational stock location') }} · {{ $t('System-managed') }}</span>
                        <p>{{ $locations->firstWhere('id', $unit->default_stock_location_id)?->name ?? $t('This Business Unit has no operational stock location configured.') }}</p>
                        @if($unit->location_cutover_note)<p role="status">{{ $t('Legacy locations require supervised cutover. No stock was moved.') }}</p>@endif
                    </div>
                    <div class="unit-actions">
                        <button class="button" type="submit">{{ $t('Save') }}</button>
                    </div>
                </div>
            </form>
        @empty
            <p class="unit-help">{{ $t('No Business Units in this scope.') }}</p>
        @endforelse
    </div>
</section>
@if($units->contains('advanced_locations_enabled', true))
<section class="panel unit-panel"><h2>{{ $t('Advanced Stock Locations') }}</h2>
@foreach($locations as $location)
@if(!$location->is_shared && !$units->firstWhere('id', $location->business_unit_id)?->advanced_locations_enabled) @continue @endif
<form data-business-unit-action="location" class="unit-form"><input type="hidden" name="id" value="{{ $location->id }}"><div class="unit-identity"><strong>{{ $location->name }}</strong><small>{{ $branches->firstWhere('id', $location->branch_id)?->name }}</small>
<span class="field-caption">{{ $t('Current Business Unit') }}</span><span>{{ $location->is_shared ? $t('Shared across Business Units') : $units->firstWhere('id', $location->business_unit_id)?->name }}</span>
<span class="badge {{ $location->active ? 'green' : 'gray' }}">{{ $t($location->active ? 'Active' : 'Inactive') }}</span>
@if($location->is_shared)<span class="badge blue">{{ $t('Shared store') }}</span>@endif
@if($location->type === 'COUNTER')<small data-counter-identity-occupancy>{{ $t('Active Cashiers') }}: {{ $counterDisplays[$location->id]['active_cashier_count'] }} / {{ $counterDisplays[$location->id]['max_active_cashiers'] }}</small>@endif</div>
<fieldset class="unit-options"><legend>{{ $t('Business Units') }}</legend>@foreach($units->where('branch_id',$location->branch_id) as $unit)<label><input type="checkbox" name="unit_ids[]" value="{{ $unit->id }}" @checked($location->business_unit_id === $unit->id || \Illuminate\Support\Facades\DB::table('business_unit_location')->where('stock_location_id',$location->id)->where('business_unit_id',$unit->id)->exists())><span>{{ $unit->name }}</span></label>@endforeach</fieldset>
<label class="form-field">{{ $t('Shared store') }}<select name="is_shared"><option value="0" @selected(!$location->is_shared)>{{ $t('No') }}</option>@if($location->type === 'STORE')<option value="1" @selected($location->is_shared)>{{ $t('Yes') }}</option>@endif</select></label><button class="button">{{ $t('Save') }}</button></form>
@if($location->type === 'COUNTER')
@can('manage-counters')
<form data-cashier-limit data-saved-limit="{{ $counterDisplays[$location->id]['max_active_cashiers'] }}" class="cashier-limit-form"><p data-counter-occupancy>{{ $t('Active Cashiers') }}: {{ $counterDisplays[$location->id]['active_cashier_count'] }} / {{ $counterDisplays[$location->id]['max_active_cashiers'] }}</p><span class="badge amber" data-counter-full @if($counterDisplays[$location->id]['active_cashier_count'] < $counterDisplays[$location->id]['max_active_cashiers']) hidden @endif>{{ $t('Cashier limit reached') }}</span><input type="hidden" name="id" value="{{ $location->id }}"><label class="form-field"><span>{{ $location->name }} · {{ $t('Maximum Active Cashiers') }}</span><input name="max_active_cashiers" type="number" min="1" max="65535" step="1" required value="{{ $counterDisplays[$location->id]['max_active_cashiers'] }}"><small>{{ $t('Maximum number of cashier shifts that may be open on this counter at the same time.') }}</small></label><button class="button" type="submit">{{ $t('Save') }}</button><p data-cashier-limit-status role="status" aria-live="polite"></p></form>
@endcan
@endif
@endforeach
</section>
@endif
@can('transfer-stock')
@if($units->where('active', false)->isNotEmpty())
<section class="panel unit-panel"><h2>{{ $t('Supervised stock cutover') }}</h2><p>{{ $t('Allocate known legacy stock using audited transfers. Close shifts and unresolved work first. History is preserved.') }}</p>
<form data-business-unit-action="cutover" class="unit-form">
<label class="form-field">{{ $t('Legacy stock location') }}<select name="source_location_id" required>@foreach($locations as $location)@if($units->where('active',false)->contains('id',$location->business_unit_id))<option value="{{ $location->id }}">{{ $branches->firstWhere('id',$location->branch_id)?->name }} / {{ $location->name }}</option>@endif @endforeach</select></label>
<label class="form-field">{{ $t('To Business Unit') }}<select name="destination_business_unit_id" required>@foreach($units->where('active',true) as $unit)<option value="{{ $unit->id }}">{{ $branches->firstWhere('id',$unit->branch_id)?->name }} / {{ $unit->name }}</option>@endforeach</select></label>
<label class="form-field">{{ $t('Product') }}<select name="cutover_product_unit_id" required>@foreach($products as $product)@foreach($product->sellingUnits as $selling)<option value="{{ $selling->id }}">{{ $product->name }} / {{ $selling->unit->name }}</option>@endforeach @endforeach</select></label>
<label class="form-field">{{ $t('Quantity') }}<input name="cutover_quantity" type="number" min="0.000001" step="0.000001" required></label>
<label class="form-field">{{ $t('Notes') }}<textarea name="notes" minlength="8" maxlength="2000" required></textarea></label>
<button class="button" type="submit">{{ $t('Transfer allocated stock') }}</button></form></section>
@endif
@endcan
<section class="panel unit-panel" aria-labelledby="staff-business-units-heading">
    <div class="unit-section-heading">
        <h2 id="staff-business-units-heading">{{ $t('Staff Business Units') }}</h2>
        <p>{{ $t('Assign branches and Business Units. Simple-mode staff use the unit stock automatically. Terminals require one Business Unit.') }}</p>
    </div>
    <div class="unit-list">
        @forelse($users as $employee)
            @php($role = $employee->role?->name)
            @if(in_array($role, ['Company Admin / Owner', 'Super Admin']))
                <article class="unit-record unit-record-header">
                    <div class="unit-record-identity">
                        <h3>{{ $employee->name }}</h3>
                        <p>{{ $t($role) }}</p>
                    </div>
                    <span class="badge blue">{{ $t('All Business Units') }}</span>
                </article>
                @continue
            @endif
            @php($editable = in_array($role, [...config('business.staff_roles'), 'POS Terminal']))
            <form data-business-unit-action="staff" data-scope-role="{{ $role }}" class="unit-record staff-unit-card">
                <input type="hidden" name="id" value="{{ $employee->id }}">
                <div class="unit-record-identity">
                    <h3>{{ $employee->name }}</h3>
                    <p>{{ $t($role ?? '') }}</p>
                    @if($employee->isPosTerminal())
                        <p>{{ $t('Counter') }} · {{ $locations->firstWhere('id', $employee->terminal_stock_location_id)?->name ?? $t('Deleted or unavailable record') }}</p>
                    @endif
                </div>
                @if($editable)
                    <details class="scope-picker">
                        <summary><span>{{ $t('Branch') }}</span><span data-scope-summary></span></summary>
                        <fieldset class="unit-options">
                            <legend class="sr-only">{{ $t('Branch') }}</legend>
                            @foreach($branches as $branch)
                                <label>
                                    <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked($employee->branches()->whereKey($branch->id)->exists() || ($employee->isPosTerminal() && $employee->canAccessBranch($branch->id)))>
                                    <span>{{ $branch->name }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    </details>
                @else
                    <div class="unit-record-identity">
                        <span class="field-caption">{{ $t('Branch') }}</span>
                        <p>{{ $employee->branches()->pluck('name')->implode(', ') }}</p>
                    </div>
                @endif
                <details class="scope-picker">
                    <summary><span>{{ $t('Business Units') }}</span><span data-scope-summary></span></summary>
                    <fieldset class="unit-options">
                        <legend class="sr-only">{{ $t('Business Units') }}</legend>
                        @foreach($units as $unit)
                            <label data-option-branch="{{ $unit->branch_id }}">
                                <input type="checkbox" name="unit_ids[]" value="{{ $unit->id }}" @checked($employee->canAccessBusinessUnit($unit->id))>
                                <span>{{ $branches->firstWhere('id', $unit->branch_id)?->name }} / {{ $unit->name }}</span>
                            </label>
                        @endforeach
                        <p data-scope-empty hidden>{{ $t('No options in the selected scope.') }}</p>
                    </fieldset>
                </details>
                @if($editable)
                    <label class="form-field">
                        {{ $t('Status') }}
                        <select name="active">
                            <option value="1" @selected($employee->active)>{{ $t('Active') }}</option>
                            <option value="0" @selected(!$employee->active)>{{ $t('Inactive') }}</option>
                        </select>
                    </label>
                @endif
                @if($role === 'Cashier')
                    <details class="scope-picker staff-unit-counters" data-scope-kind="cashier">
                        <summary><span>{{ $t('Counters') }}</span><span data-scope-summary></span></summary>
                        <fieldset class="unit-options">
                            <legend class="sr-only">{{ $t('Counters') }}</legend>
                            @foreach($locations->where('type', 'COUNTER') as $counter)
                                @if(!$units->firstWhere('id', $counter->business_unit_id)?->advanced_locations_enabled) @continue @endif
                                <label data-option-branch="{{ $counter->branch_id }}" data-option-unit="{{ $counter->business_unit_id }}">
                                    <input type="checkbox" name="counter_ids[]" value="{{ $counter->id }}" @checked($employee->canAccessCounter($counter->id))>
                                    <span>{{ $units->firstWhere('id', $counter->business_unit_id)?->name }} / {{ $counter->name }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    </details>
                @endif
                <div class="unit-actions staff-unit-actions">
                    <button class="button" type="submit">{{ $t('Save') }}</button>
                </div>
            </form>
        @empty
            <p class="unit-help">{{ $t('No staff in this scope.') }}</p>
        @endforelse
    </div>
</section>
<section class="panel unit-panel" aria-labelledby="unit-products-heading" data-unit-products>
    <div class="unit-section-heading product-unit-heading">
        <div>
            <h2 id="unit-products-heading">{{ $t('Product availability and unit prices') }}</h2>
            <p>{{ $t('Blank unit price uses the company selling price. Only checked Business Units can use this product.') }}</p>
        </div>
        @if($products->isNotEmpty())
            <label class="form-field">
                <span class="sr-only">{{ $t('Search products') }}</span>
                <input type="search" placeholder="{{ $t('Search products') }}" autocomplete="off" data-unit-product-search>
            </label>
        @endif
    </div>
    @php($productAvailability = $availability->groupBy('product_id'))
    @php($unitPrices = $prices->groupBy('business_unit_id')->map->keyBy('product_unit_id'))
    <div class="unit-list">
        @foreach($products as $product)
            @php($settings = $productAvailability->get($product->id, collect())->keyBy('business_unit_id'))
            @php($availableUnits = $units->filter(fn ($unit) => $settings->get($unit->id)?->available))
            <details class="unit-record unit-product" data-unit-product="{{ $product->name }} {{ $product->sku }}">
                <summary>
                    <span class="unit-product-name">{{ $product->name }}</span>
                    <span class="unit-badges">
                        @forelse($availableUnits as $unit)
                            <span class="badge blue">{{ $unit->name }}</span>
                        @empty
                            <span class="unit-help">{{ $t('Unavailable in these Business Units') }}</span>
                        @endforelse
                    </span>
                </summary>
                <div class="unit-product-rows">
                    @foreach($units as $unit)
                        @php($available = $settings->get($unit->id)?->available)
                        <form data-business-unit-action="product" class="unit-product-form">
                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                            <input type="hidden" name="business_unit_id" value="{{ $unit->id }}">
                            <div class="unit-record-identity">
                                <h3>{{ $unit->name }}</h3>
                                <p>{{ $branches->firstWhere('id', $unit->branch_id)?->name }}</p>
                            </div>
                            <label class="form-field">
                                {{ $t('Available in') }}
                                <select name="available">
                                    <option value="1" @selected($available)>{{ $t('Yes') }}</option>
                                    <option value="0" @selected(!$available)>{{ $t('No') }}</option>
                                </select>
                            </label>
                            @if($product->sellingUnits->isNotEmpty())
                                <fieldset class="unit-price-fields">
                                    <legend class="field-caption">{{ $t('Price overrides') }}</legend>
                                    <div class="unit-price-grid">
                                        @foreach($product->sellingUnits as $i => $selling)
                                            <input type="hidden" name="prices[{{ $i }}][product_unit_id]" value="{{ $selling->id }}">
                                            <label class="form-field">
                                                {{ $selling->unit->name }}<span class="sr-only"> · {{ $t('Unit Price') }}</span>
                                                <input name="prices[{{ $i }}][selling_price]" type="number" min="0" step="0.01" placeholder="{{ $selling->selling_price }}" value="{{ $unitPrices->get($unit->id)?->get($selling->id)?->selling_price }}">
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endif
                            <div class="unit-actions">
                                <button class="button" type="submit">{{ $t('Save') }}</button>
                            </div>
                        </form>
                    @endforeach
                </div>
            </details>
        @endforeach
        <p class="unit-help" data-unit-product-empty role="status" @if($products->isNotEmpty()) hidden @endif>{{ $t('No matching products.') }}</p>
    </div>
</section>
</div>
<noscript>{{ $t('Please enable JavaScript to use the Nightshift workspace.') }}</noscript>
@endcomponent
