<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use Illuminate\Support\Facades\Gate;

final class ReturnService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private WaiterLedgerService $ledger, private ShiftService $shifts, private AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function process(int $id, array $data): OrderReturn
    {
        Gate::authorize('return-items');

        return $this->tx->run(function () use ($id, $data) {
            $o = Order::lockForUpdate()->findOrFail($id);
            Gate::authorize('view', $o);
            $this->stock->require(in_array($o->status, ['ISSUED', 'PARTIALLY_PAID', 'PAID'], true), 'Returns require an issued invoice. Paid value requires an approved refund.');
            $cashier = $this->shifts->activeCashier($o->stock_location_id);
            $lines = [];
            $value = '0.00';
            $seen = [];
            foreach ($data['items'] as $entry) {
                $line = OrderItem::where('order_id', $o->id)->lockForUpdate()->whereKey($entry['order_item_id'])->firstOrFail();
                $this->stock->require(! in_array($line->id, $seen, true), 'Duplicate return item.');
                $seen[] = $line->id;
                $qty = D::quantity($entry['quantity']);
                $available = D::sub($line->quantity, $line->returned_quantity, 6);
                $this->stock->require(D::cmp($qty, '0') > 0 && D::cmp($qty, $available) <= 0, 'Returned quantity exceeds unreturned issued quantity.');
                $net = D::sub($line->total, $line->discount_share);
                $full = D::cmp($qty, $available) === 0;
                $amount = $full ? D::sub($net, $line->returned_value) : D::money(bcdiv(bcmul($net, $qty, 8), $line->quantity, 4));
                $remainingValue = D::sub($net, $line->returned_value);
                if (D::cmp($amount, $remainingValue) > 0) {
                    $amount = $remainingValue;
                }
                $value = D::add($value, $amount);
                $lines[] = [$line, $qty, $amount, D::mul($qty, $line->conversion_quantity, 6)];
            }
            $this->stock->require(D::cmp($value, $o->balance) <= 0, 'Paid value cannot be returned without a refund workflow.');
            $return = OrderReturn::create(['branch_id' => $o->branch_id, 'order_id' => $o->id, 'shift_id' => $cashier->id, 'number' => $this->numbers->next('RET'), 'reason' => $data['reason'], 'total' => $value, 'created_by' => app(TenantContext::class)->user()->id]);
            foreach ($lines as [$line,$qty,$amount,$base]) {
                ReturnItem::create(['branch_id' => $o->branch_id, 'return_id' => $return->id, 'order_item_id' => $line->id, 'quantity' => $qty, 'base_quantity' => $base, 'value' => $amount]);
                $this->stock->change($o->stock_location_id, $line->product_id, $base, 'RETURN', 'OrderReturn', $return->id, $cashier->id);
                $line->update(['returned_quantity' => D::add($line->returned_quantity, $qty, 6), 'returned_value' => D::add($line->returned_value, $amount)]);
            }
            $old = ['total' => $o->total, 'balance' => $o->balance];
            $o->return_total = D::add($o->return_total, $value);
            $o->total = D::sub($o->total, $value);
            $o->balance = D::sub($o->total, $o->amount_paid);
            $allReturned = ! $o->items()->whereColumn('returned_quantity', '<', 'quantity')->exists();
            if (D::cmp($o->credit_amount, $o->balance) > 0) {
                $o->credit_amount = $o->balance;
            }
            $o->status = $allReturned ? 'RETURNED' : (D::cmp($o->balance, '0') === 0 ? 'PAID' : $o->status);
            if (in_array($o->status, ['PAID', 'RETURNED'])) {
                $o->closed_at = now();
            }
            $o->save();
            $this->ledger->post($o, 'RETURN', '0', $value, 'OrderReturn', $return->id, 'Return processed: '.$return->number, null, $return->id);
            $this->audit->record('RETURN_PROCESSED', 'Orders', 'OrderReturn', $return->id, $old, ['order' => $o->id, 'total' => $o->total, 'returned' => $value], $o->branch_id);

            return $return;
        });
    }
}
