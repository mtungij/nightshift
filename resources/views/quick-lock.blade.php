@component('layouts.nightshift')
    @include('partials.language-switch')
    <main class="panel" style="max-width:420px;margin:10vh auto;padding:24px">
        <h1>{{ \App\Support\UiText::get('Nightshift · Quick Lock') }}</h1><p>{{ auth()->user()->name }}, {{ \App\Support\UiText::get('Enter your operational PIN to continue.') }}</p>
        <form method="POST" action="{{ route('quick-lock.unlock') }}">@csrf
            <label class="form-field"><span>{{ \App\Support\UiText::get('4-digit PIN') }}</span><input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" autocomplete="off" required autofocus></label>
            @error('pin')<p role="alert">{{ \App\Support\UiText::get($message) }}</p>@enderror
            <button class="button primary" type="submit">{{ \App\Support\UiText::get('Unlock workspace') }}</button>
        </form>
        <form method="POST" action="{{ route('logout') }}" style="margin-top:16px">@csrf<button type="submit" class="button">{{ \App\Support\UiText::get('Log out') }}</button></form>
    </main>
@endcomponent
