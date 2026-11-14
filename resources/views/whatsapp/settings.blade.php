<x-layouts::nightshift>
    @include('partials.workspace-mount')
    <main class="workspace-admin-page whatsapp-page" data-workspace-page="whatsapp" data-workspace-group="Administration" hidden>
        <div class="page-heading">
            <div><p class="eyebrow">NIGHTSHIFT</p><h1>{{ \App\Support\UiText::get('WhatsApp Notifications') }}</h1><p>{{ \App\Support\UiText::get('Owner reports and alerts from your bar’s WhatsApp number.') }}</p></div>

        </div>
        <div id="whatsapp-notice" class="info-box" role="status" hidden></div>
        <nav class="tabs whatsapp-tabs" aria-label="{{ \App\Support\UiText::get('WhatsApp settings') }}">
            @foreach(['overview' => 'Overview', 'connection' => 'Connection', 'recipients' => 'Recipients', 'reports' => 'Reports', 'alerts' => 'Alerts', 'logs' => 'Logs'] as $key => $label)
                @if($key !== 'logs' || auth()->user()->hasPermission('whatsapp.view_logs'))
                    <button type="button" data-whatsapp-tab="{{ $key }}" aria-controls="whatsapp-{{ $key }}" aria-selected="{{ $key === 'overview' ? 'true' : 'false' }}" class="{{ $key === 'overview' ? 'active' : '' }}">{{ \App\Support\UiText::get($label) }}</button>
                @endif
            @endforeach
        </nav>
        <section id="whatsapp-overview" class="panel whatsapp-section" data-whatsapp-section="overview">
            <div class="panel-header"><h2>{{ \App\Support\UiText::get('Connection status') }}</h2><span class="badge" data-whatsapp-status>{{ \App\Support\UiText::get(match ($settings->connection_status) { 'NOT_FOUND' => 'Not Found', 'PROVIDER_ERROR' => 'Provider Error', default => str_replace('_', ' ', ucfirst(strtolower($settings->connection_status))) }) }}</span></div>
            <div class="panel-body">
                <div class="detail-row"><span>{{ \App\Support\UiText::get('WhatsApp Device ID') }}</span><strong>{{ $settings->device_id ?? '—' }}</strong></div>
                <div class="detail-row"><span>{{ \App\Support\UiText::get('WhatsApp number / JID') }}</span><strong data-whatsapp-jid>{{ $settings->jid ?? '—' }}</strong></div>
                <div class="detail-row"><span>{{ \App\Support\UiText::get('Last connection check') }}</span><strong data-whatsapp-checked>{{ $settings->last_checked_at?->copy()->timezone(auth()->user()->company->timezone ?: 'Africa/Dar_es_Salaam')->format('d M Y H:i') ?? '—' }}</strong></div>
                <p>{{ \App\Support\UiText::get('Timezone') }}: {{ auth()->user()->company->timezone ?: 'Africa/Dar_es_Salaam' }}</p>
                <button type="button" class="button" data-whatsapp-connection="status">{{ \App\Support\UiText::get('Test Connection') }}</button>
            </div>
        </section>
        <section id="whatsapp-connection" class="panel whatsapp-section" data-whatsapp-section="connection" hidden>
            <div class="panel-header"><h2>{{ \App\Support\UiText::get('Connect WhatsApp') }}</h2><span>{{ \App\Support\UiText::get('Status') }}: <span class="badge" data-whatsapp-status>{{ \App\Support\UiText::get(match ($settings->connection_status) { 'NOT_FOUND' => 'Not Found', 'PROVIDER_ERROR' => 'Provider Error', default => str_replace('_', ' ', ucfirst(strtolower($settings->connection_status))) }) }}</span></span></div>
            <div class="panel-body">
                <form data-whatsapp-form="device">
                    <label class="form-field"><span>{{ \App\Support\UiText::get('WhatsApp Device ID') }}</span><input name="device_id" value="{{ $settings->device_id ?? '' }}" @readonly(!auth()->user()->hasPermission('whatsapp.manage_settings')) maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,99}" required aria-describedby="whatsapp-device-help" data-whatsapp-device></label>
                    <p id="whatsapp-device-help" class="form-help">{{ \App\Support\UiText::get('Create and link your WhatsApp device at notify.buildcore.site, then paste its Device ID here.') }}</p>
                    <a class="button" href="https://notify.buildcore.site" target="_blank" rel="noopener noreferrer">{{ \App\Support\UiText::get('Open WhatsApp Device Manager') }}</a>
                    <div class="onboarding-actions">
                        @can('whatsapp.manage_settings')<button class="button primary" type="submit">{{ \App\Support\UiText::get('Save Device ID') }}</button>@endcan
                        <button type="button" class="button" data-whatsapp-connection="status">{{ \App\Support\UiText::get('Test Connection') }}</button>
                    </div>
                </form>
                <div class="detail-row"><span>{{ \App\Support\UiText::get('WhatsApp Phone Number') }}</span><strong data-whatsapp-connection-jid>{{ $settings->jid ?? '—' }}</strong></div>
                <div class="detail-row"><span>{{ \App\Support\UiText::get('Last connection check') }}</span><strong data-whatsapp-connection-checked>{{ $settings->last_checked_at?->toIso8601String() ?? '—' }}</strong></div>
                <p class="form-help" data-whatsapp-connection-warning @if($settings->connection_status === 'CONNECTED') hidden @endif>{{ \App\Support\UiText::get($settings->connection_status === 'NOT_FOUND' ? 'Device ID was not found on the WhatsApp server.' : 'Manage device linking on the WhatsApp server, then test the connection here.') }}</p>
            </div>
        </section>
        <section id="whatsapp-recipients" class="panel whatsapp-section" data-whatsapp-section="recipients" hidden>
            <div class="panel-header"><h2>{{ \App\Support\UiText::get('Owner / management recipients') }}</h2></div>
            <div class="panel-body">
                @foreach($recipients as $recipient)
                    <details class="whatsapp-recipient"><summary>{{ $recipient->name }} · {{ $recipient->phone }} · {{ \App\Support\UiText::get($recipient->active ? 'Active' : 'Inactive') }}</summary>
                        @include('whatsapp.recipient-form', ['recipient' => $recipient])
                    </details>
                @endforeach
                @can('whatsapp.manage_recipients')
                    <h3>{{ \App\Support\UiText::get('Add recipient') }}</h3>
                    @include('whatsapp.recipient-form', ['recipient' => null])
                @endcan
            </div>
        </section>
        <form data-whatsapp-form="settings">
            <fieldset @disabled(!auth()->user()->hasPermission('whatsapp.manage_settings'))>
                <section id="whatsapp-reports" class="panel whatsapp-section" data-whatsapp-section="reports" hidden>
                    <div class="panel-header"><h2>{{ \App\Support\UiText::get('Delivery and shift reports') }}</h2></div>@if($schedules->contains('report_type','DAILY'))<p class="form-help">{{ \App\Support\UiText::get('Scheduled reports below control periodic delivery times.') }}</p>@endif
                    <div class="panel-body form-grid">
                        @foreach(['enabled' => 'WhatsApp Enabled', 'daily_report' => 'Daily Owner Report Enabled', 'shift_report' => 'Shift Closing Report Enabled'] as $key => $label)
                            <label class="form-field" @if($key==='daily_report' && $schedules->contains('report_type','DAILY')) hidden @endif><span>{{ \App\Support\UiText::get($label) }}</span><input type="checkbox" name="{{ $key }}" @checked($options[$key])></label>
                        @endforeach
                        <label class="form-field" @if($schedules->contains('report_type','DAILY')) hidden @endif><span>{{ \App\Support\UiText::get('Operating day cutoff') }}</span><input type="time" name="operating_cutoff" value="{{ $options['operating_cutoff'] }}" required></label><label class="form-field" @if($schedules->contains('report_type','DAILY')) hidden @endif><span>{{ \App\Support\UiText::get('Daily Report Time') }}</span><input type="time" name="daily_time" value="{{ $options['daily_time'] }}" required></label>
                        <label class="form-field"><span>{{ \App\Support\UiText::get('Report Language') }}</span><select name="language"><option value="en" @selected($options['language'] === 'en')>English</option><option value="sw" @selected($options['language'] === 'sw')>Kiswahili</option></select></label>
                        <label class="form-field" @if($schedules->contains('report_type','DAILY')) hidden @endif><span>{{ \App\Support\UiText::get('Report scope') }}</span><select name="report_mode"><option value="consolidated" @selected($options['report_mode'] === 'consolidated')>{{ \App\Support\UiText::get('Consolidated Company Report') }}</option><option value="branch" @selected($options['report_mode'] === 'branch')>{{ \App\Support\UiText::get('Per-Branch Report') }}</option></select></label>
                        @can('whatsapp.manage_settings')<button class="button primary" type="submit">{{ \App\Support\UiText::get('Save WhatsApp settings') }}</button>@endcan
                    </div>
                </section>
                <section id="whatsapp-alerts" class="panel whatsapp-section" data-whatsapp-section="alerts" hidden>
                    <div class="panel-header"><h2>{{ \App\Support\UiText::get('Operational alerts') }}</h2></div>
                    <div class="panel-body form-grid">
                        @foreach(['waiter_outstanding' => 'Waiter Outstanding Alert Enabled', 'cash_shortage' => 'Cash Shortage Alert Enabled', 'low_stock' => 'Low Stock Alert Enabled', 'void' => 'Void Alert Enabled', 'breakage' => 'Breakage Alert Enabled', 'complimentary' => 'Complimentary Alert Enabled', 'large_sale' => 'Large Sale Alert Enabled', 'cash_handover'=>'Cash Handover Alert', 'adjustment'=>'Adjustment Alert'] as $key => $label)
                            <label class="form-field"><span>{{ \App\Support\UiText::get($label) }}</span><input type="checkbox" name="{{ $key }}" @checked($options[$key])></label>
                        @endforeach
                        @foreach(['waiter_threshold' => 'Waiter outstanding threshold', 'cash_threshold' => 'Cash shortage threshold', 'breakage_threshold' => 'Breakage threshold', 'complimentary_threshold' => 'Complimentary threshold', 'large_sale_threshold' => 'Large sale threshold', 'adjustment_threshold'=>'Adjustment threshold'] as $key => $label)
                            <label class="form-field"><span>{{ \App\Support\UiText::get($label) }} ({{ auth()->user()->company->currency }})</span><input type="number" name="{{ $key }}" min="0" step="0.01" value="{{ $options[$key] }}" required></label>
                        @endforeach
                        <label class="form-field"><span>{{ \App\Support\UiText::get('Alert cooldown (minutes)') }}</span><input type="number" name="cooldown_minutes" min="1" max="10080" value="{{ $options['cooldown_minutes'] }}" required></label>
                        <label class="form-field"><span>{{ \App\Support\UiText::get('Low stock further drop (%)') }}</span><input type="number" name="low_stock_drop_percent" min="1" max="100" value="{{ $options['low_stock_drop_percent'] }}" required></label>
                        @can('whatsapp.manage_settings')<button class="button primary" type="submit">{{ \App\Support\UiText::get('Save WhatsApp settings') }}</button>@endcan
                    </div>
                </section>
            </fieldset>
        </form>
        @can('whatsapp.manage_settings')
        <section class="panel whatsapp-section" data-whatsapp-section="reports" hidden><div class="panel-header"><h2>{{ \App\Support\UiText::get('Scheduled Reports') }}</h2></div><div class="panel-body">
        <p class="form-help">{{ \App\Support\UiText::get('Send time is separate from the operating cutoff. Whole shifts stay together.') }}</p><p class="form-help">{{ \App\Support\UiText::get('Daily schedules send the last completed operating period. Manual reports can show the current live period.') }}</p>
        @foreach($schedules as $schedule)<details class="whatsapp-recipient"><summary>{{ $schedule->name }} · {{ $schedule->send_time }} · {{ $schedule->timezone }} · {{ \App\Support\UiText::get($schedule->enabled ? 'Enabled' : 'Disabled') }}</summary>@include('whatsapp.schedule-form',['schedule'=>$schedule])</details>@endforeach
        <h3>{{ \App\Support\UiText::get('Add report schedule') }}</h3>@include('whatsapp.schedule-form',['schedule'=>null])
        </div></section>
        @endcan
        @if(auth()->user()->hasPermission('whatsapp.send_test') || auth()->user()->hasPermission('whatsapp.send_manual_report'))
            <section class="panel"><div class="panel-header"><h2>{{ \App\Support\UiText::get('Send a test or owner report') }}</h2></div><div class="panel-body">
                <form data-whatsapp-form="send" class="form-grid">
                    <label class="form-field"><span>{{ \App\Support\UiText::get('Recipient') }}</span><select name="recipient_id" required>@foreach($recipients->where('active', true) as $recipient)<option value="{{ $recipient->id }}">{{ $recipient->name }} · {{ $recipient->phone }}</option>@endforeach</select></label>
                    <label class="form-field"><span>{{ \App\Support\UiText::get('Operating Date') }}</span><input type="date" name="date" value="{{ $operatingDate }}"></label>
                    <div class="onboarding-actions">@can('whatsapp.send_test')<button class="button" type="submit" value="test">{{ \App\Support\UiText::get('Send Test Message') }}</button>@endcan @can('whatsapp.send_manual_report')<button class="button primary" type="submit" value="report">{{ \App\Support\UiText::get('Send Owner Report') }}</button>@endcan</div>
                </form>
            </div></section>
        @endif
        @can('whatsapp.view_logs')
            <section id="whatsapp-logs" class="panel whatsapp-section" data-whatsapp-section="logs" hidden>
                <div class="panel-header"><h2>{{ \App\Support\UiText::get('Delivery logs') }}</h2><button type="button" class="button" data-whatsapp-refresh>{{ \App\Support\UiText::get('Refresh') }}</button></div>
                <div class="table-scroll"><table class="data-table responsive-records whatsapp-log-table"><thead><tr>@foreach(['Date/Time', 'Recipient', 'Type', 'Status', 'Attempts', 'Reference', 'Message', 'Provider message ID', 'Error'] as $label)<th>{{ \App\Support\UiText::get($label) }}</th>@endforeach</tr></thead><tbody>
                    @forelse($logs as $log)
                        <tr><td data-label="{{ \App\Support\UiText::get('Date/Time') }}">{{ $log->queued_at?->copy()->timezone(auth()->user()->company->timezone ?: 'Africa/Dar_es_Salaam')->format('d M Y H:i') }}</td><td data-label="{{ \App\Support\UiText::get('Recipient') }}">{{ $log->recipient_name }}<br>{{ $log->recipient_phone }}</td><td data-label="{{ \App\Support\UiText::get('Type') }}">{{ \App\Support\UiText::get(config('whatsapp.type_labels.'.$log->message_type, $log->message_type==='TEST' ? 'Test Message' : ($log->message_type==='MANUAL' ? 'Owner Report' : $log->message_type))) }}</td><td>{{ \App\Support\UiText::get($log->status) }}</td><td data-label="{{ \App\Support\UiText::get('Attempts') }}">{{ $log->attempt_count }}</td><td data-label="{{ \App\Support\UiText::get('Reference') }}">{{ $log->reference_type }} {{ $log->reference_id }}<br>{{ $log->payload['period_start'] ?? '' }}<br>{{ $log->payload['period_end'] ?? '' }}</td><td class="whatsapp-preview" data-label="{{ \App\Support\UiText::get('Message') }}"><div class="whatsapp-message-preview">{{ $log->message_preview ?? '—' }}</div></td><td data-label="{{ \App\Support\UiText::get('Provider message ID') }}">{{ $log->provider_message_id ?? '—' }}<br>{{ $log->attachment_message_id ?? '' }}@if($log->attachment_status!=='NONE')<br>{{ \App\Support\UiText::get($log->attachment_status) }}@endif</td><td data-label="{{ \App\Support\UiText::get('Error') }}">{{ $log->error_message ? \App\Support\UiText::get($log->error_message) : '—' }}</td></tr>
                    @empty<tr><td colspan="9">{{ \App\Support\UiText::get('No WhatsApp deliveries yet.') }}</td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endcan
    </main>
</x-layouts::nightshift>
