@component('layouts.nightshift')
@php($t = fn($text) => \App\Support\UiText::get($text))
<meta name="csrf-token" content="{{ csrf_token() }}">
@include('partials.auth-toolbar')
<main class="reconciliation-page" id="shift-stock" data-shift="{{ $report['shift_id'] }}">
    <header class="recon-heading"><x-nightshift-brand :href="url('/dashboard#shifts')" /><a class="button" href="/dashboard#shifts">{{ $t('Shifts') }}</a></header>
    <h1>{{ $t('Stock Reconciliation') }}</h1>
    <p>{{ $report['number'] }} · {{ $report['branch'] }} · {{ $report['counter'] }} · {{ $report['opened_by'] }}</p>
    <div id="recon-message" role="alert" class="text-red">{{ $report['stale'] ? $t('Stock changed. Reload and verify every physical count.') : '' }}</div>
    @if($report['totals']['ledger_mismatches'])<p class="text-red">{{ $t('Stock balances do not match the ledger. Investigate before closing.') }}</p>@endif
    <section class="panel panel-body">
        <h2>{{ $t('Cash reconciliation') }}</h2>
        <div class="recon-cash">
            <div><span>{{ $t('Opening cash') }}</span><strong>{{ $report['cash']['opening'] }}</strong></div>
            @foreach($report['cash']['methods'] as $method => $amounts)
                <div><span>{{ $t($method) }}</span><strong>{{ $amounts['net'] }}</strong><small>{{ $t('Payments received') }}: {{ $amounts['sales'] }} · {{ $t('Refunds') }}: {{ $amounts['refunds'] }}</small></div>
            @endforeach
            <div><span>{{ $t('Expected cash') }}</span><strong>{{ $report['cash']['expected'] }}</strong></div>
            <div><span>{{ $t('Waiter outstanding') }}</span><strong>{{ $report['cash']['waiter_outstanding'] }}</strong></div>
        </div>
    </section>
    <section class="panel panel-body">
        <h2>{{ $t('Physical closing count') }}</h2>
        <p>{{ $t('Count base units. Stock is never changed by this reconciliation.') }}</p>
        <div class="toolbar recon-filters">
            <input id="recon-search" class="search-field" placeholder="{{ $t('Search product') }}" aria-label="{{ $t('Search product') }}">
            <select id="recon-category" aria-label="{{ $t('Category') }}"><option value="">{{ $t('All categories') }}</option>@foreach(collect($report['rows'])->pluck('category')->unique() as $category)<option>{{ $category }}</option>@endforeach</select>
            <label><input id="recon-variance-only" type="checkbox"> {{ $t('Variances only') }}</label>
        </div>
        <form id="recon-count-form">
            <div class="table-scroll"><table class="data-table recon-table"><thead><tr>
                @foreach(['Product','Opening','Added','Total Available','System Sold','Other Stock Out','Valid Returns','Expected Closing','Physical Closing','Variance','Selling Price','Expected Sales Value'] as $label)<th>{{ $t($label) }}</th>@endforeach
            </tr></thead><tbody>
            @foreach($report['rows'] as $row)
                <tr data-recon-row data-product="{{ $row['product_id'] }}" data-category="{{ $row['category'] }}" data-name="{{ $row['product'] }}" data-expected="{{ $row['expected'] }}" data-price="{{ \App\Support\QuantityFormatter::format($row['price']) }}" data-total="{{ $row['total_available'] }}">
                    <td><strong>{{ $row['product'] }}</strong><small>{{ $row['base_unit'] }}</small>@foreach($row['conversions'] as $conversion)<small>1 {{ $conversion['unit'] }} = {{ \App\Support\QuantityFormatter::format($conversion['quantity']) }} {{ $row['base_unit'] }}</small>@endforeach</td>
                    @foreach(['opening','added','total_available','system_sold','other_out','returns','expected'] as $field)<td>{{ \App\Support\QuantityFormatter::format($row[$field]) }}</td>@endforeach
                    <td><input class="physical-count" aria-label="{{ $t('Physical Closing') }} {{ $row['product'] }}" inputmode="decimal" type="number" min="0" step="0.000001" value="{{ $row['physical'] === null ? '' : \App\Support\QuantityFormatter::format($row['physical']) }}" {{ $report['can_edit'] ? '' : 'readonly' }}></td>
                    <td data-variance-cell><strong>{{ $row['variance'] === null ? '—' : \App\Support\QuantityFormatter::format($row['variance']) }}</strong> {{ $t($row['status']) }}</td>
                    <td>{{ \App\Support\QuantityFormatter::format($row['price']) }}</td><td data-sales-cell>{{ $row['sales_value'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody></table></div>
            <div class="recon-summary"><strong>{{ $t('Stock Variance Value') }}: <span id="recon-variance-total">{{ $report['totals']['variance_value'] }}</span></strong><strong>{{ $t('Expected Sales Value') }}: <span id="recon-sales-total">{{ $report['totals']['sales_value'] }}</span></strong></div>
            <p>{{ $t('Sales value is a physical-depletion estimate at the opening base-unit price, not posted revenue or cash. Returns and non-sale stock out are shown separately.') }}</p>
            @if($report['can_edit'])
            <label class="form-field"><span>{{ $t('Variance reason') }}</span><textarea name="reason" maxlength="2000">{{ $report['reason'] }}</textarea></label>
            <button class="button primary" type="submit">{{ $t('Save physical count') }}</button>
            @endif
        </form>
    </section>
    <section class="panel panel-body">
        <h2>{{ $t('Review variances') }}</h2>
        <p>{{ $t('Counted by') }}: {{ $report['entered_name'] ?? '—' }} · {{ $report['counted_at'] ?? '' }} · {{ $t('Manager approval') }}: {{ $report['approved_name'] ?? '—' }}</p>
        <p>{{ $report['reason'] }} {{ $report['approval_note'] }}</p>
        @if($report['can_approve'] && $report['complete'] && $report['totals']['variance_count'] && !$report['approved'])
            <form id="recon-approve-form"><label class="form-field"><span>{{ $t('Approval note') }}</span><textarea name="note" required minlength="8" maxlength="2000"></textarea></label><button class="button primary">{{ $t('Approve stock variances') }}</button></form>
        @elseif($report['totals']['variance_count'] && !$report['approved'])<p>{{ $t('A manager must approve the stock variances before closing.') }}</p>@endif
        @if($report['can_edit'])
            <form id="recon-close-form"><label class="form-field"><span>{{ $t('Actual cash') }}</span><input name="actual_cash" type="number" min="0" step="0.01" required value="{{ $report['cash']['expected'] }}"></label><p id="recon-cash-difference"></p><label class="form-field"><span>{{ $t('Closing notes') }}</span><textarea name="notes" maxlength="2000"></textarea></label><button class="button primary" {{ $report['complete'] && (!$report['totals']['variance_count'] || $report['approved']) && !$report['totals']['ledger_mismatches'] ? '' : 'disabled' }}>{{ $t('Confirm close') }}</button></form>
        @endif
        @if($report['status'] === 'CLOSED')<p>{{ $t('Closed by') }}: {{ $report['closed_by'] }} · {{ $report['closed_at'] }}</p><p>{{ $report['closing_notes'] }}</p><p>{{ $t('Cash variance') }}: {{ $report['cash']['difference'] }}</p>@endif
        <a class="button" href="/shift-stock/{{ $report['shift_id'] }}/print" target="_blank" rel="noopener">{{ $t('Print closing sheet') }}</a>
    </section>
</main>
<script>window.ShiftStockData = @json($report); window.ShiftStockMessages = @json(\App\Support\UiText::catalog());</script>
@endcomponent
