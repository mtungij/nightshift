<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\OrderService;
use App\Services\StaffPinService;
use App\Services\WaiterLedgerService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WaiterPinWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $waiter;

    private User $cashier;

    private StockLocation $counter;

    private ProductUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->counter = StockLocation::where('name', 'Main Counter')->firstOrFail();
        $this->unit = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', 'Piece'))->firstOrFail();
        app(StaffPinService::class)->set($this->waiter->id, '0123');
        app(StaffPinService::class)->set($this->cashier->id, '4567');
        $this->waiter = $this->waiter->fresh();
        $this->cashier = $this->cashier->fresh();
    }

    private function payload(): array
    {
        return ['waiter_id' => $this->waiter->id, 'pin' => '0123', 'stock_location_id' => $this->counter->id, 'discount' => '0', 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '1']]];
    }

    private function command(string $name, array $payload, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/nightshift/commands/'.$name, [...$payload, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function quantity(): string
    {
        return (string) Stock::where('stock_location_id', $this->counter->id)->where('product_id', $this->unit->product_id)->value('quantity');
    }

    public function test_pin_is_hashed_hidden_and_never_audited_or_returned(): void
    {
        $this->command('staff-pin', ['id' => $this->waiter->id, 'pin' => '9876'])->assertOk()->assertJsonMissingPath('operational_pin')->assertJsonMissingPath('pin');
        $hash = $this->waiter->fresh()->operational_pin;
        $this->assertTrue(Hash::check('9876', $hash));
        $this->assertNotSame('9876', $hash);
        $this->assertArrayNotHasKey('operational_pin', $this->waiter->fresh()->toArray());
        $audit = AuditLog::where('action', 'STAFF_PIN_RESET')->get()->toJson();
        $this->assertStringNotContainsString('9876', $audit);
        $this->assertStringNotContainsString($hash, $audit);
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertDontSee($hash)->assertDontSee('9876');
        $this->assertStringNotContainsString('9876', DB::table('idempotency_keys')->get()->toJson());
    }

    public function test_pin_requires_exactly_four_digits_and_admin_can_remove_it(): void
    {
        foreach (['123', '12345', 'abcd', '12.3', 1234] as $pin) {
            $this->command('staff-pin', ['id' => $this->waiter->id, 'pin' => $pin])->assertUnprocessable()->assertJsonValidationErrors('pin');
        }
        $this->command('staff-pin', ['id' => $this->waiter->id, 'pin' => null])->assertOk();
        $this->assertNull($this->waiter->fresh()->operational_pin);
        $this->command('order-send', $this->payload())->assertUnprocessable();
    }

    public function test_only_company_admin_can_manage_operational_staff_pins(): void
    {
        foreach ([$this->waiter, $this->cashier, User::where('email', 'manager@nightshift.test')->firstOrFail()] as $actor) {
            $this->actingAs($actor);
            $this->command('staff-pin', ['id' => $this->waiter->id, 'pin' => '9876'])->assertForbidden();
        }
        $this->actingAs($this->owner);
        $this->command('staff-pin', ['id' => $this->owner->id, 'pin' => '9876'])->assertOk();
        $this->assertTrue(Hash::check('9876', $this->owner->fresh()->operational_pin));
    }

    public function test_missing_and_invalid_pin_cannot_send_an_order(): void
    {
        $before = Order::count();
        $this->command('order-send', [...$this->payload(), 'pin' => ''])->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->command('order-send', [...$this->payload(), 'pin' => '9999'])->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->assertSame($before, Order::count());
        $this->assertSame(1, $this->waiter->fresh()->pin_failed_attempts);
    }

    public function test_five_failed_attempts_lock_the_pin_and_reset_or_expiry_unlocks_it(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->command('order-send', [...$this->payload(), 'pin' => '9999'])->assertUnprocessable();
        }
        $this->assertNotNull($this->waiter->fresh()->pin_locked_until);
        $this->command('order-send', $this->payload())->assertStatus(429);
        $this->travel(16)->minutes();
        $this->command('order-send', $this->payload())->assertOk();
        $this->assertSame(0, $this->waiter->fresh()->pin_failed_attempts);
        $this->assertNull($this->waiter->fresh()->pin_locked_until);
    }

    public function test_pin_admin_reset_clears_lock(): void
    {
        $this->waiter->forceFill(['pin_locked_until' => now()->addMinutes(15), 'pin_failed_attempts' => 5])->save();
        $this->command('staff-pin', ['id' => $this->waiter->id, 'pin' => '0123'])->assertOk();
        $this->command('order-send', $this->payload())->assertOk();
    }

    public function test_foreign_company_pin_cannot_be_used_or_reset(): void
    {
        $foreign = User::factory()->create(['role_id' => Role::where('name', 'Waiter')->firstOrFail()->id]);
        $foreign->forceFill(['operational_pin' => Hash::make('0123')])->save();
        $this->command('order-send', [...$this->payload(), 'waiter_id' => $foreign->id])->assertNotFound();
        $this->command('staff-pin', ['id' => $foreign->id, 'pin' => '9876'])->assertNotFound();
        $this->assertTrue(Hash::check('0123', $foreign->fresh()->operational_pin));
    }

    public function test_inactive_waiters_are_hidden_and_rejected(): void
    {
        $this->waiter->forceFill(['active' => false])->save();
        $cards = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json('servingWaiters');
        $this->assertNotContains($this->waiter->id, array_column($cards, 'id'));
        $this->command('order-send', $this->payload())->assertForbidden();
    }

    public function test_closed_shift_or_wrong_counter_blocks_send(): void
    {
        $shift = Shift::where('user_id', $this->waiter->id)->where('status', 'OPEN')->firstOrFail();
        $shift->update(['status' => 'CLOSED']);
        $this->command('order-send', $this->payload())->assertUnprocessable();
        $shift->update(['status' => 'OPEN']);
        $other = StockLocation::where('name', 'VIP Counter')->firstOrFail();
        $this->command('order-send', [...$this->payload(), 'stock_location_id' => $other->id])->assertUnprocessable();
    }

    public function test_send_issues_stock_and_ledger_without_a_cashier_confirmation_or_open_cashier_shift(): void
    {
        Shift::where('type', 'CASHIER')->update(['status' => 'CLOSED']);
        $before = $this->quantity();
        $balance = app(WaiterLedgerService::class)->balance($this->waiter->id);
        $this->actingAs($this->waiter);
        $response = $this->command('order-send', $this->payload())->assertOk()->assertJsonPath('status', 'ISSUED');
        $order = Order::findOrFail($response->json('id'));
        $this->assertSame(0, bccomp(bcsub($before, '1', 6), $this->quantity(), 6));
        $this->assertSame(1, StockMovement::where('reference_type', 'Order')->where('reference_id', $order->id)->where('movement_type', 'ISSUE')->count());
        $this->assertSame(1, WaiterLedgerEntry::where('order_id', $order->id)->where('entry_type', 'ISSUE')->count());
        $this->assertSame(bcadd($balance, $order->total, 2), app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertSame($this->waiter->id, $order->waiter_id);
        $this->assertSame($order->shift_id, $order->issued_shift_id);
        $this->assertSame($this->counter->id, $order->stock_location_id);
        $this->assertNotNull($order->issued_at);
    }

    public function test_same_send_key_cannot_double_deduct_even_after_a_retry(): void
    {
        $key = (string) Str::uuid();
        $before = $this->quantity();
        $first = $this->command('order-send', $this->payload(), $key)->assertOk();
        $second = $this->command('order-send', $this->payload(), $key)->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(0, bccomp(bcsub($before, '1', 6), $this->quantity(), 6));
        $this->assertSame(1, WaiterLedgerEntry::where('order_id', $first->json('id'))->where('entry_type', 'ISSUE')->count());
        $this->command('order-send', [...$this->payload(), 'discount' => '1'], $key)->assertUnprocessable();
    }

    public function test_insufficient_stock_rolls_back_order_movements_and_waiter_ledger(): void
    {
        $counts = [Order::count(), StockMovement::count(), WaiterLedgerEntry::count()];
        $before = $this->quantity();
        $payload = $this->payload();
        $payload['items'][0]['quantity'] = '999999';
        $this->command('order-send', $payload)->assertUnprocessable();
        $this->assertSame($counts, [Order::count(), StockMovement::count(), WaiterLedgerEntry::count()]);
        $this->assertSame($before, $this->quantity());
    }

    public function test_draft_is_issued_directly_with_its_assigned_waiter_pin(): void
    {
        $data = $this->payload();
        unset($data['pin']);
        $draft = app(OrderService::class)->create([...$data, 'status' => 'DRAFT']);
        $this->command('order-send', ['id' => $draft->id, 'waiter_id' => $this->waiter->id, 'pin' => '0123'])->assertOk()->assertJsonPath('status', 'ISSUED');
        $this->assertSame('ISSUED', $draft->fresh()->status);
    }

    public function test_partial_account_payment_then_full_settlement_reconcile_all_invoices_and_shift(): void
    {
        $this->command('order-send', $this->payload())->assertOk();
        $this->command('order-send', $this->payload())->assertOk();
        $balance = app(WaiterLedgerService::class)->balance($this->waiter->id);
        $this->actingAs($this->cashier);
        $payment = ['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'method' => 'Cash', 'amount' => '5000'];
        $key = (string) Str::uuid();
        $partial = $this->command('waiter-payment', $payment, $key)->assertOk();
        $this->command('waiter-payment', $payment, $key)->assertOk()->assertExactJson($partial->json());
        $this->assertSame(bcsub($balance, '5000', 2), app(WaiterLedgerService::class)->balance($this->waiter->id));
        $remaining = app(WaiterLedgerService::class)->balance($this->waiter->id);
        $this->command('waiter-payment', [...$payment, 'amount' => $remaining])->assertOk()->assertJsonPath('outstanding', '0.00');
        $this->assertSame(0, Order::where('waiter_id', $this->waiter->id)->whereNotNull('issued_at')->where('balance', '>', 0)->count());
        $this->assertSame(0, bccomp((string) WaiterLedgerEntry::where('waiter_id', $this->waiter->id)->sum('debit'), (string) WaiterLedgerEntry::where('waiter_id', $this->waiter->id)->sum('credit'), 2));
        foreach (Order::where('waiter_id', $this->waiter->id)->whereNotNull('issued_at')->get() as $order) {
            $this->assertSame(0, bccomp($order->amount_paid, (string) PaymentAllocation::where('order_id', $order->id)->sum('amount'), 2));
        }
        // The demo already contained payments: reconcile against total issued value.
        $this->assertSame('0.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->actingAs($this->waiter);
        $shift = Shift::where('user_id', $this->waiter->id)->where('status', 'OPEN')->firstOrFail();
        foreach (Order::where('waiter_id', $this->waiter->id)->where('status', 'WAITING_FOR_ISSUE')->get() as $pending) {
            $this->command('order-cancel', ['id' => $pending->id])->assertOk();
        }
        $this->command('shift-close', ['id' => $shift->id])->assertOk()->assertJsonPath('status', 'CLOSED');
    }

    public function test_waiter_cannot_close_with_debt_and_unauthorized_account_payment_is_blocked(): void
    {
        $this->actingAs($this->waiter);
        $shift = Shift::where('user_id', $this->waiter->id)->where('status', 'OPEN')->firstOrFail();
        $this->command('shift-close', ['id' => $shift->id])->assertForbidden();
        $this->command('waiter-payment', ['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'method' => 'Cash', 'amount' => '1'])->assertForbidden();
        $this->assertSame('OPEN', $shift->fresh()->status);
    }

    public function test_overpayment_and_foreign_account_payment_are_rejected_atomically(): void
    {
        $payment = ['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'method' => 'Cash', 'amount' => '999999'];
        $before = PaymentAllocation::count();
        $this->command('waiter-payment', $payment)->assertUnprocessable();
        $foreign = User::factory()->create();
        $this->command('waiter-payment', [...$payment, 'waiter_id' => $foreign->id])->assertNotFound();
        $this->assertSame($before, PaymentAllocation::count());
    }

    public function test_ticket_uses_committed_order_and_rejects_unissued_orders(): void
    {
        $data = $this->payload();
        unset($data['pin']);
        $queued = app(OrderService::class)->create($data);
        $this->get('/api/nightshift/issue-tickets/'.$queued->id)->assertStatus(409);
        $response = $this->command('order-send', $this->payload())->assertOk();
        $order = Order::findOrFail($response->json('id'));
        $this->get($response->json('ticket_url'))->assertOk()->assertSee($order->invoice_number)->assertSee($this->waiter->name)->assertSee($this->counter->name)->assertSee('UNPAID')->assertSee('80mm')->assertSee('data-committed-order="'.$order->id.'"', false)->assertSee($order->total)->assertSee($order->items->first()->product_name);
    }

    public function test_quick_lock_blocks_workspace_and_api_until_own_pin_unlock_and_does_not_flash_pin(): void
    {
        $this->actingAs($this->cashier);
        $this->post('/quick-lock')->assertRedirect('/quick-lock');
        $this->get('/dashboard')->assertRedirect('/quick-lock');
        $this->getJson('/api/nightshift/bootstrap')->assertStatus(423);
        $this->post('/quick-lock/unlock', ['pin' => '0123'])->assertSessionHasErrors('pin')->assertSessionMissing('_old_input.pin');
        $this->post('/quick-lock/unlock', ['pin' => '4567'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $this->get('/quick-lock')->assertOk()->assertSee('Quick Lock');
    }

    public function test_server_inactivity_lock_preserves_authenticated_session_and_guests_remain_blocked(): void
    {
        $this->actingAs($this->cashier)->withSession(['staff_last_activity' => time() - 901]);
        $this->getJson('/api/nightshift/bootstrap')->assertStatus(423);
        $this->assertAuthenticatedAs($this->cashier);
        $this->assertSame(960, config('session.lifetime'));
        $this->post('/logout')->assertRedirect('/');
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
