<?php

namespace App\Http\Controllers;

use App\Services\TicketCollectionService;
use App\Support\UiText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TicketCollectionController
{
    public function index(Request $request, TicketCollectionService $collections): View
    {
        $query = $collections->visible();
        $invoice = strtoupper($request->validate(['invoice' => ['nullable', 'string', 'max:60']])['invoice'] ?? '');

        if ($invoice === '') {
            $query->where('status', 'PENDING');
        } else {
            $query->whereHas('order', fn ($order) => $order->where('invoice_number', $invoice));
        }

        return view('ticket-collections', [
            'invoice' => $invoice,
            'tickets' => $query->with(['order.waiter', 'branch', 'businessUnit', 'counter'])->latest('id')->simplePaginate(30)->withQueryString(),
        ]);
    }

    public function collect(Request $request, int $id, TicketCollectionService $collections): RedirectResponse
    {
        $collections->authorize();
        $data = $request->validate(['invoice' => ['nullable', 'string', 'max:60']]);
        $collections->collect($id);

        return redirect()->route('ticket-collections.index', $data)->with('status', UiText::get('Ticket collection recorded.'));
    }
}
