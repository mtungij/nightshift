<x-layouts::nightshift>
    @include('partials.language-switch')
    <main class="onboarding-container">
        <a href="{{ route('login') }}" class="brand"><img src="{{ asset('nightshift.svg') }}" width="36" height="36" alt=""><span>{{ \App\Support\UiText::get('NIGHTSHIFT') }}<small>{{ \App\Support\UiText::get('BAR MANAGEMENT') }}</small></span></a>
        <div class="page-heading"><div><p class="eyebrow">{{ \App\Support\UiText::get('YOUR BUSINESS. YOUR NIGHTSHIFT.') }}</p><h1>{{ \App\Support\UiText::get('Set up your bar account') }}</h1><p>{{ \App\Support\UiText::get('A workspace for your bar, your team, and every night ahead.') }}</p></div><a class="button" href="{{ route('login') }}">{{ \App\Support\UiText::get('Already registered? Log in') }}</a></div>
        @if($errors->any())<div class="info-box red" role="alert"><strong>{{ \App\Support\UiText::get('Please check these details:') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('business.store') }}" enctype="multipart/form-data">
            @csrf
            <section class="panel"><div class="panel-header"><h2>{{ \App\Support\UiText::get('1. Business information') }}</h2></div><div class="panel-body">@include('business.fields')</div></section>
            <section class="panel"><div class="panel-header"><h2>{{ \App\Support\UiText::get('2. First administrator / Owner') }}</h2></div><div class="panel-body"><div class="form-grid">
                @foreach(['first_name' => 'First name', 'last_name' => 'Last name', 'owner_email' => 'Owner email', 'owner_phone' => 'Owner phone'] as $name => $label)<label class="form-field"><span>{{ \App\Support\UiText::get($label) }}</span><input name="{{ $name }}" value="{{ old($name) }}" type="{{ $name === 'owner_email' ? 'email' : ($name === 'owner_phone' ? 'tel' : 'text') }}" required maxlength="{{ $name === 'owner_phone' ? 40 : ($name === 'owner_email' ? 255 : 100) }}"></label>@endforeach
                <label class="form-field"><span>{{ \App\Support\UiText::get('Username') }}</span><input name="username" value="{{ old('username') }}" required maxlength="100" pattern="[a-zA-Z0-9][a-zA-Z0-9._-]*" autocomplete="username"></label>
                <label class="form-field"><span>{{ \App\Support\UiText::get('4-digit PIN') }}</span><input name="pin" type="password" required minlength="4" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" autocomplete="off"></label>
            </div><p class="modal-description">{{ \App\Support\UiText::get('Your owner account can manage this business and its staff. Email verification is not required.') }}</p></div></section>
            <section class="panel"><div class="panel-header"><h2>{{ \App\Support\UiText::get('3. Choose your starting categories') }}</h2></div><div class="panel-body">
                <p class="modal-description">{{ \App\Support\UiText::get('Keep the categories you need. You can add, rename, reorder, or deactivate them later.') }}</p>
                <input type="hidden" name="categories_configured" value="1">
                <div class="checklist">@foreach(config('business.categories') as $category)<label><input type="checkbox" name="categories[]" value="{{ $category }}" @checked(in_array($category, old('categories_configured') ? old('categories', []) : config('business.categories'), true))>{{ $category }}</label>@endforeach</div>
                <div class="info-box">{{ \App\Support\UiText::get('Main Branch (MAIN), Main Store, Main Counter, and 11 common units will be ready for you.') }}</div>
            </div></section>
            <div class="onboarding-actions"><button type="submit" class="button primary" data-test="register-business">{{ \App\Support\UiText::get('Create business account') }}</button><span class="modal-description">{{ \App\Support\UiText::get('You’ll be signed in and guided through the remaining setup.') }}</span></div>
        </form>
    </main>
</x-layouts::nightshift>
