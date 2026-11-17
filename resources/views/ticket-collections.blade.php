@component('layouts.nightshift')
@php($t = fn ($text) => \App\Support\UiText::get($text))
@include('partials.workspace-mount')
<div class="workspace-admin-page custody-page" data-workspace-page="ticket-collections" data-workspace-group="Finance" hidden>
    <div class="page-heading">
        <h1>{{ $t('Ticket Collection') }}</h1>
        <a class="button" href="/dashboard#waiters">{{ $t('Waiter accounts') }}</a>
    </div>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
    <form class="panel panel-body admin-filters" method="get" action="{{ route('ticket-collections.index') }}">
        <label class="form-field">{{ $t('Invoice number') }}<input name="invoice" value="{{ $invoice }}" maxlength="60"></label>
        <button class="button">{{ $t('Search') }}</button>
        @if($invoice !== '')<a class="button" href="{{ route('ticket-collections.index') }}">{{ $t('Pending Ticket Collections') }}</a>@endif
    </form>
    <section class="panel">
        @if($invoice === '')<div class="panel-header"><h2>{{ $t('Pending Ticket Collections') }}</h2></div>@endif
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr>
                    @foreach(['Invoice', 'Waiter', 'Business Unit', 'Branch', 'Counter', 'Amount', 'Created', 'Collection status'] as $label)
                        <th scope="col">{{ $t($label) }}</th>
                    @endforeach
                    <th scope="col"></th>
                </tr></thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        <tr>
                            <td>{{ $ticket->order->invoice_number }}</td>
                            <td>{{ $ticket->order->waiter->name }}</td>
                            <td>{{ $ticket->businessUnit?->name }}</td>
                            <td>{{ $ticket->branch?->name }}</td>
                            <td>{{ $ticket->counter?->name }}</td>
                            <td>{{ auth()->user()->company->currency }} {{ $ticket->amount }}</td>
                            <td>{{ $ticket->order->created_at?->copy()->timezone(auth()->user()->company->timezone ?: 'Africa/Dar_es_Salaam')->format('d M Y H:i') }}</td>
                            <td>{{ $t($ticket->status) }}</td>
                            <td>
                                @if($ticket->status === 'PENDING' && in_array($ticket->order->status, ['PAID', 'RETURNED'], true))
                                    <form method="post" action="{{ route('ticket-collections.collect', $ticket->id) }}">
                                        @csrf
                                        <input type="hidden" name="invoice" value="{{ $invoice }}">
                                        <button class="button">{{ $t('Mark Collected') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9">{{ $t('No ticket collections found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <nav class="report-navigation">
        @if($tickets->previousPageUrl())<a class="button" href="{{ $tickets->previousPageUrl() }}">{{ $t('Previous') }}</a>@endif
        @if($tickets->nextPageUrl())<a class="button" href="{{ $tickets->nextPageUrl() }}">{{ $t('Next') }}</a>@endif
    </nav>
</div>
@endcomponent
