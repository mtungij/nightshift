<x-layouts::nightshift>
    @include('partials.language-switch')
    <main class="onboarding-container">
        <div class="page-heading"><div><p class="eyebrow">{{ \App\Support\UiText::get('YOUR BUSINESS, CONNECTED.') }}</p><h1>{{ \App\Support\UiText::get('Business profile') }}</h1><p>{{ \App\Support\UiText::get('Company details and the information printed on your receipts.') }}</p></div><div class="onboarding-actions"><button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="Switch theme"><svg class="icon theme-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg><svg class="icon theme-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M20.5 13A9 9 0 0 1 11 3.5 9 9 0 1 0 20.5 13Z"/></svg></button><a class="button" href="/dashboard#setup">{{ \App\Support\UiText::get('Setup guide') }}</a><a class="button" href="/dashboard#settings">{{ \App\Support\UiText::get('Back to workspace') }}</a></div></div>
        @if(session('status'))<div class="info-box" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="info-box red" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route('business.update') }}" enctype="multipart/form-data">
            @csrf
            <section class="panel"><div class="panel-header"><h2>{{ \App\Support\UiText::get('Business information') }}</h2></div><div class="panel-body">@include('business.fields')</div></section>
            <div class="onboarding-actions"><button class="button primary" type="submit">{{ \App\Support\UiText::get('Save business profile') }}</button><a class="button" href="/dashboard#branches">{{ \App\Support\UiText::get('Edit branch information') }}</a><a class="button" href="/dashboard#counters">{{ \App\Support\UiText::get('Manage stores & counters') }}</a></div>
            <p data-theme-status role="status" class="modal-description"></p>
        </form>
    </main>
</x-layouts::nightshift>
