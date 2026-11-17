<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\OrderCollectionMode;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\TicketCollection;
use App\Models\VoidRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class TicketCollectionService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private AuditService $audit) {}

    public function authorize(): void
    {
        Gate::authorize('receive-payments');
        abort_unless(app(TenantContext::class)->user()->company->active, 403);
    }

    /** @return Builder<TicketCollection> */
    public function visible(): Builder
    {
        $this->authorize();
        $actor = app(TenantContext::class)->user();
        $visibility = app(StaffVisibility::class);
        $locations = $visibility->atCounter(StockLocation::where('type', 'COUNTER'), 'id')->pluck('id')
            ->filter(fn ($id) => $actor->canAccessCounter($id));

        return TicketCollection::whereIn('stock_location_id', $locations)
            ->whereHas('order', fn ($q) => $q->where('collection_mode', OrderCollectionMode::PaidTicket->value));
    }

    public function start(Order $order, Shift $shift): void
    {
        TicketCollection::create([
            'branch_id' => $order->branch_id,
            'business_unit_id' => $order->business_unit_id,
            'stock_location_id' => $order->stock_location_id,
            'order_id' => $order->id,
            'cashier_shift_id' => $shift->id,
            'amount' => $order->total,
            'status' => 'PENDING',
        ]);
        $this->record('PAID_TICKET_ORDER_CREATED', $order, $shift, null, $order->total);
    }

    public function collect(int $id): TicketCollection
    {
        $this->authorize();

        return $this->tx->run(function () use ($id) {
            $ticket = $this->visible()->lockForUpdate()->findOrFail($id);
            $order = Order::lockForUpdate()->findOrFail($ticket->order_id);
            abort_unless($order->branch_id === $ticket->branch_id && $order->business_unit_id === $ticket->business_unit_id && $order->stock_location_id === $ticket->stock_location_id, 404);
            if ($ticket->status === 'COLLECTED') {
                return $ticket;
            }
            $this->stock->require($ticket->status === 'PENDING' && $order->issued_at !== null && in_array($order->status, ['PAID', 'RETURNED'], true), __('ui.This ticket is unavailable for collection.'));
            $shift = app(ShiftService::class)->activeCashier($ticket->stock_location_id);
            abort_unless($shift->branch_id === $ticket->branch_id && $shift->business_unit_id === $ticket->business_unit_id, 403);
            $cashier = app(TenantContext::class)->user()->id;
            $ticket->update(['status' => 'COLLECTED', 'cashier_shift_id' => $shift->id, 'collected_by' => $cashier, 'collected_at' => now()]);
            $this->record('PAID_TICKET_COLLECTED', $order, $shift, $cashier, $ticket->amount);

            return $ticket;
        });
    }

    public function void(Order $order, VoidRequest $void): void
    {
        $ticket = TicketCollection::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        if ($ticket->status === 'PENDING') {
            $ticket->update(['status' => 'CANCELLED']);
            $shift = Shift::findOrFail($ticket->cashier_shift_id);
            $this->record('PAID_TICKET_COLLECTION_CANCELLED', $order, $shift, null, $ticket->amount);

            return;
        }
        if ($ticket->status === 'COLLECTED' && D::cmp($ticket->amount, '0') > 0) {
            $shift = app(ShiftService::class)->activeCashier($ticket->stock_location_id);
            $cashier = app(TenantContext::class)->user()->id;
            $refund = Payment::create(['order_id' => $order->id, 'branch_id' => $order->branch_id, 'shift_id' => $shift->id,
                'number' => app(NumberingService::class)->next('RFD'), 'method' => 'Cash', 'amount' => D::sub('0', $ticket->amount),
                'is_credit' => false, 'received_by' => $cashier, 'external_reference' => 'Void '.$void->number]);
            PaymentAllocation::create(['branch_id' => $order->branch_id, 'payment_id' => $refund->id, 'order_id' => $order->id, 'amount' => $refund->amount]);
            $this->record('PAID_TICKET_REFUNDED', $order, $shift, $cashier, $refund->amount);
        }
    }

    public function ensureNoPending(Shift $shift): void
    {
        $this->stock->require(! $this->forShift($shift)->where('status', 'PENDING')->exists(), __('ui.Collect pending tickets before closing or handing over cash.'));
    }

    /** @return Builder<TicketCollection> */
    public function forShift(Shift $shift): Builder
    {
        abort_unless(app(TenantContext::class)->companyId() === $shift->company_id, 404);

        return TicketCollection::withoutGlobalScopes()->where('company_id', $shift->company_id)
            ->where('branch_id', $shift->branch_id)->where('business_unit_id', $shift->business_unit_id)
            ->where('stock_location_id', $shift->stock_location_id)->where('cashier_shift_id', $shift->id);
    }

    /** @return numeric-string */
    public function collectedCash(Shift $shift): string
    {
        return D::money((string) $this->forShift($shift)->where('status', 'COLLECTED')->sum('amount'));
    }

    private function record(string $event, Order $order, Shift $shift, ?int $collector, string $amount): void
    {
        $this->audit->record($event, 'Ticket Collection', 'Order', $order->id, [], [
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'business_unit_id' => $order->business_unit_id,
            'stock_location_id' => $order->stock_location_id,
            'order_id' => $order->id,
            'invoice' => $order->invoice_number,
            'waiter_id' => $order->waiter_id,
            'cashier_id' => $collector ?? $shift->user_id,
            'cashier_shift_id' => $shift->id,
            'amount' => $amount,
        ], $order->branch_id);
    }
}
