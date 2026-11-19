@component('layouts.nightshift')
@php
    $t = fn ($label) => \App\Support\UiText::get($label);
    $qty = fn ($amount) => \App\Support\QuantityFormatter::format($amount);
    $money = fn ($amount) => $amount === null ? '—' : 'TSh '.number_format((float) $amount, 2);
    $date = fn ($value) => \Carbon\CarbonImmutable::parse($value)->timezone(auth()->user()->company->timezone ?: 'Africa/Dar_es_Salaam')->format('d M Y H:i');
    $steps = [
        'pending-orders' => 'Pending Orders',
        'ticket-collection' => 'Ticket collection step',
        'stock-count' => 'Stock Count',
        'cash-reconciliation' => 'Cash Reconciliation',
        'review' => 'Review',
        'close-shift' => 'Close Shift',
    ];
@endphp
<meta name="csrf-token" content="{{ csrf_token() }}">
<main class="reconciliation-page close-wizard" @if($report['status'] === 'OPEN') id="cashier-close-wizard" @endif>
    <header class="close-wizard-header">
        <x-nightshift-brand :href="url('/dashboard#shifts')" />
        <div class="close-wizard-actions">
            @include('partials.language-switch')
            <button type="button" class="button" data-theme-toggle>{{ $t('Light / Dark') }}</button>
            @if($report['status'] === 'OPEN')
                <a class="button" href="/dashboard#shifts">{{ $t('Back to Shifts') }}</a>
            @endif
        </div>
    </header>
    @if($report['status'] === 'CLOSED')
        <section class="panel panel-body close-wizard-success" data-shift-closed>
            <span class="close-wizard-state ready">{{ $t('Closed') }}</span>
            <h1>{{ $t('Shift closed successfully.') }}</h1>
            <dl class="close-wizard-facts">
                <div><dt>{{ $t('Shift') }}</dt><dd>{{ $report['number'] }}</dd></div>
                <div><dt>{{ $t('Closed at') }}</dt><dd>{{ $date($report['closed_at']) }}</dd></div>
                <div><dt>{{ $t('Expected cash') }}</dt><dd>{{ $money($report['cash']['expected']) }}</dd></div>
                <div><dt>{{ $t('Actual cash') }}</dt><dd>{{ $money($report['cash']['actual']) }}</dd></div>
                <div><dt>{{ $t('Difference') }}</dt><dd>{{ $money($report['cash']['difference']) }}</dd></div>
            </dl>
            <div class="close-wizard-actions">
                <a class="button primary" href="/shift-stock/{{ $report['shift_id'] }}/print" target="_blank" rel="noopener">{{ $t('Print Shift Report') }}</a>
                <a class="button" href="/dashboard">{{ $t('Return to Dashboard') }}</a>
            </div>
        </section>
    @else
        <h1>{{ $t('Close Shift') }}</h1>
        <p>{{ $report['number'] }} · {{ $report['cashier'] }} · {{ $report['business_unit'] }} · {{ $report['counter'] }}</p>
        <nav aria-label="{{ $t('Shift close steps') }}">
            <ol class="close-wizard-steps">
                @foreach($steps as $key => $label)
                    <li><button type="button" data-wizard-step="{{ $key }}" aria-controls="wizard-{{ $key }}" @if($loop->first) aria-current="step" @endif><span data-step-icon aria-hidden="true">{{ $loop->iteration }}</span>{{ $t($label) }}<span class="sr-only" data-step-status></span></button></li>
                @endforeach
            </ol>
        </nav>
        <div class="close-wizard-feedback">
            <p role="alert" data-wizard-error hidden></p>
            <button type="button" class="button" data-wizard-refresh>{{ $t('Refresh shift status') }}</button>
        </div>

        <section class="panel panel-body close-wizard-panel" id="wizard-pending-orders" data-wizard-panel="pending-orders">
            <h2 tabindex="-1">{{ $t('Pending Orders') }}</h2>
            @if($report['blockers'] === [])
                <p class="close-wizard-state ready">{{ $t('No pending waiter or order blockers.') }}</p>
            @else
                <ul class="close-wizard-blockers">
                    @foreach($report['blockers'] as $blocker)
                        <li>
                            <strong>{{ $blocker['name'] }}</strong>
                            <span>{{ $t($blocker['open'] ? 'Open' : 'Closed') }} · {{ $t('Outstanding') }} {{ $money($blocker['balance']) }}</span>
                            @if(bccomp($blocker['balance'], '0', 2) > 0)<span>{{ $t('Settle the outstanding waiter balance.') }}</span>@endif
                            @if($blocker['open'])<span>{{ $t('Submit or cancel pending waiter orders and close the waiter shift.') }}</span>@endif
                        </li>
                    @endforeach
                </ul>
                <div class="close-wizard-actions">
                    <a class="button" href="/dashboard#waiters" target="_blank" rel="noopener">{{ $t('Waiter accounts') }}</a>
                    <a class="button" href="/dashboard#orders" target="_blank" rel="noopener">{{ $t('Orders') }}</a>
                </div>
            @endif
        </section>

        <section class="panel panel-body close-wizard-panel" id="wizard-ticket-collection" data-wizard-panel="ticket-collection" hidden>
            <h2 tabindex="-1">{{ $t('Ticket collection step') }}</h2>
            <dl class="close-wizard-facts">
                <div><dt>{{ $t('Pending ticket count') }}</dt><dd>{{ $report['cash']['pending_ticket_count'] }}</dd></div>
                <div><dt>{{ $t('Pending ticket amount') }}</dt><dd>{{ $money($report['cash']['pending_ticket_amount']) }}</dd></div>
                <div><dt>{{ $t('Collected ticket amount') }}</dt><dd>{{ $money($report['cash']['collected_ticket_amount']) }}</dd></div>
            </dl>
            @if($report['cash']['pending_ticket_count'])
                <p class="close-wizard-state blocked">{{ $t('Collect pending tickets before closing or handing over cash.') }}</p>
                <ul class="close-wizard-blockers">
                    @foreach($report['pending_tickets'] as $ticket)
                        <li><strong>{{ $ticket['invoice'] }}</strong><span>{{ $ticket['waiter'] }} · {{ $money($ticket['amount']) }}</span></li>
                    @endforeach
                </ul>
                @can('receive-payments')<a class="button" href="{{ route('ticket-collections.index') }}" target="_blank" rel="noopener">{{ $t('Open Ticket Collection') }}</a>@endcan
            @else
                <p class="close-wizard-state ready">{{ $t('All ticket collections are complete.') }}</p>
            @endif
        </section>

        <section class="panel panel-body close-wizard-panel" id="wizard-stock-count" data-wizard-panel="stock-count" hidden>
            <h2 tabindex="-1">{{ $t('Stock Count') }}</h2>
            <p class="form-help">{{ $t('Physical counts are saved with actual cash in the next step.') }}</p>
            <div class="close-wizard-stock-summary">
                <span data-count-progress></span>
                <span>{{ $t('Stock variances') }} <strong data-count-variance></strong></span>
                <span>{{ $t('Ledger mismatches') }} <strong>{{ $report['totals']['ledger_mismatches'] }}</strong></span>
            </div>
            @if($report['totals']['ledger_mismatches'])
                <p class="close-wizard-state blocked">{{ $t('Stock ledger does not match the shift calculation. A manager must resolve this before closing.') }}</p>
            @endif
            @if($report['stale'])
                <p class="close-wizard-state attention">{{ $t('Stock changed. Reload and verify every physical count.') }}</p>
            @endif
            <p class="close-wizard-state attention" data-approval-needed hidden>{{ $t('Awaiting manager approval') }}</p>
            <p class="close-wizard-state ready" data-approval-granted hidden>{{ $t('Stock variances approved') }}</p>
            <label class="form-field close-wizard-search"><span>{{ $t('Search product') }}</span><input type="search" data-count-search></label>
            <div class="table-scroll close-wizard-table" role="region" aria-label="{{ $t('Stock Count') }}" tabindex="0">
                <table class="data-table recon-table">
                    <thead><tr>@foreach(['Product', 'Opening', 'Added', 'System Sold', 'Returns', 'Other Out', 'Expected', 'Physical', 'Variance', 'Status'] as $label)<th scope="col">{{ $t($label) }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach($report['rows'] as $row)
                        <tr data-wizard-count-row data-product="{{ $row['product_id'] }}" data-name="{{ $row['product'] }}" data-expected="{{ $row['expected'] }}">
                            <th scope="row">{{ $row['product'] }}<small>{{ $row['base_unit'] }}</small></th>
                            @foreach(['opening' => 'Opening', 'added' => 'Added', 'system_sold' => 'System Sold', 'returns' => 'Returns', 'other_out' => 'Other Out', 'expected' => 'Expected'] as $field => $label)
                                <td data-count-label="{{ $t($label) }}" data-count-metric="{{ $field }}">{{ $qty($row[$field]) }}</td>
                            @endforeach
                            <td data-count-label="{{ $t('Physical') }}" data-count-metric="physical">
                                <input aria-label="{{ $t('Physical') }} {{ $row['product'] }}" type="text" inputmode="decimal" maxlength="17" pattern="[0-9]{1,10}([.][0-9]{1,6})?" data-physical-count value="{{ !$report['stale'] && $row['physical'] !== null ? $qty($row['physical']) : '' }}" @readonly($report['custody'] !== null)>
                                @if($report['stale'] && $row['physical'] !== null)<small>{{ $t('Previous count') }} {{ $qty($row['physical']) }}</small>@endif
                            </td>
                            <td data-count-difference data-count-label="{{ $t('Variance') }}">—</td><td data-count-status data-count-label="{{ $t('Status') }}">{{ $t('Not counted') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <label class="form-field"><span>{{ $t('Variance reason') }}</span><textarea data-stock-reason maxlength="2000" @readonly($report['custody'] !== null)>{{ $report['reason'] }}</textarea></label>
        </section>

        <section class="panel panel-body close-wizard-panel" id="wizard-cash-reconciliation" data-wizard-panel="cash-reconciliation" hidden>
            <h2 tabindex="-1">{{ $t('Cash Reconciliation') }}</h2>
            <dl class="close-wizard-facts">
                <div><dt>{{ $t('Opening cash') }}</dt><dd>{{ $money($report['cash']['opening']) }}</dd></div>
                <div><dt>{{ $t('Cash sales and settlements') }}</dt><dd>{{ $money($report['cash']['methods']['Cash']['net']) }}</dd></div>
                <div><dt>{{ $t('Collected ticket amount') }}</dt><dd>{{ $money($report['cash']['collected_ticket_amount']) }}</dd></div>
                <div><dt>{{ $t('Expected cash') }}</dt><dd>{{ $money($report['cash']['expected']) }}</dd></div>
                @foreach($report['cash']['methods'] as $method => $amounts)
                    @if($method !== 'Cash' && (bccomp($amounts['sales'], '0', 2) || bccomp($amounts['refunds'], '0', 2)))
                        <div><dt>{{ $t($method) }}</dt><dd>{{ $money($amounts['net']) }}</dd></div>
                    @endif
                @endforeach
            </dl>
            <label class="form-field close-wizard-cash-input"><span>{{ $t('Actual cash') }}</span>
                <input type="text" inputmode="decimal" maxlength="13" pattern="[0-9]{1,10}([.][0-9]{1,2})?" data-wizard-actual value="{{ !$report['stale'] ? ($report['cash']['actual'] ?? '') : '' }}" required @readonly($report['custody'] !== null)>
            </label>
            @if($report['stale'] && $report['cash']['actual'] !== null)<p>{{ $t('Previously counted cash') }} {{ $money($report['cash']['actual']) }}</p>@endif
            <p aria-live="polite">{{ $t('Difference') }} <strong data-wizard-cash-difference>—</strong></p>
            <label class="form-field"><span>{{ $t('Closing notes') }}</span><textarea data-wizard-notes maxlength="2000">{{ $report['custody']['note'] ?? '' }}</textarea></label>
            <p class="form-help" data-wizard-note-required hidden>{{ $t('Explain the cash shortage or excess.') }}</p>
            <p class="form-help" data-cash-waiters hidden>{{ $t('Close all waiter shifts and settle all balances before reconciliation.') }}</p>
            @if($report['custody'] !== null)<p><a href="/cash-handovers" target="_blank" rel="noopener">{{ $t('Cancel the unreceived handover before editing reconciliation.') }}</a></p>@endif
            <button type="button" class="button primary" data-wizard-save>{{ $t('Save reconciliation') }}</button>
        </section>

        <section class="panel panel-body close-wizard-panel" id="wizard-review" data-wizard-panel="review" hidden>
            <h2 tabindex="-1">{{ $t('Review') }}</h2>
            <dl class="close-wizard-facts">
                @foreach(['Shift' => $report['number'], 'Cashier' => $report['cashier'], 'Business Unit' => $report['business_unit'], 'Counter' => $report['counter'], 'Opened' => $date($report['opened_at'])] as $label => $value)
                    <div><dt>{{ $t($label) }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
                <div><dt>{{ $t('Stock Count') }}</dt><dd data-review-stock></dd></div>
                <div><dt>{{ $t('Ledger mismatches') }}</dt><dd>{{ $report['totals']['ledger_mismatches'] }}</dd></div>
                <div><dt>{{ $t('Stock variances') }}</dt><dd data-review-variance></dd></div>
                <div><dt>{{ $t('Pending ticket count') }}</dt><dd>{{ $report['cash']['pending_ticket_count'] }}</dd></div>
                <div><dt>{{ $t('Waiter blockers') }}</dt><dd>{{ count($report['blockers']) }}</dd></div>
                <div><dt>{{ $t('Expected cash') }}</dt><dd>{{ $money($report['cash']['expected']) }}</dd></div>
                <div><dt>{{ $t('Actual cash') }}</dt><dd data-review-actual></dd></div>
                <div><dt>{{ $t('Difference') }}</dt><dd data-review-difference></dd></div>
            </dl>
            <p class="close-wizard-state" data-review-status></p>
        </section>

        <section class="panel panel-body close-wizard-panel" id="wizard-close-shift" data-wizard-panel="close-shift" hidden>
            <h2 tabindex="-1">{{ $t('Close Shift') }}</h2>
            <h3>{{ $t('Cash Handover') }}</h3>
            <div data-handover-saved hidden>
                <p><strong data-handover-recipient></strong> · <span data-handover-status></span></p>
                <a class="button" href="/cash-handovers" target="_blank" rel="noopener">{{ $t('Review cash receipt') }}</a>
            </div>
            <p data-no-handover hidden>{{ $t('No cash to hand over') }}</p>
            <div data-handover-choices hidden>
                <label class="form-field"><span>{{ $t('Where is the cash being handed over?') }}</span>
                    <select data-wizard-destination><option value="NEXT_CASHIER">{{ $t('Next Cashier') }}</option><option value="TREASURY">{{ $t('Accountant / Admin') }}</option></select>
                </label>
                <label class="form-field"><span>{{ $t('Destination') }}</span>
                    <select data-wizard-recipient>
                        @foreach($recipients as $recipient)
                            <option value="{{ $recipient['id'] }}" data-cashier="{{ (int) ($recipient['cashier'] && $recipient['id'] !== $report['cashier_user_id']) }}" data-treasury="{{ (int) $recipient['treasury'] }}">{{ $recipient['name'] }}</option>
                        @endforeach
                    </select>
                </label>
                <p class="form-help" data-handover-no-recipient hidden>{{ $t('No authorized cash recipients for this destination.') }}</p>
                <button type="button" class="button" data-handover-submit>{{ $t('Save cash handover') }}</button>
            </div>
            <p data-handover-problem></p>
            <button type="button" class="button primary" data-wizard-close disabled>{{ $t('Close Shift') }}</button>
        </section>
        <footer class="close-wizard-footer close-wizard-actions">
            <button type="button" class="button" data-wizard-back>{{ $t('Back') }}</button>
            <button type="button" class="button primary" data-wizard-next>{{ $t('Continue') }}</button>
        </footer>
        <dialog class="close-wizard-confirm">
            <form method="dialog" class="panel panel-body">
                <h2>{{ $t('Close Shift') }}</h2>
                <p>{{ $t('Close this cashier shift? Final stock and cash reconciliation will be frozen.') }}</p>
                <div class="close-wizard-actions">
                    <button class="button" value="cancel" autofocus>{{ $t('Cancel') }}</button>
                    <button class="button primary" value="close">{{ $t('Close Shift') }}</button>
                </div>
            </form>
        </dialog>
        <noscript>{{ $t('Enable JavaScript to reconcile and close this shift.') }}</noscript>
    @endif
</main>
@if($report['status'] === 'OPEN')
<script>window.ShiftCloseData = @js($report); window.ShiftCloseMessages = @js(\App\Support\UiText::catalog());</script>
@endif
@endcomponent
