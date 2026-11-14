<form data-whatsapp-form="recipient" data-recipient-id="{{ $recipient?->id }}">
    <fieldset @disabled(!auth()->user()->hasPermission('whatsapp.manage_recipients')) class="form-grid">
        <label class="form-field"><span>{{ \App\Support\UiText::get('Name') }}</span><input name="name" value="{{ $recipient?->name }}" required maxlength="255"></label>
        <label class="form-field"><span>{{ \App\Support\UiText::get('Phone Number') }}</span><input name="phone" type="tel" value="{{ $recipient?->phone }}" required placeholder="0629123456"></label>
        <label class="form-field"><span>{{ \App\Support\UiText::get('Label / Role') }}</span><input name="label" value="{{ $recipient?->label }}" maxlength="80" placeholder="{{ \App\Support\UiText::get('Owner / General Manager / Finance Manager') }}"></label>
        @if(auth()->user()->hasPermission('all-branches'))
            <label class="form-field"><span>{{ \App\Support\UiText::get('All Branches') }}</span><input name="all_branches" type="checkbox" @checked($recipient?->all_branches ?? false)></label>
        @endif
        <fieldset class="whatsapp-branch-list"><legend>{{ \App\Support\UiText::get('Selected Branches') }}</legend>@foreach($branches as $branch)<label><input name="branch_ids[]" type="checkbox" value="{{ $branch->id }}" @checked(in_array($branch->id, $recipient?->branch_ids ?? [], true))> {{ $branch->name }}</label>@endforeach</fieldset>
        @if(auth()->user()->hasPermission('all-business-units'))<label class="form-field"><span>{{ \App\Support\UiText::get('All Business Units') }}</span><input type="checkbox" name="all_business_units" @checked($recipient?->all_business_units ?? true)></label>@endif
        <fieldset class="whatsapp-branch-list"><legend>{{ \App\Support\UiText::get('Business Units') }}</legend>@foreach($units as $unit)<label><input type="checkbox" name="business_unit_ids[]" value="{{ $unit->id }}" data-branch="{{ $unit->branch_id }}" @checked(in_array($unit->id,$recipient?->business_unit_ids ?? [],true))>{{ $unit->name }}</label>@endforeach</fieldset>
        <label class="form-field"><span>{{ \App\Support\UiText::get('Report Language') }}</span><select name="language"><option value="">{{ \App\Support\UiText::get('Use schedule language') }}</option><option value="sw" @selected($recipient?->language==='sw')>Kiswahili</option><option value="en" @selected($recipient?->language==='en')>English</option></select></label>
        <fieldset class="whatsapp-branch-list"><legend>{{ \App\Support\UiText::get('Enabled reports and alerts') }}</legend>@foreach(config('whatsapp.types') as $type)<label><input type="checkbox" name="enabled_types[]" value="{{ $type }}" @checked($recipient ? app(\App\Services\WhatsApp\RecipientService::class)->enabled($recipient,$type) : true)>{{ \App\Support\UiText::get(config('whatsapp.type_labels.'.$type,$type)) }}</label>@endforeach</fieldset>
        @foreach(['daily_report','shift_reports','critical_alerts'] as $key)<input type="hidden" name="{{ $key }}" value="{{ ($recipient?->$key ?? true) ? '1' : '0' }}">@endforeach
        <label class="form-field"><span>{{ \App\Support\UiText::get('Active') }}</span><input type="checkbox" name="active" @checked($recipient?->active ?? true)></label>
        @can('whatsapp.manage_recipients')<button type="submit" class="button primary">{{ \App\Support\UiText::get('Save recipient') }}</button>@endcan
    </fieldset>
</form>
