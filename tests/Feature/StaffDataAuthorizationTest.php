<?php

namespace Tests\Feature;

use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessNotification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\ShiftService;
use App\Services\WaiterLedgerService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffDataAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $a;

    private User $b;

    private User $cashier;

    private StockLocation $main;

    private StockLocation $vip;

    private ProductUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->a = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $this->b = User::where('email', 'james@nightshift.test')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->main = StockLocation::where('name', 'Main Counter')->firstOrFail();
        $this->vip = StockLocation::where('name', 'VIP Counter')->firstOrFail();
        $this->unit = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->firstOrFail();
    }

    private function staff(string $role, int $branch): User
    {
        $user = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'operational_pin' => Hash::make('0123')]);
        $user->branches()->attach($branch, ['company_id' => $user->company_id]);

        return $user;
    }

    private function command(string $name, array $data)
    {
        return $this->postJson('/api/nightshift/commands/'.$name, ['idempotency_key' => (string) Str::uuid(), ...$data]);
    }

    public function test_waiter_snapshot_contains_only_own_orders_payments_ledger_shifts_and_pos_identity(): void
    {
        $this->actingAs($this->a);
        $data = $this->getJson('/api/nightshift/bootstrap?all_branches=1')->assertOk()->json();
        $this->assertSame([$this->a->id], array_column($data['waiters'], 'id'));
        $this->assertSame([$this->a->id], array_column($data['servingWaiters'], 'id'));
        $this->assertNotEmpty($data['orders']);
        foreach ($data['orders'] as $order) {
            $this->assertSame($this->a->id, $order['waiter']);
        }
        foreach ($data['payments'] as $payment) {
            $this->assertSame($this->a->id, $payment['waiter']);
        }
        foreach ($data['shifts'] as $shift) {
            $this->assertSame($this->a->id, $shift['user_id']);
            $this->assertArrayNotHasKey('actual_cash', $shift);
        }
        $ownShift = Shift::where('user_id', $this->a->id)->where('status', 'OPEN')->firstOrFail();
        $this->assertSame($ownShift->id, $data['shift']['id']);
        $this->assertSame('WAITER', $data['shift']['type']);
        $this->assertArrayNotHasKey('opening', $data['shift']);
        $ownOrders = Order::where('waiter_id', $this->a->id)->pluck('id')->all();
        foreach ($data['waiters'][0]['ledger'] as $entry) {
            $this->assertContains($entry['reference_id'], $entry['reference_type'] === 'Order' ? $ownOrders : Payment::whereIn('order_id', $ownOrders)->pluck('id')->all());
        }
        $other = Order::where('waiter_id', $this->b->id)->firstOrFail();
        $this->get('/api/nightshift/receipts/'.$other->id)->assertForbidden();
        $this->get('/api/nightshift/issue-tickets/'.$other->id)->assertForbidden();
        $this->getJson('/api/nightshift/bootstrap?waiter_id='.$this->b->id)->assertForbidden();
        $this->command('order-send', ['waiter_id' => $this->b->id, 'pin' => '0123', 'stock_location_id' => $this->main->id, 'discount' => '0', 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '1']]])->assertForbidden();
    }

    public function test_waiter_report_permission_does_not_grant_other_staff_or_cashier_reconciliation(): void
    {
        $this->a->role->permissions()->attach(Permission::where('name', 'view-reports')->firstOrFail()->id);
        $this->actingAs($this->a);
        $this->getJson('/api/nightshift/reports/Cashier%20Shift')->assertForbidden();
        $this->get('/api/nightshift/reports/Cashier%20Shift/export')->assertForbidden();
        $this->getJson('/api/nightshift/reports/Waiter%20Outstanding?waiter_id='.$this->b->id)->assertForbidden();
        $this->getJson('/api/nightshift/reports/Waiter%20Outstanding')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.0', $this->a->name);
        $report = $this->getJson('/api/nightshift/reports/Shift%20Report')->assertOk();
        $this->assertNotContains('Actual cash', $report->json('headers'));
        $this->assertFalse(Gate::allows('view', Shift::where('user_id', $this->cashier->id)->firstOrFail()));
    }

    public function test_cashier_sees_only_own_reconciliation_and_payment_activity_and_minimal_balances(): void
    {
        $otherCashier = $this->staff('Cashier', $this->main->branch_id);
        $otherShift = app(ShiftService::class)->open(['user_id' => $otherCashier->id, 'stock_location_id' => $this->main->id, 'type' => 'CASHIER', 'opening_cash' => '98765']);
        $this->actingAs($this->cashier);
        $data = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertSame([$this->cashier->id], array_values(array_unique(array_column($data['shifts'], 'user_id'))));
        $this->assertSame([], $data['payments']); // Demo receipts were received by Owner.
        $this->assertSame('0.00', $data['metrics']['collected']);
        $this->assertSame('0.00', $data['metrics']['cash_difference']);
        $this->assertSame(2, $data['metrics']['active_waiters']);
        $this->assertSame([$this->main->id], array_column($data['locations'], 'id'));
        $this->assertTrue($data['visibility']['settlement_only']);
        foreach ($data['waiters'] as $waiter) {
            foreach (['ledger', 'shift_id', 'issued', 'paid', 'returns', 'adjustments'] as $field) {
                $this->assertArrayNotHasKey($field, $waiter);
            }
        }
        $this->assertFalse(Gate::allows('view', $otherShift));
        $this->command('shift-close', ['id' => $otherShift->id, 'actual_cash' => '98765'])->assertForbidden();
        $this->cashier->role->permissions()->attach(Permission::where('name', 'view-reports')->firstOrFail()->id);
        $this->getJson('/api/nightshift/reports/Cashier%20Shift?issued_by='.$otherCashier->id)->assertForbidden();
        $report = $this->getJson('/api/nightshift/reports/Cashier%20Shift')->assertOk()->json();
        $this->assertCount(1, $report['rows']);
        $this->assertSame($this->cashier->name, $report['rows'][0][1]);
    }

    public function test_cashier_cannot_settle_or_read_another_counter_but_allowed_settlement_remains_atomic(): void
    {
        $vipWaiter = $this->staff('Waiter', $this->vip->branch_id);
        app(ShiftService::class)->open(['user_id' => $vipWaiter->id, 'stock_location_id' => $this->vip->id, 'type' => 'WAITER', 'opening_cash' => '0']);
        $order = app(OrderService::class)->send(['waiter_id' => $vipWaiter->id, 'pin' => '0123', 'stock_location_id' => $this->vip->id, 'discount' => '0', 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '1']]], (string) Str::uuid());
        $this->actingAs($this->cashier);
        $data = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertNotContains($vipWaiter->id, array_column($data['waiters'], 'id'));
        $this->assertNotContains($vipWaiter->id, array_column($data['servingWaiters'], 'id'));
        $this->getJson('/api/nightshift/bootstrap?stock_location_id='.$this->vip->id)->assertForbidden();
        $this->get('/api/nightshift/receipts/'.$order['id'])->assertForbidden();
        $before = Payment::count();
        $this->command('waiter-payment', ['waiter_id' => $vipWaiter->id, 'stock_location_id' => $this->vip->id, 'amount' => '1', 'method' => 'Cash'])->assertForbidden();
        $this->command('payment', ['id' => $order['id'], 'lines' => [['method' => 'Cash', 'amount' => '1']]])->assertForbidden();
        $this->assertSame($before, Payment::count());
        $this->command('waiter-payment', ['waiter_id' => $this->a->id, 'stock_location_id' => $this->main->id, 'amount' => '1', 'method' => 'Cash'])->assertOk();
        $data = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertCount(1, $data['payments']);
        $this->assertSame('1.00', $data['metrics']['collected']);
    }

    public function test_manager_supervisor_are_branch_scoped_and_owner_is_company_wide(): void
    {
        $branch = Branch::create(['name' => 'Private second branch']);
        $counter = StockLocation::create(['branch_id' => $branch->id, 'name' => 'Private counter', 'type' => 'COUNTER']);
        $private = $this->staff('Cashier', $branch->id);
        $privateShift = app(ShiftService::class)->open(['user_id' => $private->id, 'stock_location_id' => $counter->id, 'type' => 'CASHIER', 'opening_cash' => '456789']);
        $manager = User::where('email', 'manager@nightshift.test')->firstOrFail();
        $supervisor = $this->staff('Supervisor', $this->main->branch_id);
        foreach ([$manager, $supervisor] as $actor) {
            $this->actingAs($actor);
            $data = $this->getJson('/api/nightshift/bootstrap?all_branches=1')->assertOk()->json();
            $this->assertNotContains($private->id, array_column($data['shifts'], 'user_id'));
            $this->assertNotContains($private->id, array_column($data['team'], 'id'));
            $this->assertFalse(Gate::allows('view', $privateShift));
            $this->getJson('/api/nightshift/bootstrap?branch_id='.$branch->id)->assertNotFound();
            $this->getJson('/api/nightshift/reports/Cashier%20Shift?branch_id='.$branch->id)->assertNotFound();
        }
        $this->actingAs($this->owner);
        $data = $this->getJson('/api/nightshift/bootstrap?all_branches=1')->assertOk()->json();
        $this->assertContains($private->id, array_column($data['shifts'], 'user_id'));
        $this->assertContains($private->id, array_column($data['team'], 'id'));
        $this->assertTrue(Gate::allows('view', $privateShift));
        // Wider access is explicitly opt-in after the new branch-scoped default.
        $manager->role->permissions()->attach(Permission::where('name', 'all-branches')->firstOrFail()->id);
        $this->actingAs($manager)->getJson('/api/nightshift/bootstrap?branch_id='.$branch->id)->assertOk();
    }

    public function test_company_boundary_applies_even_to_owner_and_explicit_wide_permissions(): void
    {
        $foreign = User::factory()->create();
        [$branch, $shift] = app(TenantContext::class)->run($foreign, function () use ($foreign) {
            $branch = Branch::create(['name' => 'Foreign staff branch']);
            $foreign->branches()->attach($branch->id, ['company_id' => $foreign->company_id]);
            $counter = StockLocation::create(['branch_id' => $branch->id, 'name' => 'Foreign counter', 'type' => 'COUNTER']);
            $shift = app(ShiftService::class)->open(['user_id' => $foreign->id, 'stock_location_id' => $counter->id, 'type' => 'CASHIER', 'opening_cash' => '99999']);

            return [$branch, $shift];
        });
        $this->assertFalse(Gate::allows('view', $shift));
        $data = $this->getJson('/api/nightshift/bootstrap?all_branches=1')->assertOk()->json();
        $this->assertNotContains($foreign->id, array_column($data['shifts'], 'user_id'));
        $this->getJson('/api/nightshift/bootstrap?branch_id='.$branch->id)->assertNotFound();
        $this->command('shift-close', ['id' => $shift->id, 'actual_cash' => '99999'])->assertNotFound();
    }

    public function test_cashier_uses_latest_closed_shift_counter_and_has_no_operations_before_first_shift(): void
    {
        $new = $this->staff('Cashier', $this->main->branch_id);
        $this->actingAs($new);
        $this->getJson('/api/nightshift/bootstrap')->assertStatus(409)->assertJsonMissingPath('orders');
        $this->get('/open-shift')->assertOk()->assertSee($this->vip->name);
        $this->command('order-send', ['waiter_id' => $this->a->id, 'pin' => '0123', 'stock_location_id' => $this->main->id, 'discount' => '0', 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '1']]])->assertStatus(409);
        $this->actingAs($this->cashier);
        $own = Shift::where('user_id', $this->cashier->id)->firstOrFail();
        $this->command('shift-close', ['id' => $own->id, 'actual_cash' => $own->opening_cash])->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertStatus(409);
        $this->assertSame([$this->main->id], app(StaffVisibility::class)->counters());
        $this->get('/open-shift')->assertOk()->assertSee($this->vip->name);
        $this->command('shift-open', ['user_id' => $this->cashier->id, 'stock_location_id' => $this->vip->id, 'type' => 'CASHIER', 'opening_cash' => '0'])->assertOk();
        $data = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertSame([$this->vip->id], array_column($data['locations'], 'id'));
        $this->getJson('/api/nightshift/bootstrap?stock_location_id='.$this->main->id)->assertForbidden();
    }

    public function test_notifications_do_not_disclose_other_waiter_activity_or_other_readers(): void
    {
        $private = BusinessNotification::create(['branch_id' => $this->main->branch_id, 'user_id' => $this->b->id, 'stock_location_id' => $this->vip->id, 'type' => 'NEW_ORDER', 'title' => 'Private activity', 'message' => 'Private waiter order', 'screen' => 'orders', 'read_by' => [$this->owner->id]]);
        $own = BusinessNotification::create(['branch_id' => $this->main->branch_id, 'user_id' => $this->a->id, 'stock_location_id' => $this->main->id, 'type' => 'NEW_ORDER', 'title' => 'Own activity', 'message' => 'Own waiter order', 'screen' => 'orders', 'read_by' => [$this->owner->id]]);
        $this->actingAs($this->a);
        $rows = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json('notifications');
        $this->assertNotContains($private->id, array_column($rows, 'id'));
        $this->assertSame([], collect($rows)->firstWhere('id', $own->id)['read_by']);
        $this->command('notifications-read', [])->assertOk();
        $this->assertNotContains($this->a->id, $private->fresh()->read_by);
        $this->assertContains($this->a->id, $own->fresh()->read_by);
        $this->actingAs($this->cashier);
        $rows = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json('notifications');
        $this->assertNotContains($private->id, array_column($rows, 'id'));
    }

    public function test_branch_manager_reads_scoped_balance_without_changing_lifetime_accounting(): void
    {
        $branch = Branch::create(['name' => 'Other ledger branch']);
        $counter = StockLocation::create(['branch_id' => $branch->id, 'name' => 'Other ledger counter', 'type' => 'COUNTER']);
        $waiter = $this->staff('Waiter', $this->main->branch_id);
        $waiter->branches()->attach($branch->id, ['company_id' => $waiter->company_id]);
        $firstShift = app(ShiftService::class)->open(['user_id' => $waiter->id, 'stock_location_id' => $this->main->id, 'type' => 'WAITER']);
        $send = fn (int $counter) => app(OrderService::class)->send(['waiter_id' => $waiter->id, 'pin' => '0123', 'stock_location_id' => $counter, 'discount' => '0', 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '1']]], (string) Str::uuid());
        $send($this->main->id);
        app(ShiftService::class)->close($firstShift->id, ['override_reason' => 'Approved transfer to second branch']);
        app(GoodsReceiptService::class)->post(['supplier_invoice' => 'SCOPE-BRANCH-OPENING', 'supplier_id' => Supplier::firstOrFail()->id, 'stock_location_id' => $counter->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '5', 'unit_cost' => '2000']]]);
        app(ShiftService::class)->open(['user_id' => $waiter->id, 'stock_location_id' => $counter->id, 'type' => 'WAITER']);
        $otherOrder = $send($counter->id);
        $manager = User::where('email', 'manager@nightshift.test')->firstOrFail();
        $this->actingAs($manager);
        $this->assertSame('3000.00', app(WaiterLedgerService::class)->visibleBalance($waiter->id));
        $this->assertSame('6000.00', app(WaiterLedgerService::class)->balance($waiter->id));
        $data = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertSame('3000.00', collect($data['waiters'])->firstWhere('id', $waiter->id)['outstanding']);
        $this->get('/api/nightshift/receipts/'.$otherOrder['id'])->assertNotFound();
        $this->actingAs($this->owner);
        $this->assertSame('6000.00', app(WaiterLedgerService::class)->visibleBalance($waiter->id));
    }

    public function test_cashier_cannot_settle_a_waiter_no_longer_assigned_to_the_permitted_branch(): void
    {
        $other = Branch::create(['name' => 'Transferred waiter branch']);
        $order = Order::where('waiter_id', $this->a->id)->where('status', 'PARTIALLY_PAID')->firstOrFail();
        $this->a->branches()->sync([$other->id => ['company_id' => $this->a->company_id]]);
        $this->actingAs($this->cashier);
        $data = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertNotContains($this->a->id, array_column($data['waiters'], 'id'));
        $this->assertNotContains($order->id, array_column($data['orders'], 'id'));
        $this->get('/api/nightshift/receipts/'.$order->id)->assertForbidden();
        $this->command('payment', ['id' => $order->id, 'lines' => [['method' => 'Cash', 'amount' => '1']]])->assertForbidden();
        $this->command('waiter-payment', ['waiter_id' => $this->a->id, 'stock_location_id' => $this->main->id, 'amount' => '1', 'method' => 'Cash'])->assertForbidden();
    }
}
