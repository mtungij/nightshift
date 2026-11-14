<div class="table-scroll"><table class="data-table responsive-records"><thead><tr>@foreach(['Date and time','Reference','Custodian','Branch','Transaction type','Direction','Amount','Description','Status'] as $label)<th>{{ $t($label) }}</th>@endforeach</tr></thead><tbody>
@forelse($entries as $entry)<tr><td data-label="{{ $t('Date and time') }}">{{ $entry['occurred_at'] }}</td><td data-label="{{ $t('Reference') }}">{{ $entry['external_reference'] }}</td><td data-label="{{ $t('Custodian') }}">{{ $entry['custodian'] }}</td><td data-label="{{ $t('Branch') }}">{{ $entry['branch'] }}</td><td data-label="{{ $t('Transaction type') }}">{{ $t($entry['transaction_type']) }}</td><td data-label="{{ $t('Direction') }}">{{ $t($entry['direction']) }}</td><td data-label="{{ $t('Amount') }}">{{ $entry['amount'] }}</td><td data-label="{{ $t('Description') }}">{{ $entry['business_unit'] ?? '' }} · {{ $entry['description'] }} {{ $entry['employee'] ?? '' }} {{ $entry['bank'] }} {{ $entry['pay_period'] }}</td><td data-label="{{ $t('Status') }}">{{ $entry['reversed'] ? $t('Reversed') : $t('Posted') }}
@if(empty($printing) && auth()->user()->hasPermission('reverse-treasury') && !$entry['reversed'] && $entry['transaction_type'] !== 'REVERSAL')
<form data-custody-action="reverse" data-id="{{ $entry['id'] }}"><label>{{ $t('Reason / reference') }}<input name="note" required minlength="8" maxlength="2000"></label>
@if($entry['direction'] === 'IN')<label>{{ $t('Replacement custodian') }}<select name="to_user_id" required>@foreach($recipients ?? [] as $recipient)@if($recipient['treasury'] && $recipient['id'] !== $entry['custodian_id'])<option value="{{ $recipient['id'] }}">{{ $recipient['name'] }}</option>@endif @endforeach</select></label>@endif
<button class="button">{{ $t('Record reversal') }}</button></form>
@endif
</td></tr>
@empty<tr><td colspan="9">{{ $t('No treasury entries found.') }}</td></tr>@endforelse
</tbody></table></div>
