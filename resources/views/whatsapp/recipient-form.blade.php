<form data-whatsapp-form="recipient" data-recipient-id="{{ $recipient?->id }}">
    <fieldset @disabled(!auth()->user()->hasPermission('whatsapp.manage_recipients')) class="form-grid">
        <label class="form-field"><span>{{ \App\Support\UiText::get('Name') }}</span><input name="name" value="{{ $recipient?->name }}" required maxlength="255"></label>
        <label class="form-field"><span>{{ \App\Support\UiText::get('Phone Number') }}</span><input name="phone" type="tel" value="{{ $recipient?->phone }}" required placeholder="0629123456"></label>
        <label class="form-field"><span>{{ \App\Support\UiText::get('Label / Role') }}</span><input name="label" value="{{ $recipient?->label }}" maxlength="80" placeholder="{{ \App\Support\UiText::get('Owner / General Manager / Finance Manager') }}"></label>
        @if(auth()->user()->hasPermission('all-branches'))
            <label class="form-field"><span>{{ \App\Support\UiText::get('All Branches') }}</span><input name="all_branches" type="checkbox" @checked($recipient?->all_branches ?? false)></label>
        @endif
        <fieldset class="whatsapp-branch-list"><legend>{{ \App\Support\UiText::get('Selected Branches') }}</legend>@foreach($branches as $branch)<label><input name="branch_ids[]" type="checkbox" value="{{ $branch->id }}" @checked(in_array($branch->id, $recipient?->branch_ids ?? [], true))> {{ $branch->name }}</label>@endforeach</fieldset>
        @foreach(['daily_report' => 'Receive Daily Report', 'shift_reports' => 'Receive Shift Reports', 'critical_alerts' => 'Receive Critical Alerts', 'active' => 'Active'] as $key => $label)
            <label class="form-field"><span>{{ \App\Support\UiText::get($label) }}</span><input type="checkbox" name="{{ $key }}" @checked($recipient?->$key ?? true)></label>
        @endforeach
        @can('whatsapp.manage_recipients')<button type="submit" class="button primary">{{ \App\Support\UiText::get('Save recipient') }}</button>@endcan
    </fieldset>
</form>
