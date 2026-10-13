<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\StockMovement;
use App\Models\VoidRequest;
use Illuminate\Support\Facades\Gate;

final class VoidService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private WaiterLedgerService $ledger, private OrderService $orders, private ShiftService $shifts, private AuditService $audit) {}

    public function request(int $id, string $reason): VoidRequest
    {
        Gate::authorize('request-void');

        return $this->tx->run(function () use ($id, $reason) {
            $o = $this->orders->accessible($id, 'request-void');
            $o = Order::lockForUpdate()->findOrFail($id);
            $this->stock->require(in_array($o->status, ['ISSUED', 'PARTIALLY_PAID', 'PAID'], true), 'Only issued orders can request a void.');
            $this->stock->require(! VoidRequest::where('order_id', $o->id)->where('status', 'PENDING')->exists(), 'A void request is already pending.');
            $v = VoidRequest::create(['branch_id' => $o->branch_id, 'order_id' => $o->id, 'number' => $this->numbers->next('VDR'), 'reason' => $reason, 'previous_status' => $o->status, 'requested_by' => app(TenantContext::class)->user()->id]);
            $o->update(['status' => 'VOID_REQUESTED']);
            $this->audit->record('VOID_REQUESTED', 'Orders', 'VoidRequest', $v->id, ['status' => $v->previous_status], ['reason' => $reason], $o->branch_id);
            $this->audit->notify($o->branch_id, 'VOID_REQUESTED', 'Void request awaiting approval', $v->number.' · '.$o->invoice_number, 'approvals', $v->requested_by, $o->stock_location_id);

            return $v;
        });
    }

    public function decide(int $id, bool $approved, string $reason = ''): VoidRequest
    {
        Gate::authorize('approve-void');

        return $this->tx->run(function () use ($id, $approved, $reason) {
            $v = VoidRequest::lockForUpdate()->findOrFail($id);
            if ($v->status === ($approved ? 'APPROVED' : 'REJECTED')) {
                return $v;
            }
            $this->stock->require($v->status === 'PENDING', 'Void request is already decided.');
            $o = Order::lockForUpdate()->findOrFail($v->order_id);
            $this->stock->require($o->status === 'VOID_REQUESTED', 'Invoice is not pending a void.');
            if ($approved) {
                $this->stock->require(strlen(trim($reason)) >= 8, 'Confirm that goods were recovered and explain the void approval.');
                foreach ($o->items()->orderBy('product_id')->get() as $line) {
                    $remaining = D::sub($line->quantity, $line->returned_quantity, 6);
                    if (D::cmp($remaining, '0') > 0) {
                        $source = StockMovement::where('reference_type', 'Order')->where('reference_id', $o->id)->where('product_id', $line->product_id)->where('movement_type', 'ISSUE')->first();
                        $this->stock->change($o->stock_location_id, $line->product_id, D::mul($remaining, $line->conversion_quantity, 6), 'VOID_REVERSAL', 'VoidRequest', $v->id, $o->issued_shift_id, $source?->id);
                    }
                }
                $this->ledger->post($o, 'REVERSAL', '0', $o->total, 'VoidRequest', $v->id, 'Void liability reversal: '.$v->number);
                if (D::cmp($o->amount_paid, '0') > 0) {
                    $cashier = $this->shifts->activeCashier($o->stock_location_id);
                    foreach (PaymentAllocation::where('order_id', $o->id)->get() as $allocation) {
                        $paid = Payment::whereKey($allocation->payment_id)->firstOrFail();
                        if ($paid->is_credit || D::cmp($paid->amount, '0') <= 0) {
                            continue;
                        }
                        $refund = Payment::create(['order_id' => $o->id, 'branch_id' => $o->branch_id, 'shift_id' => $cashier->id, 'number' => $this->numbers->next('RFD'), 'method' => $paid->method, 'amount' => D::sub('0', $allocation->amount), 'is_credit' => false, 'received_by' => app(TenantContext::class)->user()->id, 'external_reference' => 'Void '.$v->number]);
                        PaymentAllocation::create(['branch_id' => $o->branch_id, 'payment_id' => $refund->id, 'order_id' => $o->id, 'amount' => $refund->amount]);
                        $this->ledger->post($o, 'REVERSAL', $allocation->amount, '0', 'Payment', $refund->id, 'Refund reverses received payment: '.$v->number, $refund->id);
                    }
                }
                $o->update(['status' => 'VOIDED', 'total' => '0', 'amount_paid' => '0', 'balance' => '0', 'credit_amount' => '0', 'closed_at' => now()]);
            } else {
                $this->stock->require(strlen(trim($reason)) >= 8, 'Record the rejection reason.');
                $o->update(['status' => $v->previous_status]);
            }
            $v->update(['status' => $approved ? 'APPROVED' : 'REJECTED', 'approved_by' => app(TenantContext::class)->user()->id, 'decided_at' => now(), 'decision_reason' => $reason]);
            $this->audit->record($approved ? 'VOID_APPROVED' : 'VOID_REJECTED', 'Orders', 'VoidRequest', $v->id, ['status' => 'PENDING', 'invoice_total' => D::sub($o->subtotal, $o->discount)], ['status' => $v->status, 'reason' => $reason], $o->branch_id);

            return $v;
        });
    }
}
