<?php

namespace Tests\Feature;

use App\Domain\Decimal as D;
use App\Domain\OrderCollectionMode;
use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductUnit;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\TicketCollection;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\BusinessUnitService;
use App\Services\ReportService;
use App\Services\ShiftService;
use App\Services\ShiftStockService;
use App\Services\StaffPinService;
use App\Services\StockService;
use App\Services\WaiterLedgerService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CompletesStockCount;
use Tests\TestCase;

class PaidTicketCollectionTest extends TestCase
{
    use CompletesStockCount, RefreshDatabase;

    private User $owner;

    private User $waiter;

    private User $cashier;

    private BusinessUnit $bar;

    private StockLocation $counter;

    private ProductUnit $selling;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->bar = BusinessUnit::where('code', 'BAR')->firstOrFail();
        $this->counter = StockLocation::findOrFail($this->bar->default_stock_location_id);
        $this->selling = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', 'Piece'))->firstOrFail();
        app(StaffPinService::class)->set($this->waiter->id, '0123');
    }

    private function command(string $command, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/nightshift/commands/'.$command, [...$data, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function mode(string $mode): TestResponse
    {
        return $this->postJson('/api/business-units/save', [
            ...$this->bar->only(['id', 'branch_id', 'name', 'code', 'active']),
            'order_collection_mode' => $mode,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function payload(): array
    {
        return ['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'discount' => '0', 'items' => [['product_unit_id' => $this->selling->id, 'quantity' => '1']]];
    }

    private function paidOrder(): Order
    {
        $this->actingAs($this->owner);
        $this->mode('PAID_TICKET')->assertOk();
        $this->actingAs($this->waiter);
        $result = $this->command('order-send', [...$this->payload(), 'pin' => '0123'])->assertOk()->assertJsonPath('status', 'PAID');

        return Order::findOrFail($result->json('id'));
    }

    public function test_paid_order_issues_once_and_excludes_waiter_debt_and_settlement(): void
    {
        $this->assertSame(OrderCollectionMode::WaiterAccount, $this->bar->order_collection_mode);
        $this->mode('INVALID')->assertUnprocessable();
        $this->mode('PAID_TICKET')->assertOk();

        $this->actingAs($this->waiter);
        $ledger = app(WaiterLedgerService::class);
        $before = $ledger->visibleBalance($this->waiter->id);
        $stock = Stock::where('stock_location_id', $this->counter->id)->where('product_id', $this->selling->product_id)->firstOrFail();
        $key = (string) Str::uuid();
        $request = [...$this->payload(), 'pin' => '0123'];
        $id = $this->command('order-send', $request, $key)->assertOk()->assertJsonPath('status', 'PAID')->json('id');
        $this->command('order-send', $request, $key)->assertOk()->assertJsonPath('id', $id);
        $order = Order::findOrFail($id);
        $this->assertSame('0.00', $order->balance);
        $this->assertSame($order->total, $order->amount_paid);
        $this->assertSame($before, $ledger->visibleBalance($this->waiter->id));
        $this->assertSame(D::sub($stock->quantity, '1', 6), $stock->fresh()->quantity);
        $this->assertSame(1, StockMovement::where('reference_type', 'Order')->where('reference_id', $id)->where('movement_type', 'ISSUE')->count());
        $this->assertSame(0, WaiterLedgerEntry::where('order_id', $id)->count());
        $this->assertSame(0, Payment::where('order_id', $id)->count());
        $this->assertSame(1, TicketCollection::where('order_id', $id)->where('status', 'PENDING')->count());

        $this->actingAs($this->cashier);
        $this->command('payment', ['id' => $id, 'lines' => [['method' => 'Cash', 'amount' => $order->total]]])->assertUnprocessable();
        $settlement = $this->command('waiter-payment', ['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'amount' => $before, 'method' => 'Cash'])->assertOk()->json();
        $this->assertNotContains($id, array_column($settlement['allocations'], 'order_id'));

        $this->actingAs($this->waiter);
        foreach (Order::where('waiter_id', $this->waiter->id)->where('status', 'WAITING_FOR_ISSUE')->pluck('id') as $queued) {
            $this->command('order-cancel', ['id' => $queued])->assertOk();
        }
        $this->command('shift-close', ['id' => $order->shift_id])->assertOk()->assertJsonPath('status', 'CLOSED');
    }

    public function test_collection_is_idempotent_and_reconciliation_counts_cash_once(): void
    {
        $order = $this->paidOrder();
        $ticket = TicketCollection::where('order_id', $order->id)->firstOrFail();
        $search = '/ticket-collections?invoice='.$order->invoice_number;
        $collect = '/ticket-collections/'.$ticket->id;
        $this->get($search)->assertForbidden();
        $this->post($collect)->assertForbidden();

        $this->actingAs($this->cashier);
        foreach (Shift::where('stock_location_id', $this->counter->id)->where('type', 'WAITER')->where('status', 'OPEN')->get() as $waiterShift) {
            $balance = app(WaiterLedgerService::class)->visibleBalance($waiterShift->user_id, $this->counter->id);
            $this->command('waiter-payment', ['waiter_id' => $waiterShift->user_id, 'stock_location_id' => $this->counter->id, 'amount' => $balance, 'method' => 'Cash'])->assertOk();
            $this->actingAs($this->owner);
            foreach (Order::where('shift_id', $waiterShift->id)->where('status', 'WAITING_FOR_ISSUE')->pluck('id') as $queued) {
                $this->command('order-cancel', ['id' => $queued])->assertOk();
            }
            $this->command('shift-close', ['id' => $waiterShift->id])->assertOk();
            $this->actingAs($this->cashier);
        }

        $this->actingAs($this->owner);
        $reports = app(ReportService::class);
        $before = $reports->generate('Paid Orders')['rows'];
        $this->assertSame(1, count(array_filter($before, fn ($row) => $row[0] === $order->invoice_number)));
        $cashBefore = array_column($reports->generate('Payment Method Report')['rows'], 1, 0)['Cash'];
        $performanceBefore = $reports->generate('Business Unit Performance', ['business_unit_id' => $this->bar->id])['rows'][0];

        $this->actingAs($this->cashier);
        $this->getJson('/api/workspace-shell?page=ticket-collections')->assertOk();
        $this->get($search)->assertOk()->assertSee($order->invoice_number)->assertSee($this->waiter->name)->assertSee('Mark Collected');
        $shift = Shift::findOrFail($ticket->cashier_shift_id);
        $report = app(ShiftStockService::class)->report($shift->id);
        $this->assertSame(1, $report['cash']['pending_ticket_count']);
        $this->get('/shift-stock/'.$shift->id.'?close=1')->assertOk()->assertSee('Open Ticket Collection')->assertSee($order->invoice_number)->assertSee($order->total);
        $this->assertSame($order->total, $report['cash']['pending_ticket_amount']);
        $this->command('shift-close', ['id' => $shift->id, 'actual_cash' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors('transaction');
        $this->completeStockCount($shift, disposition: false);
        $this->postJson('/api/cash-custody/stage', ['shift_id' => $shift->id, 'destination' => 'TREASURY', 'to_user_id' => $this->owner->id, 'amount' => $report['cash']['expected'], 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('errors.transaction.0', 'Collect pending tickets before closing or handing over cash.');

        $this->post($collect, ['invoice' => $order->invoice_number])->assertRedirect($search);
        $this->post($collect)->assertRedirect('/ticket-collections');
        $this->get($search)->assertOk()->assertSee('COLLECTED')->assertDontSee('Mark Collected');
        $ticket->refresh();
        $this->assertSame($this->cashier->id, $ticket->collected_by);
        $this->assertNotNull($ticket->collected_at);
        $this->assertSame($shift->id, $ticket->cashier_shift_id);
        $this->assertSame('PAID', $order->fresh()->status);
        $updated = app(ShiftStockService::class)->report($shift->id);
        $this->assertTrue($updated['stale']);
        $this->assertSame(0, $updated['cash']['pending_ticket_count']);
        $this->assertSame($order->total, $updated['cash']['collected_ticket_amount']);
        $this->assertSame(D::add($order->total, $report['cash']['expected']), $updated['cash']['expected']);
        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'Order')->where('reference_id', $order->id)->where('movement_type', 'ISSUE')->count());

        $this->completeStockCount($shift);
        $this->command('shift-close', ['id' => $shift->id, 'actual_cash' => $updated['cash']['expected']])->assertOk()->assertJsonPath('status', 'CLOSED');

        $this->actingAs($this->owner);
        $historical = array_column($reports->generate('Paid Orders', ['shift_id' => $shift->id])['rows'], null, 0);
        $this->assertSame($order->total, $historical[$order->invoice_number][4]);
        $this->assertSame('0.00', $historical[$order->invoice_number][5]);
        $this->assertSame($before, $reports->generate('Paid Orders')['rows']);
        $cashAfter = array_column($reports->generate('Payment Method Report')['rows'], 1, 0)['Cash'];
        $this->assertSame(D::add($cashBefore, $order->total), $cashAfter);
        $performanceAfter = $reports->generate('Business Unit Performance', ['business_unit_id' => $this->bar->id])['rows'][0];
        $this->assertSame($performanceBefore[1], $performanceAfter[1]);
        $this->assertSame(D::add($performanceBefore[2], $order->total), $performanceAfter[2]);
        $events = AuditLog::where('reference_type', 'Order')->where('reference_id', (string) $order->id)->whereIn('action', ['PAID_TICKET_ORDER_CREATED', 'PAID_TICKET_COLLECTED'])->orderBy('id')->get();
        $this->assertSame(['PAID_TICKET_ORDER_CREATED', 'PAID_TICKET_COLLECTED'], $events->pluck('action')->all());
        foreach ($events as $event) {
            $this->assertSame($this->bar->id, $event->business_unit_id);
            $this->assertSame($this->bar->id, $event->new_values['business_unit_id']);
            $this->assertSame($order->invoice_number, $event->new_values['invoice']);
            $this->assertSame($this->cashier->id, $event->new_values['cashier_id']);
        }
    }

    public function test_approved_void_cancels_pending_custody_or_refunds_collected_cash(): void
    {
        $pending = $this->paidOrder();
        $collected = $this->paidOrder();
        $this->actingAs($this->cashier);
        $ticket = TicketCollection::where('order_id', $collected->id)->firstOrFail();
        $shift = Shift::findOrFail($ticket->cashier_shift_id);
        $expected = app(ShiftService::class)->expected($shift);
        $this->post('/ticket-collections/'.$ticket->id)->assertRedirect();

        $this->actingAs($this->owner);
        foreach ([$pending, $collected] as $order) {
            $void = $this->command('void-request', ['id' => $order->id, 'reason' => 'Goods recovered for refund'])->assertOk()->json('id');
            $this->command('void-decide', ['id' => $void, 'approved' => true, 'reason' => 'Goods recovered and cash returned'])->assertOk();
            $this->assertSame('VOIDED', $order->fresh()->status);
            $this->assertSame(0, WaiterLedgerEntry::where('order_id', $order->id)->count());
        }
        $this->assertDatabaseHas('ticket_collections', ['order_id' => $pending->id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('ticket_collections', ['order_id' => $collected->id, 'status' => 'COLLECTED']);
        $this->assertDatabaseMissing('payments', ['order_id' => $pending->id]);
        $this->assertSame(D::sub('0', $collected->total), Payment::where('order_id', $collected->id)->sole()->amount);
        $this->assertSame($expected, app(ShiftService::class)->expected($shift));
    }

    public function test_collection_blocks_cross_company_and_unauthorized_units(): void
    {
        $order = $this->paidOrder();
        $ticket = TicketCollection::where('order_id', $order->id)->firstOrFail();
        $this->actingAs($this->owner);
        $restaurant = BusinessUnit::where('code', 'RESTAURANT')->firstOrFail();
        $manager = User::where('email', 'manager@nightshift.test')->firstOrFail();
        app(BusinessUnitService::class)->assign($manager, [$restaurant->id]);

        $company = Company::create(['name' => 'Another Company']);
        $foreignOwner = User::factory()->create(['company_id' => $company->id, 'role_id' => $this->owner->role_id, 'active' => true]);
        foreach ([$manager, $foreignOwner] as $actor) {
            $this->actingAs($actor);
            $this->get('/ticket-collections?invoice='.$order->invoice_number)->assertOk()->assertDontSee($order->waiter->name)->assertSee('No ticket collections found.');
            $this->postJson('/ticket-collections/'.$ticket->id)->assertNotFound();
        }
        $this->assertDatabaseHas('ticket_collections', ['id' => $ticket->id, 'status' => 'PENDING']);
    }

    public function test_mode_is_snapshotted_at_creation_and_units_can_operate_concurrently(): void
    {
        $this->assertDatabaseMissing('orders', ['collection_mode' => 'PAID_TICKET']);
        $this->mode('PAID_TICKET')->assertOk();
        $draft = $this->command('order-create', [...$this->payload(), 'status' => 'DRAFT'])->assertOk()->json('id');
        $barPaid = $this->command('order-create', [...$this->payload(), 'status' => 'WAITING_FOR_ISSUE'])->assertOk()->assertJsonPath('status', 'PAID')->json('id');

        $restaurant = BusinessUnit::where('code', 'RESTAURANT')->firstOrFail();
        $this->assertSame(OrderCollectionMode::WaiterAccount, $restaurant->order_collection_mode);
        $counter = StockLocation::findOrFail($restaurant->default_stock_location_id);
        $restaurantWaiter = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => $this->waiter->role_id, 'active' => true]);
        $restaurantCashier = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => $this->cashier->role_id, 'active' => true]);
        foreach ([[$restaurantCashier, 'CASHIER'], [$restaurantWaiter, 'WAITER']] as [$staff, $type]) {
            $staff->branches()->attach($restaurant->branch_id, ['company_id' => $restaurant->company_id]);
            app(BusinessUnitService::class)->assign($staff, [$restaurant->id]);
            app(ShiftService::class)->open(['user_id' => $staff->id, 'stock_location_id' => $counter->id, 'type' => $type]);
        }
        $food = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'FD-001'))->firstOrFail();
        DB::transaction(fn () => app(StockService::class)->change($counter->id, $food->product_id, '3', 'PURCHASE', 'Test', 1));
        app(StaffPinService::class)->set($restaurantWaiter->id, '0123');
        $this->actingAs($restaurantWaiter);
        $legacyOrder = $this->command('order-send', ['stock_location_id' => $counter->id, 'waiter_id' => $restaurantWaiter->id, 'pin' => '0123', 'discount' => '0', 'items' => [['product_unit_id' => $food->id, 'quantity' => '1']]])->assertOk()->assertJsonPath('status', 'ISSUED')->json('id');
        $this->assertDatabaseHas('waiter_ledger_entries', ['order_id' => $legacyOrder, 'entry_type' => 'ISSUE']);
        $this->assertDatabaseMissing('ticket_collections', ['order_id' => $legacyOrder]);

        $this->actingAs($this->owner);
        $this->mode('WAITER_ACCOUNT')->assertOk();
        $this->command('order-submit', ['id' => $draft])->assertOk()->assertJsonPath('status', 'PAID');
        $this->assertDatabaseHas('orders', ['id' => $barPaid, 'collection_mode' => 'PAID_TICKET', 'status' => 'PAID']);
        $this->assertDatabaseHas('orders', ['id' => $draft, 'collection_mode' => 'PAID_TICKET', 'status' => 'PAID']);
        $this->assertDatabaseHas('orders', ['id' => $legacyOrder, 'collection_mode' => 'WAITER_ACCOUNT', 'status' => 'ISSUED']);

        $this->actingAs($this->waiter);
        $this->command('order-send', [...$this->payload(), 'pin' => '0123'])->assertOk()->assertJsonPath('status', 'ISSUED');
    }
}
