@props(['href' => null])
<a href="{{ $href ?? route('home') }}" aria-label="Nightshift" {{ $attributes->class('nightshift-brand') }}>
    <x-app-logo-icon alt="" class="nightshift-brand-mark" />
    <span>NIGHTSHIFT<small>{{ \App\Support\UiText::get('BAR MANAGEMENT') }}</small></span>
</a>
