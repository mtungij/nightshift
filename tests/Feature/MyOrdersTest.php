<?php

namespace Tests\Feature;

use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SalesTicketBatch;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\MyOrdersService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use App\Services\StaffPinService;
use App\Services\WaiterLedgerService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MyOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $terminal;

    private User $asha;

    private User $james;

    private User $cashier;

    private User $otherCashier;

    private StockLocation $counter;

    private ProductUnit $unit;

    private Order $unpaid;

    private Order $partial;

    private Order $paid;

    private Order $jamesOrder;

    private Order $otherCashierPaid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->counter = StockLocation::where('name', 'Main Counter')->firstOrFail();
        $this->unit = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', 'Piece'))->firstOrFail();
        $this->asha = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $this->james = User::where('email', 'james@nightshift.test')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        foreach ([[$this->asha, '0123'], [$this->james, '6789'], [$this->cashier, '4567']] as [$staff, $pin]) {
            app(StaffPinService::class)->set($staff->id, $pin);
        }
        $this->otherCashier = $this->employee('Cashier', '9876');
        $this->unpaid = $this->issue($this->asha, '0123');
        $this->partial = $this->issue($this->asha, '0123', '2');
        $this->paid = $this->issue($this->asha, '0123');
        $this->jamesOrder = $this->issue($this->james, '6789');
        $this->otherCashierPaid = $this->issue($this->james, '6789');
        $this->actingAs($this->cashier);
        app(PaymentService::class)->receive($this->partial->id, [['method' => 'Cash', 'amount' => '3000']]);
        app(PaymentService::class)->receive($this->paid->id, [['method' => 'Cash', 'amount' => '3000']]);
        // Preserve a previous cashier's paid history without overlapping operations.
        $legacy = Shift::where('user_id', $this->otherCashier->id)->firstOrFail();
        $payment = Payment::create(['branch_id' => $this->counter->branch_id, 'shift_id' => $legacy->id, 'order_id' => $this->otherCashierPaid->id, 'number' => 'LEGACY-PAID', 'method' => 'Cash', 'amount' => '3000', 'is_credit' => false, 'received_by' => $this->otherCashier->id]);
        app(WaiterLedgerService::class)->post($this->otherCashierPaid, 'PAYMENT', '0', '3000', 'Payment', $payment->id, 'Historical cashier payment', $payment->id);
        $this->otherCashierPaid->update(['amount_paid' => '3000', 'balance' => '0', 'status' => 'PAID', 'closed_at' => now()]);
        $this->terminal = User::factory()->create([
            'name' => 'Shared POS',
            'company_id' => $this->owner->company_id,
            'role_id' => Role::where('name', 'POS Terminal')->firstOrFail()->id,
            'terminal_stock_location_id' => $this->counter->id,
            'active' => true,
        ]);

        $this->terminal->branches()->syncWithoutDetaching([
            $this->counter->branch_id => [
                'company_id' => $this->terminal->company_id,
            ],
        ]);

        $this->terminal->businessUnits()->syncWithoutDetaching([
            $this->counter->business_unit_id => [
                'company_id' => $this->terminal->company_id,
            ],
        ]);

        $this->actingAs($this->terminal);
    }

    private function employee(string $role, string $pin, ?StockLocation $counter = null): User
    {
        $counter ??= $this->counter;
        $user = User::factory()->create([
            'company_id' => $this->owner->company_id,
            'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'active' => true,
            'operational_pin' => Hash::make($pin),
        ]);

        $user->branches()->attach($counter->branch_id, [
            'company_id' => $user->company_id,
        ]);

        $user->businessUnits()->syncWithoutDetaching([
            $counter->business_unit_id => [
                'company_id' => $user->company_id,
            ],
        ]);
        if ($role === 'Cashier' && Shift::where('stock_location_id', $counter->id)->where('type', 'CASHIER')->where('status', 'OPEN')->exists()) {
            Shift::create(['user_id' => $user->id, 'branch_id' => $counter->branch_id, 'stock_location_id' => $counter->id, 'number' => 'OLD-'.Str::uuid(), 'type' => 'CASHIER', 'status' => 'CLOSED', 'opened_at' => now()->subDays(2), 'closed_at' => now()->subDay(), 'opening_cash' => '0']);
        } else {
            app(ShiftService::class)->open(['user_id' => $user->id, 'stock_location_id' => $counter->id, 'type' => $role === 'Waiter' ? 'WAITER' : 'CASHIER', 'opening_cash' => '0']);
        }

        return $user;
    }

    private function issue(User $waiter, string $pin, string $quantity = '1', ?StockLocation $counter = null): Order
    {
        $data = ['waiter_id' => $waiter->id, 'pin' => $pin, 'stock_location_id' => ($counter ?? $this->counter)->id, 'discount' => '0', 'order_note' => 'Mteja karibu na TV <b>test</b>', 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => $quantity]]];
        $result = app(OrderService::class)->send($data, (string) Str::uuid());

        return Order::findOrFail($result['id']);
    }

    private function open(): string
    {
        return $this->getJson('/api/nightshift/my-orders/staff?stock_location_id='.$this->counter->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('view');
    }

    private function verify(User $staff, string $pin): array
    {
        $view = $this->open();
        $result = $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $staff->id, 'pin' => $pin], ['X-My-Orders-View' => $view])->assertOk()->json();

        return [...$result, 'view' => $view];
    }

    private function effects(): array
    {
        return [StockMovement::count(), WaiterLedgerEntry::count(), Payment::count(), PaymentAllocation::count(), SalesTicketBatch::count(), Order::count(), Stock::orderBy('id')->pluck('quantity')->all()];
    }

    public function test_selector_contains_only_eligible_waiters_and_cashiers_at_the_assigned_counter(): void
    {
        $this->actingAs($this->owner);
        $vip = StockLocation::where('name', 'VIP Counter')->firstOrFail();
        $wrongCounter = $this->employee('Waiter', '3333', $vip);
        $wrongBranch = Branch::create(['name' => 'Other branch', 'active' => true]);
        $notAssigned = $this->employee('Waiter', '4444');
        $notAssigned->branches()->sync([$wrongBranch->id => ['company_id' => $this->owner->company_id]]);
        $foreign = User::factory()->create(['role_id' => $this->asha->role_id]);
        $this->actingAs($this->terminal);
        $staff = $this->getJson('/api/nightshift/my-orders/staff?stock_location_id='.$this->counter->id)->assertOk()->json('staff');
        $ids = array_column($staff, 'id');
        $this->assertEqualsCanonicalizing([$this->asha->id, $this->james->id, $this->cashier->id], $ids);
        foreach ([$wrongCounter->id, $notAssigned->id, $foreign->id, $this->owner->id, $this->terminal->id] as $id) {
            $this->assertNotContains($id, $ids);
        }
        foreach ($staff as $row) {
            $this->assertSame(['id', 'name', 'role'], array_keys($row));
        }
    }

    public function test_inactive_closed_shift_missing_pin_and_removed_permissions_exclude_staff(): void
    {
        $this->asha->forceFill(['active' => false])->save();
        Shift::where('user_id', $this->james->id)->update(['status' => 'CLOSED']);
        $this->cashier->forceFill(['operational_pin' => null])->save();
        $this->otherCashier->role->permissions()->detach();
        $response = $this->getJson('/api/nightshift/my-orders/staff?stock_location_id='.$this->counter->id)->assertOk();
        $response->assertJsonPath('staff', []);
    }

    public function test_waiter_pin_returns_only_the_selected_waiters_orders_and_note_without_financial_side_effects(): void
    {
        $before = $this->effects();
        $grant = $this->verify($this->asha, '0123');
        $response = $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $ids = array_column($response->json('orders'), 'id');
        foreach ([$this->unpaid, $this->partial, $this->paid] as $order) {
            $this->assertContains($order->id, $ids);
        }
        foreach ([$this->jamesOrder, $this->otherCashierPaid] as $order) {
            $this->assertNotContains($order->id, $ids);
        }
        $row = collect($response->json('orders'))->firstWhere('id', $this->partial->id);
        $this->assertSame('PARTIALLY_PAID', $row['status']);
        $this->assertSame('6000.00', $row['total']);
        $this->assertSame('3000.00', $row['amount_paid']);
        $this->assertSame('3000.00', $row['balance']);
        $this->assertSame('Mteja karibu na TV <b>test</b>', $row['order_note']);
        $this->assertSame($before, $this->effects());
        $this->assertAuthenticatedAs($this->terminal);
        $this->assertSame($this->terminal->id, app(TenantContext::class)->user()->id);
    }

    public function test_waiter_cannot_read_another_waiter_by_id_or_query_tampering(): void
    {
        $grant = $this->verify($this->asha, '0123');
        $headers = ['X-My-Orders-Token' => $grant['token']];
        $this->getJson('/api/nightshift/my-orders/'.$this->jamesOrder->id, $headers)->assertNotFound();
        $this->getJson('/api/nightshift/my-orders/'.$this->unpaid->id, $headers)->assertOk()->assertJsonPath('order.id', $this->unpaid->id);
        $response = $this->getJson('/api/nightshift/my-orders?staff_id='.$this->james->id.'&waiter_id='.$this->james->id.'&stock_location_id=999999', $headers)->assertOk();
        $this->assertNotContains($this->jamesOrder->id, array_column($response->json('orders'), 'id'));
        $this->getJson('/api/nightshift/issue-tickets/'.$this->unpaid->id, $headers)->assertForbidden();
        $this->postJson('/api/nightshift/commands/payment', [])->assertForbidden();
    }

    public function test_all_unpaid_and_paid_use_existing_status_and_include_partial_payments(): void
    {
        $grant = $this->verify($this->asha, '0123');
        $headers = ['X-My-Orders-Token' => $grant['token']];
        $unpaid = $this->getJson('/api/nightshift/my-orders?filter=unpaid', $headers)->assertOk()->json('orders');
        $this->assertContains($this->unpaid->id, array_column($unpaid, 'id'));
        $this->assertContains($this->partial->id, array_column($unpaid, 'id'));
        $this->assertNotContains($this->paid->id, array_column($unpaid, 'id'));
        foreach ($unpaid as $row) {
            $this->assertContains($row['status'], ['ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED']);
        }
        $paid = $this->getJson('/api/nightshift/my-orders?filter=paid', $headers)->assertOk()->json('orders');
        $this->assertContains($this->paid->id, array_column($paid, 'id'));
        foreach ($paid as $row) {
            $this->assertSame('PAID', $row['status']);
        }
        $this->getJson('/api/nightshift/my-orders?filter=invalid', $headers)->assertUnprocessable();
    }

    public function test_cashier_scope_preserves_unpaid_settlement_activity_and_own_paid_history(): void
    {
        $grant = $this->verify($this->cashier, '4567');
        $headers = ['X-My-Orders-Token' => $grant['token']];
        $ids = array_column($this->getJson('/api/nightshift/my-orders', $headers)->assertOk()->json('orders'), 'id');
        foreach ([$this->unpaid, $this->partial, $this->paid, $this->jamesOrder] as $order) {
            $this->assertContains($order->id, $ids);
        }
        $this->assertNotContains($this->otherCashierPaid->id, $ids);
        $this->getJson('/api/nightshift/my-orders/'.$this->otherCashierPaid->id, $headers)->assertNotFound();
        $this->assertAuthenticatedAs($this->terminal);
    }

    public function test_cashier_reads_own_allocated_settlement_history_without_a_direct_payment_source_order(): void
    {
        $this->actingAs($this->owner);
        $order = $this->issue($this->asha, '0123');
        $shift = Shift::where('user_id', $this->cashier->id)->where('type', 'CASHIER')->where('status', 'OPEN')->firstOrFail();
        // Existing allocation-based historical payments legitimately have no source order_id.
        $payment = Payment::create(['branch_id' => $order->branch_id, 'shift_id' => $shift->id, 'number' => 'LEGACY-'.Str::uuid(), 'method' => 'Cash', 'amount' => $order->total, 'is_credit' => false, 'received_by' => $this->cashier->id]);
        PaymentAllocation::create(['branch_id' => $order->branch_id, 'payment_id' => $payment->id, 'order_id' => $order->id, 'amount' => $order->total]);
        app(WaiterLedgerService::class)->post($order, 'PAYMENT', '0', $order->total, 'Payment', $payment->id, 'Historical allocated payment', $payment->id);
        $order->update(['amount_paid' => $order->total, 'balance' => '0', 'status' => 'PAID', 'closed_at' => now()]);
        $this->actingAs($this->terminal);
        $before = $this->effects();
        $grant = $this->verify($this->cashier, '4567');
        $headers = ['X-My-Orders-Token' => $grant['token']];
        $this->assertContains($order->id, array_column($this->getJson('/api/nightshift/my-orders?filter=paid', $headers)->assertOk()->json('orders'), 'id'));
        $this->getJson('/api/nightshift/my-orders/'.$order->id, $headers)->assertOk();
        $this->actingAs($this->cashier);
        $this->getJson('/api/nightshift/bootstrap')->assertOk();
        $this->get('/api/nightshift/receipts/'.$order->id)->assertOk();
        $this->actingAs($this->terminal);
        $view = $this->open();
        $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $this->otherCashier->id, 'pin' => '9876'], ['X-My-Orders-View' => $view])->assertNotFound();
        $this->assertSame($before, $this->effects());
    }

    public function test_another_cashiers_counter_and_other_company_ids_are_inaccessible(): void
    {
        $this->actingAs($this->owner);
        $vip = StockLocation::where('name', 'VIP Counter')->firstOrFail();
        $waiter = $this->employee('Waiter', '1112', $vip);
        $otherCashier = $this->employee('Cashier', '1113', $vip);
        $order = $this->issue($waiter, '1112', '1', $vip);
        $foreign = User::factory()->create(['role_id' => $this->asha->role_id]);
        $foreignCounter = app(TenantContext::class)->run($foreign, function () use ($foreign) {
            $branch = Branch::create(['name' => 'Foreign branch']);

            $businessUnit = \App\Models\BusinessUnit::create([
                'branch_id' => $branch->id,
                'name' => 'Foreign Operations',
                'code' => 'FOREIGN-'.$branch->id,
                'active' => true,
            ]);

            $foreign->branches()->syncWithoutDetaching([
                $branch->id => [
                    'company_id' => $foreign->company_id,
                ],
            ]);

            $foreign->businessUnits()->syncWithoutDetaching([
                $businessUnit->id => [
                    'company_id' => $foreign->company_id,
                ],
            ]);

            return StockLocation::create([
                'branch_id' => $branch->id,
                'business_unit_id' => $businessUnit->id,
                'name' => 'Foreign counter',
                'type' => 'COUNTER',
                'active' => true,
            ]);
        });
        $this->actingAs($this->terminal);
        $this->getJson('/api/nightshift/my-orders/staff?stock_location_id='.$vip->id)->assertForbidden();
        $this->getJson('/api/nightshift/my-orders/staff?stock_location_id='.$foreignCounter->id)->assertNotFound();
        foreach ([$otherCashier, $foreign] as $user) {
            $view = $this->open();
            $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $user->id, 'pin' => '1113'], ['X-My-Orders-View' => $view])->assertNotFound();
        }
        $grant = $this->verify($this->cashier, '4567');
        $this->getJson('/api/nightshift/my-orders/'.$order->id, ['X-My-Orders-Token' => $grant['token']])->assertNotFound();
    }

    public function test_wrong_selected_waiter_and_cashier_pins_fail_without_order_stock_payment_or_ledger_effects(): void
    {
        $before = $this->effects();
        foreach ([[$this->asha, '6789'], [$this->james, '0123'], [$this->cashier, '9876']] as [$staff, $pin]) {
            $view = $this->open();
            $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $staff->id, 'pin' => $pin], ['X-My-Orders-View' => $view])->assertUnprocessable()->assertJsonValidationErrors('pin');
            $this->getJson('/api/nightshift/my-orders')->assertForbidden();
            $this->assertSame(1, $staff->fresh()->pin_failed_attempts);
        }
        $this->assertSame($before, $this->effects());
        $this->assertSame(3, AuditLog::where('action', 'MY_ORDERS_PIN_FAILED')->count());
        $this->assertAuthenticatedAs($this->terminal);
    }

    public function test_lockout_and_attempt_counter_are_preserved(): void
    {
        $view = $this->open();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $this->asha->id, 'pin' => '9999'], ['X-My-Orders-View' => $view])->assertUnprocessable();
        }
        $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $this->asha->id, 'pin' => '0123'], ['X-My-Orders-View' => $view])->assertStatus(429);
        $this->assertSame(5, $this->asha->fresh()->pin_failed_attempts);
        $this->assertNotNull($this->asha->fresh()->pin_locked_until);
    }

    public function test_close_refresh_expiry_and_logout_clear_authorization_without_switching_browser_identity(): void
    {
        $grant = $this->verify($this->asha, '0123');
        $headers = ['X-My-Orders-Token' => $grant['token']];
        $this->postJson('/api/nightshift/my-orders/close', [], ['X-My-Orders-View' => $grant['view']])->assertOk()->assertSessionMissing(MyOrdersService::SESSION_KEY);
        $this->getJson('/api/nightshift/my-orders', $headers)->assertForbidden();
        $grant = $this->verify($this->asha, '0123');
        $this->get('/dashboard')->assertOk()->assertSessionMissing(MyOrdersService::SESSION_KEY);
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertForbidden();
        $grant = $this->verify($this->asha, '0123');
        $this->travel(301)->seconds();
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertForbidden()->assertSessionMissing(MyOrdersService::SESSION_KEY);
        $this->assertAuthenticatedAs($this->terminal);
        $grant = $this->verify($this->cashier, '4567');
        $this->post('/logout')->assertRedirect('/')->assertSessionMissing(MyOrdersService::SESSION_KEY);
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertUnauthorized();
    }

    public function test_tokens_are_bound_to_session_actor_and_view_and_cannot_be_replayed_after_switching_staff(): void
    {
        $first = $this->verify($this->asha, '0123');
        $second = $this->verify($this->james, '6789');
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $first['token']])->assertForbidden();
        $this->postJson('/api/nightshift/my-orders/close', [], ['X-My-Orders-View' => $first['view']])->assertOk();
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $second['token']])->assertOk();
        $this->withSession([MyOrdersService::SESSION_KEY => null])->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $second['token']])->assertForbidden();
        $grant = $this->verify($this->asha, '0123');
        $this->actingAs($this->cashier);
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertForbidden();
    }

    public function test_grant_does_not_extend_on_read_and_is_revoked_by_pin_reset_inactivity_or_shift_close(): void
    {
        $grant = $this->verify($this->asha, '0123');
        $expires = session(MyOrdersService::SESSION_KEY)['expires_at'];
        $this->travel(60)->seconds();
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertOk();
        $this->assertSame($expires, session(MyOrdersService::SESSION_KEY)['expires_at']);
        $this->asha->forceFill(['operational_pin' => Hash::make('0123')])->save();
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertForbidden();
        $grant = $this->verify($this->asha, '0123');
        Shift::where('user_id', $this->asha->id)->update(['status' => 'CLOSED']);
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertNotFound();
        $grant = $this->verify($this->cashier, '4567');
        $this->cashier->forceFill(['active' => false])->save();
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertNotFound();
    }

    public function test_individual_waiter_and_cashier_can_only_select_and_verify_themselves(): void
    {
        foreach ([[$this->asha, '0123'], [$this->cashier, '4567']] as [$staff, $pin]) {
            $this->actingAs($staff);
            $rows = $this->getJson('/api/nightshift/my-orders/staff?stock_location_id='.$this->counter->id)->assertOk()->json('staff');
            $this->assertSame([$staff->id], array_column($rows, 'id'));
            $view = $this->open();
            $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $this->james->id, 'pin' => '6789'], ['X-My-Orders-View' => $view])->assertNotFound();
            $grant = $this->verify($staff, $pin);
            $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $grant['token']])->assertOk();
            $this->assertAuthenticatedAs($staff);
        }
    }

    public function test_security_audit_and_session_do_not_contain_plaintext_pin_or_bearer_token(): void
    {
        $grant = $this->verify($this->asha, '0123');
        $state = session(MyOrdersService::SESSION_KEY);
        $this->assertArrayNotHasKey('pin', $state);
        $this->assertNotContains($grant['token'], $state);
        $this->assertNotContains($grant['view'], $state);
        $audit = AuditLog::where('action', 'MY_ORDERS_VERIFIED')->firstOrFail();
        $this->assertSame($this->terminal->id, $audit->user_id);
        $this->assertSame($this->asha->id, $audit->new_values['selected_staff_id']);
        $this->assertSame($this->terminal->id, $audit->new_values['terminal_user_id']);
        $this->assertSame($this->counter->branch_id, $audit->branch_id);
        $this->assertNotNull($audit->created_at);
        $this->assertArrayNotHasKey('pin', $audit->new_values);
        $this->assertStringNotContainsString($grant['token'], $audit->toJson());
    }

    public function test_unverified_or_forged_proofs_cannot_read_or_verify(): void
    {
        $this->getJson('/api/nightshift/my-orders')->assertForbidden();
        $this->getJson('/api/nightshift/my-orders/'.$this->unpaid->id)->assertForbidden();
        $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $this->asha->id, 'pin' => '0123'])->assertForbidden();
        $view = $this->open();
        $this->postJson('/api/nightshift/my-orders/verify', ['staff_id' => $this->asha->id, 'pin' => '0123'], ['X-My-Orders-View' => 'forged'])->assertForbidden();
        $this->getJson('/api/nightshift/my-orders', ['X-My-Orders-Token' => $view])->assertForbidden();
    }
}
