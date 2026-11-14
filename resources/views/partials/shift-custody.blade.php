<section class="panel panel-body">
    <h2>{{ $t('Cash Handover') }}</h2>
    @if(!empty($report['custody']))
        @php($custody = $report['custody'])
        <p>{{ $t($custody['destination'] === 'NEXT_CASHIER' ? 'Next Cashier' : ($custody['destination'] === 'NONE' ? 'No cash to hand over' : 'Accountant / Admin')) }} · {{ $custody['to_name'] ?? '—' }}</p>
        <p>{{ $t('Handover amount') }}: {{ $custody['amount'] }} · {{ $t('Receipt status') }}: {{ $t($custody['status']) }} · {{ $t('Cash Received') }}: {{ $custody['received_amount'] ?? '—' }} · {{ $t('Cash Difference') }}: {{ $custody['difference'] ?? '—' }}</p>
        @if($custody['destination'] === 'TREASURY' && $custody['source_status'] === 'OPEN')<p>{{ $t('Ask the recipient to confirm receipt, then return here to close the shift.') }}</p><p>{{ $t('Treasury funds become available after receipt confirmation and final shift close.') }}</p>@endif
        <details><summary>{{ $t('Custody history') }}</summary><ul>@foreach($custody['events'] as $event)<li>{{ $t($event['status']) }} · {{ $event['actor'] }} · {{ $event['amount'] ?? '—' }} · {{ $event['created_at'] }} · {{ $event['note'] }}</li>@endforeach</ul></details>
        <a class="button" href="/cash-handovers">{{ $t('Review cash receipt') }}</a>
    @else<p>{{ $t($report['status'] === 'CLOSED' ? 'Legacy shift — no custody record' : 'Select where the counted cash is being handed over.') }}</p>@endif
</section>
