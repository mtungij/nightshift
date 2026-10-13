<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class PaymentService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private WaiterLedgerService $ledger, private ShiftService $shifts, private AuditService $audit) {}

    /** @return array<string, mixed> */
    public function receiveWaiter(int $waiterId, int $locationId, string $amount, string $method): array
    {
        Gate::authorize('receive-payments');

        return $this->tx->run(function () use ($waiterId, $locationId, $amount, $method) {
            $actor = app(TenantContext::class)->user();
            $waiter = User::where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($waiterId);
            $this->stock->require($waiter->role->name === 'Waiter', 'Select a waiter account.');
            $location = $this->stock->location($locationId);
            app(StaffVisibility::class)->assertOperationalLocation($location->id);
            if (app(StaffVisibility::class)->settlementOnly()) {
                abort_unless($waiter->canAccessBranch($location->branch_id) && app(StaffVisibility::class)->waiters(User::query())->whereKey($waiter->id)->exists(), 403);
            }
            $this->shifts->activeCashier($location->id);
            $this->stock->require($method !== 'Credit', 'Account settlement requires a received payment.');
            $orders = Order::where('waiter_id', $waiter->id)->where('stock_location_id', $location->id)->whereIn('status', ['ISSUED', 'PARTIALLY_PAID'])->orderBy('id')->lockForUpdate()->get();
            $remaining = D::money($amount);
            $available = '0.00';
            foreach ($orders as $order) {
                $available = D::add($available, $order->balance);
            }
            $this->stock->require(D::cmp($remaining, '0') > 0 && D::cmp($remaining, $available) <= 0, 'Payment must not exceed this counter’s outstanding issued invoices.');
            $allocations = [];
            foreach ($orders as $order) {
                if (D::cmp($remaining, '0') === 0) {
                    break;
                }
                $portion = D::cmp($remaining, $order->balance) < 0 ? $remaining : $order->balance;
                $this->receive($order->id, [['method' => $method, 'amount' => $portion]]);
                $allocations[] = ['order_id' => $order->id, 'amount' => $portion];
                $remaining = D::sub($remaining, $portion);
            }

            return ['waiter_id' => $waiter->id, 'amount' => D::money($amount), 'outstanding' => $this->ledger->visibleBalance($waiter->id, $location->id), 'allocations' => $allocations];
        });
    }

    /**
     * @param  list<array{method: string, amount: numeric-string, reference?: string}>  $lines
     */
    public function receive(int $id, array $lines): Order
    {
        Gate::authorize('receive-payments');

        return $this->tx->run(function () use ($id, $lines) {
            $o = Order::lockForUpdate()->findOrFail($id);
            Gate::authorize('view', $o);
            $this->stock->require(in_array($o->status, ['ISSUED', 'PARTIALLY_PAID'], true), 'Only issued unpaid orders accept payments.');
            $cashier = $this->shifts->activeCashier($o->stock_location_id);
            $total = '0.00';
            $newCredit = '0.00';
            foreach ($lines as $line) {
                $amount = D::money($line['amount']);
                $this->stock->require(D::cmp($amount, '0') > 0, 'Each payment amount must be positive.');
                $this->stock->require(in_array($line['method'], ['Cash', 'M-Pesa', 'Airtel Money', 'Mixx by Yas', 'Card', 'Bank', 'Credit'], true), 'Invalid payment method.');
                $total = D::add($total, $amount);
                if ($line['method'] === 'Credit') {
                    $newCredit = D::add($newCredit, $amount);
                }
            }
            $this->stock->require(D::cmp($total, $o->balance) <= 0, 'Payment exceeds the invoice balance.');
            $this->stock->require(D::cmp(D::add($o->credit_amount, $newCredit), $o->balance) <= 0, 'Credit exceeds the unassigned invoice balance.');
            $old = ['status' => $o->status, 'balance' => $o->balance];
            foreach ($lines as $line) {
                $amount = D::money($line['amount']);
                $credit = $line['method'] === 'Credit';
                $p = Payment::create(['order_id' => $o->id, 'branch_id' => $o->branch_id, 'shift_id' => $cashier->id, 'number' => $this->numbers->next('PAY'), 'method' => $line['method'], 'amount' => $amount, 'is_credit' => $credit, 'received_by' => app(TenantContext::class)->user()->id, 'external_reference' => $line['reference'] ?? ($credit ? $o->invoice_number : null)]);
                if (! $credit) {
                    PaymentAllocation::create(['branch_id' => $o->branch_id, 'payment_id' => $p->id, 'order_id' => $o->id, 'amount' => $amount]);
                }
                if (! $credit) {
                    $o->amount_paid = D::add($o->amount_paid, $amount);
                    $this->ledger->post($o, 'PAYMENT', '0', $amount, 'Payment', $p->id, 'Payment received: '.$p->number, $p->id);
                } else {
                    $o->credit_amount = D::add($o->credit_amount, $amount);
                }
            }
            $o->balance = D::sub($o->total, $o->amount_paid);
            if (D::cmp($o->credit_amount, $o->balance) > 0) {
                $o->credit_amount = $o->balance;
            }
            $o->status = D::cmp($o->balance, '0') === 0 ? 'PAID' : (D::cmp($o->amount_paid, '0') > 0 ? 'PARTIALLY_PAID' : 'ISSUED');
            if ($o->status === 'PAID') {
                $o->closed_at = now();
            }
            $o->save();
            $this->audit->record('PAYMENT_RECEIVED', 'Payments', 'Order', $o->id, $old, ['status' => $o->status, 'balance' => $o->balance, 'amount' => $total], $o->branch_id);

            return $o;
        });
    }
}
