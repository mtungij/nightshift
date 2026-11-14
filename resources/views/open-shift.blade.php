@component('layouts.nightshift')
    @include('partials.language-switch')
    <main class="panel" style="max-width:480px;margin:8vh auto;padding:24px" data-open-shift>
        <h1>{{ \App\Support\UiText::get('Open shift') }}</h1>
        <p role="status">{{ \App\Support\UiText::get('Open your shift to start working.') }}</p>
        <p>{{ auth()->user()->name }}</p>
        @if($errors->any())
            <div class="info-box red" role="alert">{{ \App\Support\UiText::get($errors->first()) }}</div>
        @endif
        <form method="POST" action="{{ route('shift.open.store') }}" data-shift-capacity="{{ $cashier ? 'CASHIER' : 'WAITER' }}">@csrf
            <label class="form-field"><span>{{ \App\Support\UiText::get('Business Unit') }}</span>
                <select name="business_unit_id" required data-shift-unit>
                    <option value="">{{ \App\Support\UiText::get('Select Business Unit') }}</option>
                    @foreach($units as $unit)
                        <option value="{{ $unit->id }}" data-advanced="{{ $unit->advanced_locations_enabled ? '1' : '0' }}" data-active-cashiers="{{ $unitOperations->activeCashiers($unit->id) }}" data-max-active-cashiers="{{ $unit->max_active_cashiers }}" @selected(old('business_unit_id') == $unit->id)>{{ $branches[$unit->branch_id] }} · {{ $unit->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="form-field" data-advanced-shift-location hidden><span>{{ \App\Support\UiText::get('Stock Location') }}</span>
                <select name="stock_location_id" disabled>
                    <option value="">{{ \App\Support\UiText::get('Select counter') }}</option>
                    @foreach($counters as $counter)
                        <option value="{{ $counter->id }}" data-unit="{{ $counter->business_unit_id }}" data-active-cashiers="{{ $counter->active_cashiers }}" data-max-active-cashiers="{{ $counter->max_active_cashiers }}" @selected(old('stock_location_id') == $counter->id)>{{ $counter->name }}</option>
                    @endforeach
                </select>
            </label>
            <p data-cashier-occupancy role="status"></p><p data-cashier-capacity-message class="form-help"></p>
            @if($cashier)
                <p>{{ \App\Support\UiText::get('Opening cash defaults to zero. Confirmed handover cash is applied automatically.') }}</p>
                @foreach($handovers as $handover)
                <div class="info-box"><strong>{{ \App\Support\UiText::get('Cash awaiting receipt') }}: {{ $handover['amount'] }}</strong><p>{{ $handover['from_name'] }} · {{ $handover['business_unit'] ?? '—' }} @if($handover['show_location'] ?? true) · {{ $handover['counter'] }} @endif · {{ \App\Support\UiText::get($handover['status']) }}</p><p>{{ \App\Support\UiText::get('Opening cash') }}: {{ $handover['received_amount'] ?? $handover['amount'] }} · {{ \App\Support\UiText::get('Cash Difference') }}: {{ $handover['difference'] ?? '—' }}</p>
                    <label><input type="radio" name="handover_id" value="{{ $handover['id'] }}" @disabled($handover['status'] === 'DISPUTED')> {{ \App\Support\UiText::get('Confirm Received & Open Shift') }}</label>
                    <a class="button" href="/cash-handovers">{{ \App\Support\UiText::get('Report a handover difference') }}</a>
                </div>
                @endforeach
            @endif
            @if($units->isEmpty())<p role="alert">{{ \App\Support\UiText::get('No permitted Business Units. Ask your manager to assign a Business Unit.') }}</p>@endif
            <button class="button primary" type="submit" @disabled($units->isEmpty())>{{ \App\Support\UiText::get('Open shift') }}</button>
        </form>
        <form method="POST" action="{{ route('logout') }}" style="margin-top:16px">@csrf<button type="submit" class="button">{{ \App\Support\UiText::get('Log out') }}</button></form>
    </main>
@endcomponent
