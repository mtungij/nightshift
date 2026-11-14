@component('layouts.nightshift')
@php($t = fn($text) => \App\Support\UiText::get($text))
<meta name="csrf-token" content="{{ csrf_token() }}">
@include('partials.auth-toolbar')
<main class="reconciliation-page custody-page">
<header class="recon-heading"><x-nightshift-brand href="/dashboard" /><a class="button" href="/dashboard#inventory">{{ $t('Inventory') }}</a></header>
<h1>{{ $product->name }} · {{ $t('Product availability and unit prices') }}</h1>
<p>{{ $t('Blank unit price uses the company selling price. Only checked Business Units can use this product.') }}</p>
<div role="status" data-business-unit-status></div><section class="panel">
@foreach($units as $unit)@php($setting = $availability->where('business_unit_id',$unit->id)->firstWhere('product_id',$product->id))<form data-business-unit-action="product" class="toolbar custody-filters"><input type="hidden" name="product_id" value="{{ $product->id }}"><input type="hidden" name="business_unit_id" value="{{ $unit->id }}"><span>{{ $branches->firstWhere('id',$unit->branch_id)?->name }} / {{ $unit->name }}</span><label class="form-field">{{ $t('Available in') }}<select name="available"><option value="1" @selected($setting && $setting->available)>{{ $t('Yes') }}</option><option value="0" @selected(!$setting || !$setting->available)>{{ $t('No') }}</option></select></label>@foreach($product->sellingUnits as $i => $selling)<input type="hidden" name="prices[{{ $i }}][product_unit_id]" value="{{ $selling->id }}"><label class="form-field">{{ $selling->unit->name }} · {{ $t('Unit Price') }}<input name="prices[{{ $i }}][selling_price]" type="number" min="0" step="0.01" placeholder="{{ $selling->selling_price }}" value="{{ $prices->where('business_unit_id',$unit->id)->firstWhere('product_unit_id',$selling->id)?->selling_price }}"></label>@endforeach<button class="button">{{ $t('Save') }}</button></form>@endforeach
</section></main>
@endcomponent
