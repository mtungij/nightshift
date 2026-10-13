<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ServiceTable;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class OrderService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private WaiterLedgerService $ledger, private ShiftService $shifts, private AuditService $audit) {}

    public function accessible(int $id, string $permission = 'view-orders'): Order
    {
        Gate::authorize($permission);
        $o = Order::whereKey($id)->firstOrFail();
        Gate::authorize('view', $o);

        return $o;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Order
    {
        return $this->createOrder($data);
    }

    /** @param array<string, mixed> $data */
    private function createOrder(array $data, ?User $identifiedWaiter = null): Order
    {
        Gate::authorize('create-orders');

        return $this->tx->run(function () use ($data, $identifiedWaiter) {
            $actor = app(TenantContext::class)->user();
            $location = $this->stock->location($data['stock_location_id']);
            $visibility = app(StaffVisibility::class);
            $visibility->assertOperationalLocation($location->id);
            $this->stock->require($location->type === 'COUNTER', 'Orders must be assigned to a counter.');
            if ($identifiedWaiter) {
                $this->stock->require(Branch::findOrFail($location->branch_id)->active, 'Branch is inactive.');
            }
            $waiter = User::where('company_id', $actor->company_id)->whereKey($data['waiter_id'])->firstOrFail();
            if (! $identifiedWaiter && ! $visibility->broad() && ! $visibility->settlementOnly()) {
                abort_unless($waiter->id === $actor->id, 403);
            }
            $this->stock->require($waiter->active && $waiter->hasPermission('create-orders') && $waiter->canAccessBranch($location->branch_id), 'Waiter is not available at this branch.');
            $shift = Shift::where('user_id', $waiter->id)->where('branch_id', $location->branch_id)->when($identifiedWaiter, fn ($q) => $q->where('stock_location_id', $location->id))->where('type', 'WAITER')->where('status', 'OPEN')->lockForUpdate()->first();
            $this->stock->require((bool) $shift, 'The waiter needs an open shift.');
            $table = isset($data['service_table_id']) ? ServiceTable::whereKey($data['service_table_id'])->firstOrFail() : null;
            $this->stock->require(! $table || $table->branch_id === $location->branch_id, 'Table and counter must belong to the same branch.');
            $items = [];
            $subtotal = '0.00';
            foreach ($data['items'] as $line) {
                $unit = $this->stock->unit($line['product_unit_id']);
                $qty = D::quantity($line['quantity']);
                $this->stock->require(D::cmp($qty, '0') > 0, 'Order quantities must be positive.');
                $total = D::mul($qty, $unit->selling_price);
                $subtotal = D::add($subtotal, $total);
                $items[] = ['branch_id' => $location->branch_id, 'product_id' => $unit->product_id, 'product_unit_id' => $unit->id, 'product_name' => $unit->product->name, 'unit_name' => $unit->unit->name, 'quantity' => $qty, 'base_quantity' => D::mul($qty, $unit->conversion_quantity, 6), 'conversion_quantity' => $unit->conversion_quantity, 'unit_price' => $unit->selling_price, 'purchase_cost' => $unit->product->purchase_cost, 'total' => $total];
            }
            $discount = D::money($data['discount'] ?? '0');
            $this->stock->require(D::cmp($discount, '0') >= 0 && D::cmp($discount, $subtotal) <= 0, 'Discount cannot exceed the subtotal.');
            $total = D::sub($subtotal, $discount);
            $o = Order::create(['branch_id' => $location->branch_id, 'stock_location_id' => $location->id, 'waiter_id' => $waiter->id, 'service_table_id' => $table?->id, 'shift_id' => $shift->id, 'invoice_number' => $this->numbers->next('INV'), 'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total, 'balance' => $total, 'status' => $data['status'] ?? 'WAITING_FOR_ISSUE', 'created_by' => $actor->id]);
            // Floor proportional shares, then distribute the remaining cents. No line
            // can receive a negative discount or more than its original gross value.
            $remaining = $discount;
            foreach ($items as &$line) {
                $line['discount_share'] = D::cmp($subtotal, '0') === 0 ? '0.00' : bcdiv(bcmul($discount, $line['total'], 8), $subtotal, 2);
                $remaining = D::sub($remaining, $line['discount_share']);
            }
            unset($line);
            foreach ($items as &$line) {
                if (D::cmp($remaining, '0') > 0 && D::cmp($line['discount_share'], $line['total']) < 0) {
                    $line['discount_share'] = D::add($line['discount_share'], '0.01');
                    $remaining = D::sub($remaining, '0.01');
                }
            }
            unset($line);
            foreach ($items as $line) {
                $o->items()->create($line);
            }
            $this->audit->record('ORDER_CREATED', 'Orders', 'Order', $o->id, [], ['invoice' => $o->invoice_number, 'status' => $o->status, 'total' => $total], $o->branch_id);
            $this->audit->notify($o->branch_id, 'NEW_ORDER', 'New waiter order', "{$o->invoice_number} from {$waiter->name}", 'orders', $waiter->id, $o->stock_location_id);

            return $o;
        });
    }

    public function submit(int $id): Order
    {
        return $this->tx->run(function () use ($id) {
            $o = $this->accessible($id, 'create-orders');
            $o = Order::lockForUpdate()->findOrFail($id);
            $this->stock->require($o->status === 'DRAFT', 'Only draft orders can be submitted.');
            $o->update(['status' => 'WAITING_FOR_ISSUE']);
            $this->audit->record('ORDER_UPDATED', 'Orders', 'Order', $o->id, ['status' => 'DRAFT'], ['status' => $o->status], $o->branch_id);

            return $o;
        });
    }

    public function issue(int $id): Order
    {
        $this->accessible($id, 'issue-drinks');

        return $this->issueOrder($id);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function send(#[\SensitiveParameter] array $data, string $key): array
    {
        Gate::authorize('create-orders');
        $locationId = ! empty($data['id']) ? $this->accessible((int) $data['id'], 'create-orders')->stock_location_id : (int) $data['stock_location_id'];
        $location = $this->stock->location($locationId);
        if (! app(StaffVisibility::class)->broad() && ! app(StaffVisibility::class)->settlementOnly()) {
            abort_unless((int) $data['waiter_id'] === app(TenantContext::class)->user()->id, 403);
        }
        app(StaffVisibility::class)->assertOperationalLocation($locationId);
        $candidate = User::where('company_id', app(TenantContext::class)->companyId())->findOrFail((int) $data['waiter_id']);
        abort_unless($candidate->active && $candidate->role->name === 'Waiter' && $candidate->canAccessBranch($location->branch_id), 403);
        $identified = app(StaffPinService::class)->verify((int) $data['waiter_id'], $data['pin']);
        $fingerprint = hash_hmac('sha256', $data['pin'], config('app.key'));
        unset($data['pin']);

        return $this->tx->idempotent($key, 'order-send', [...$data, 'credential' => $fingerprint], function () use ($data, $identified) {
            $waiter = User::where('company_id', app(TenantContext::class)->companyId())->lockForUpdate()->findOrFail($identified->id);
            abort_unless($waiter->active && $waiter->role->name === 'Waiter' && $waiter->hasPermission('create-orders') && $waiter->operational_pin === $identified->operational_pin, 403);
            if (! empty($data['id'])) {
                $order = Order::lockForUpdate()->findOrFail((int) $data['id']);
                $this->stock->require($order->status === 'DRAFT' && $order->waiter_id === $waiter->id, 'Identify the waiter assigned to this draft.');
                $order->update(['status' => 'WAITING_FOR_ISSUE']);
            } else {
                $order = $this->createOrder([...$data, 'status' => 'WAITING_FOR_ISSUE'], $waiter);
            }
            $location = $this->stock->location($order->stock_location_id);
            $this->stock->require($waiter->canAccessBranch($location->branch_id), 'Waiter is not available at this branch.');
            $order = $this->issueOrder($order->id, true);

            return ['id' => $order->id, 'status' => $order->status, 'ticket_url' => route('nightshift.issue-ticket', $order->id, false)];
        });
    }

    private function issueOrder(int $id, bool $direct = false): Order
    {
        return $this->tx->run(function () use ($id, $direct) {
            $o = Order::lockForUpdate()->findOrFail($id);
            if (in_array($o->status, ['ISSUED', 'PARTIALLY_PAID', 'PAID'], true)) {
                return $o;
            }
            $this->stock->require($o->status === 'WAITING_FOR_ISSUE', 'Only queued orders can be issued.');
            $waiterShift = Shift::lockForUpdate()->findOrFail($o->shift_id);
            $this->stock->require($waiterShift->status === 'OPEN', 'The waiter shift is closed.');
            $this->stock->require(! $direct || ($waiterShift->type === 'WAITER' && $waiterShift->user_id === $o->waiter_id && $waiterShift->branch_id === $o->branch_id && $waiterShift->stock_location_id === $o->stock_location_id), 'Waiter shift and counter must match the order.');
            $issueShift = $direct ? $waiterShift : $this->shifts->activeCashier($o->stock_location_id);
            foreach ($o->items()->orderBy('product_id')->get() as $line) {
                $this->stock->change($o->stock_location_id, $line->product_id, D::sub('0', $line->base_quantity, 6), 'ISSUE', 'Order', $o->id, $issueShift->id);
            }
            $o->update(['status' => D::cmp($o->total, '0') === 0 ? 'PAID' : 'ISSUED', 'issued_by' => app(TenantContext::class)->user()->id, 'issued_shift_id' => $issueShift->id, 'issued_at' => now(), 'closed_at' => D::cmp($o->total, '0') === 0 ? now() : null]);
            $this->ledger->post($o, 'ISSUE', $o->total, '0', 'Order', $o->id, 'Drinks issued: '.$o->invoice_number);
            $this->audit->record('DRINKS_ISSUED', 'Orders', 'Order', $o->id, ['status' => 'WAITING_FOR_ISSUE'], ['status' => $o->status, 'issue_shift' => $issueShift->id, 'direct_send' => $direct, 'total' => $o->total], $o->branch_id);

            return $o;
        });
    }

    public function cancel(int $id): Order
    {
        return $this->tx->run(function () use ($id) {
            $o = $this->accessible($id);
            $o = Order::lockForUpdate()->findOrFail($id);
            $this->stock->require(in_array($o->status, ['DRAFT', 'WAITING_FOR_ISSUE'], true), 'Issued invoices require an approved void.');
            $old = $o->status;
            $o->update(['status' => 'CANCELLED', 'closed_at' => now()]);
            $this->audit->record('ORDER_UPDATED', 'Orders', 'Order', $o->id, ['status' => $old], ['status' => 'CANCELLED'], $o->branch_id);

            return $o;
        });
    }
}
