@php
    $profile = $company ?? null;
    $fields = [
        'business_name' => ['Business name', $profile?->name, 'text', true],
        'trading_name' => ['Trading name / Bar name', $profile?->trading_name ?? $profile?->name, 'text', true],
        'business_phone' => ['Business phone', $profile?->phone, 'tel', true],
        'business_email' => ['Business email', $profile?->email, 'email', true],
        'address' => ['Address', $profile?->address, 'text', true],
        'city' => ['Region / City', $profile?->city, 'text', true],
        'tin' => ['TIN (optional)', $profile?->tin, 'text', false],
        'vrn' => ['VRN (optional)', $profile?->vrn, 'text', false],
        'currency' => ['Currency', $profile?->currency ?? 'TZS', 'text', true],
        'timezone' => ['Timezone', $profile?->timezone ?? 'Africa/Dar_es_Salaam', 'text', true],
        'country' => ['Country', $profile?->country ?? 'Tanzania', 'text', true],
        'receipt_header' => ['Receipt header (optional)', $profile?->receipt_header, 'text', false],
        'receipt_footer' => ['Receipt footer message (optional)', $profile?->receipt_footer, 'text', false],
    ];
@endphp
<div class="form-grid">
    @foreach($fields as $name => [$label, $value, $type, $required])
        <label class="form-field"><span>{{ \App\Support\UiText::get($label) }}</span><input name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" @required($required) maxlength="{{ $name === 'currency' ? 3 : 255 }}" @if($name === 'currency') pattern="[A-Z]{3}" @endif @if($name === 'timezone') list="business-timezones" @endif></label>
    @endforeach
    <label class="form-field"><span>{{ \App\Support\UiText::get('Business type') }}</span><select name="business_type" required>@foreach(config('business.types') as $type)<option @selected(old('business_type', $profile?->business_type ?? 'Bar') === $type)>{{ $type }}</option>@endforeach</select></label>
    <label class="form-field"><span>{{ \App\Support\UiText::get('Default language') }}</span><select name="default_language" required>@foreach(['English', 'Swahili'] as $language)<option value="{{ $language }}" @selected(old('default_language', $profile?->default_language ?? 'English') === $language)>{{ \App\Support\UiText::get($language) }}</option>@endforeach</select></label>
    <label class="form-field"><span>{{ \App\Support\UiText::get('Logo (optional, JPG / PNG / WebP, up to 2 MB)') }}</span><input name="logo" type="file" accept="image/jpeg,image/png,image/webp"></label>
    @if($profile?->logo_path)<img class="business-logo" src="{{ route('business.logo') }}" alt="{{ $profile->trading_name ?? $profile->name }} logo">@endif
</div>
<datalist id="business-timezones"><option value="Africa/Dar_es_Salaam"><option value="Africa/Nairobi"><option value="Africa/Kampala"><option value="UTC"></datalist>
