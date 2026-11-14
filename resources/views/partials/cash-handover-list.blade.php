@php($showLocations = collect($handovers)->contains(fn($h) => $h['show_location'] ?? true))
<div class="table-scroll"><table class="data-table responsive-records"><thead><tr>@foreach(['Shift','Counter','Business Unit','From','Destination','Handover amount','Cash Received','Cash Difference','Receipt status','Actions'] as $label)@if($label !== 'Counter' || $showLocations)<th>{{ $t($label) }}</th>@endif@endforeach</tr></thead><tbody>
@forelse($handovers as $h)
<tr><td data-label="{{ $t('Shift') }}">@if(auth()->user()->hasPermission('view-staff-operations') || auth()->id() === $h['source_user_id'])<a href="/shift-stock/{{ $h['source_shift_id'] }}">{{ $h['source_number'] }}</a>@else{{ $h['source_number'] }}@endif</td>@if($showLocations)<td data-label="{{ $t('Counter') }}">{{ ($h['show_location'] ?? true) ? $h['counter'] : '—' }}</td>@endif<td data-label="{{ $t('Business Unit') }}">{{ $h['business_unit'] ?? '—' }}</td><td data-label="{{ $t('From') }}">{{ $h['from_name'] }}</td><td data-label="{{ $t('Destination') }}">{{ $h['to_name'] ?? $t('No cash to hand over') }}</td><td data-label="{{ $t('Handover amount') }}">{{ $h['amount'] }}</td><td data-label="{{ $t('Cash Received') }}">{{ $h['received_amount'] ?? '—' }}</td><td data-label="{{ $t('Cash Difference') }}">{{ $h['difference'] ?? '—' }}</td><td data-label="{{ $t('Receipt status') }}">{{ $t($h['status']) }}</td><td data-label="{{ $t('Actions') }}">
@if($h['to_user_id'] === auth()->id() && $h['status'] === 'PENDING')
<form data-custody-action="receive" data-id="{{ $h['id'] }}"><label>{{ $t('Cash Received') }}<input name="amount" aria-label="{{ $t('Cash Received') }}" type="number" min="0" step="0.01" required value="{{ $h['amount'] }}"></label><label>{{ $t('Reason / reference') }}<input name="note" maxlength="2000"></label><button class="button primary">{{ $t('Confirm Received') }}</button></form>
@endif
@if(auth()->user()->hasPermission('approve-cash-discrepancies') && $h['status'] === 'DISPUTED')
<form data-custody-action="approve" data-id="{{ $h['id'] }}"><label>{{ $t('Approval note') }}<input name="note" required minlength="8" maxlength="2000"></label><button class="button">{{ $t('Approve handover difference') }}</button></form>
@endif
@if(in_array($h['status'], ['PENDING','DISPUTED']) && $h['source_status'] === 'OPEN' && ($h['from_user_id'] === auth()->id() || auth()->user()->hasPermission('manage-cash-handovers')))
<form data-custody-action="cancel" data-id="{{ $h['id'] }}"><label>{{ $t('Reason / reference') }}<input name="note" required minlength="8" maxlength="2000"></label><button class="button">{{ $t('Cancel handover') }}</button></form>
@endif
@if(in_array($h['status'], ['PENDING','DISPUTED']) && $h['source_status'] === 'CLOSED' && auth()->user()->hasPermission('manage-cash-handovers'))
<form data-custody-action="cancel" data-id="{{ $h['id'] }}"><label>{{ $t('Where is the cash being handed over?') }}<select name="destination"><option value="TREASURY">{{ $t('Accountant / Admin') }}</option><option value="NEXT_CASHIER">{{ $t('Next Cashier') }}</option></select></label><label>{{ $t('Destination') }}<select name="to_user_id">@foreach($recipients ?? [] as $recipient)<option value="{{ $recipient['id'] }}">{{ $recipient['name'] }}</option>@endforeach</select></label><label>{{ $t('Reason / reference') }}<input name="note" required minlength="8" maxlength="2000"></label><button class="button">{{ $t('Replace cash recipient') }}</button></form>
@endif
<details><summary>{{ $t('Custody history') }}</summary><ul>@foreach($h['events'] as $event)<li>{{ $t($event['status']) }} · {{ $event['actor'] }} · {{ $event['created_at'] }} · {{ $event['amount'] ?? '—' }} · {{ $event['note'] }}</li>@endforeach</ul></details>
</td></tr>
@empty<tr><td colspan="10">{{ $t('No cash handovers found.') }}</td></tr>@endforelse
</tbody></table></div>
