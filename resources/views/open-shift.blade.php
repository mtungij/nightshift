@component('layouts.nightshift')
    @include('partials.language-switch')
    <main class="panel" style="max-width:480px;margin:8vh auto;padding:24px" data-open-shift>
        <h1>{{ \App\Support\UiText::get('Open shift') }}</h1>
        <p role="status">{{ \App\Support\UiText::get('Open your shift to start working.') }}</p>
        <p>{{ auth()->user()->name }}</p>
        @if($errors->any())
            <div class="info-box red" role="alert">{{ \App\Support\UiText::get($errors->first()) }}</div>
        @endif
        <form method="POST" action="{{ route('shift.open.store') }}">@csrf
            <label class="form-field"><span>{{ \App\Support\UiText::get('Counter') }}</span>
                <select name="stock_location_id" required>
                    <option value="">{{ \App\Support\UiText::get('Select counter') }}</option>
                    @foreach($counters as $counter)
                        <option value="{{ $counter->id }}" @selected(old('stock_location_id') == $counter->id)>{{ $branches[$counter->branch_id] }} · {{ $counter->name }}</option>
                    @endforeach
                </select>
            </label>
            @if($cashier)
                <label class="form-field"><span>{{ \App\Support\UiText::get('Opening cash') }} ({{ auth()->user()->company->currency }})</span>
                    <input name="opening_cash" type="number" inputmode="decimal" min="0" step="0.01" value="{{ old('opening_cash') }}" required>
                </label>
            @endif
            @if($counters->isEmpty())<p role="alert">{{ \App\Support\UiText::get('No permitted counters. Ask your manager to assign a counter.') }}</p>@endif
            <button class="button primary" type="submit" @disabled($counters->isEmpty())>{{ \App\Support\UiText::get('Open shift') }}</button>
        </form>
        <form method="POST" action="{{ route('logout') }}" style="margin-top:16px">@csrf<button type="submit" class="button">{{ \App\Support\UiText::get('Log out') }}</button></form>
    </main>
@endcomponent
