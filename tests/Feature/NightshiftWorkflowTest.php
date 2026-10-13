<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\VoidRequest;
use App\Models\WaiterLedgerEntry;
use App\Services\AdjustmentService;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Services\StockTransferService;
use App\Services\VoidService;
use App\Services\WaiterLedgerService;
use App\Support\StockMovementPresenter;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class NightshiftWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private User $waiter;

    private Branch $branch;

    private StockLocation $counter;

    private StockLocation $store;

    private StockLocation $vip;

    private Product $product;

    private ProductUnit $piece;

    private ProductUnit $bucket;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner);
        $this->branch = Branch::create(['name' => 'Masaki']);
        $this->store = StockLocation::create(['branch_id' => $this->branch->id, 'name' => 'Main Store', 'type' => 'STORE']);
        $this->counter = StockLocation::create(['branch_id' => $this->branch->id, 'name' => 'Main Counter', 'type' => 'COUNTER']);
        $this->vip = StockLocation::create(['branch_id' => $this->branch->id, 'name' => 'VIP Counter', 'type' => 'COUNTER']);
        $category = Category::create(['name' => 'Beer']);
        $bottle = Unit::create(['name' => 'Bottle']);
        $piece = Unit::create(['name' => 'Piece']);
        $bucket = Unit::create(['name' => 'Bucket']);
        $this->product = Product::create(['category_id' => $category->id, 'base_unit_id' => $bottle->id, 'name' => 'Serengeti Lite', 'sku' => 'BR-001', 'purchase_cost' => '2000', 'minimum_stock' => '48']);
        $this->piece = ProductUnit::create(['product_id' => $this->product->id, 'unit_id' => $piece->id, 'conversion_quantity' => '1', 'selling_price' => '3000']);
        $this->bucket = ProductUnit::create(['product_id' => $this->product->id, 'unit_id' => $bucket->id, 'conversion_quantity' => '6', 'selling_price' => '16000']);
        $this->supplier = Supplier::create(['name' => 'Serengeti Breweries']);
        $this->cashier = $this->staff('Cashier');
        $this->waiter = $this->staff('Waiter');
        app(ShiftService::class)->open(['user_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'type' => 'WAITER', 'opening_cash' => '0']);
        app(ShiftService::class)->open(['user_id' => $this->cashier->id, 'stock_location_id' => $this->counter->id, 'type' => 'CASHIER', 'opening_cash' => '300000']);
        app(ShiftService::class)->open(['user_id' => $this->owner->id, 'stock_location_id' => $this->counter->id, 'type' => 'CASHIER', 'opening_cash' => '0']);
        foreach ([[$this->counter, '142'], [$this->store, '480'], [$this->vip, '96']] as [$location,$qty]) {
            app(GoodsReceiptService::class)->post(['stock_location_id' => $location->id, 'supplier_id' => $this->supplier->id, 'supplier_invoice' => 'START-'.$location->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => $qty, 'unit_cost' => '2000']]]);
        }
    }

    private function staff(string $role): User
    {
        $u = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id]);
        $u->branches()->attach($this->branch->id, ['company_id' => $this->owner->company_id]);

        return $u;
    }

    private function order(string $quantity = '2', ?ProductUnit $unit = null): Order
    {
        $this->actingAs($this->waiter);

        return app(OrderService::class)->create(['stock_location_id' => $this->counter->id, 'waiter_id' => $this->waiter->id, 'items' => [['product_unit_id' => ($unit ?? $this->piece)->id, 'quantity' => $quantity]]]);
    }

    private function issued(string $qty = '2', ?ProductUnit $unit = null): Order
    {
        $o = $this->order($qty, $unit);
        $this->actingAs($this->cashier);

        return app(OrderService::class)->issue($o->id);
    }

    private function stock(StockLocation $l): string
    {
        return (string) Stock::where('stock_location_id', $l->id)->where('product_id', $this->product->id)->value('quantity');
    }

    private function assertDecimal(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp($expected, (string) $actual, 6), "Expected {$expected}, got {$actual}");
    }

    private function command(string $name, array $data, ?string $key = null)
    {
        return $this->postJson('/api/nightshift/commands/'.$name, ['idempotency_key' => $key ?? (string) Str::uuid(), ...$data]);
    }

    public function test_guest_cannot_read_or_mutate_workspace(): void
    {
        auth()->logout();
        $this->getJson('/api/nightshift/bootstrap')->assertUnauthorized();
        $this->command('order-issue', ['id' => 1])->assertUnauthorized();
    }

    public function test_waiter_creates_order_without_stock_deduction(): void
    {
        $this->actingAs($this->waiter);
        $this->command('order-create', ['stock_location_id' => $this->counter->id, 'waiter_id' => $this->waiter->id, 'discount' => '0', 'status' => 'WAITING_FOR_ISSUE', 'items' => [['product_unit_id' => $this->bucket->id, 'quantity' => '2']]])->assertOk();
        $this->assertDecimal('142', $this->stock($this->counter));
        $o = Order::firstOrFail();
        $this->assertDecimal('32000', $o->total);
        $this->assertDecimal('12', $o->items->first()->base_quantity);
        $this->assertSame(0, WaiterLedgerEntry::count());
    }

    public function test_cashier_issues_once_and_posts_ledger(): void
    {
        $o = $this->issued();
        $this->command('order-issue', ['id' => $o->id])->assertOk();
        $this->assertDecimal('140', $this->stock($this->counter));
        $this->assertDecimal('6000.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertSame(1, WaiterLedgerEntry::where('entry_type', 'ISSUE')->count());
        $this->assertNotNull($o->issued_by);
        $this->assertNotNull($o->issued_shift_id);
        $this->assertSame(1, StockMovement::where('movement_type', 'ISSUE')->count());
    }

    public function test_waiter_cannot_issue_drinks(): void
    {
        $o = $this->order();
        $this->command('order-issue', ['id' => $o->id])->assertForbidden();
        $this->assertDecimal('142', $this->stock($this->counter));
    }

    public function test_insufficient_stock_rolls_back_all_effects(): void
    {
        $o = $this->order('143');
        $this->actingAs($this->cashier);
        $this->command('order-issue', ['id' => $o->id])->assertUnprocessable();
        $this->assertDecimal('142', $this->stock($this->counter));
        $this->assertSame('WAITING_FOR_ISSUE', $o->fresh()->status);
        $this->assertSame(0, WaiterLedgerEntry::count());
    }

    public function test_multi_item_issue_is_atomic(): void
    {
        $this->actingAs($this->waiter);
        $o = app(OrderService::class)->create(['stock_location_id' => $this->counter->id, 'waiter_id' => $this->waiter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1'], ['product_unit_id' => $this->piece->id, 'quantity' => '142']]]);
        $this->actingAs($this->cashier);
        $this->command('order-issue', ['id' => $o->id])->assertUnprocessable();
        $this->assertDecimal('142', $this->stock($this->counter));
        $this->assertSame(0, StockMovement::where('movement_type', 'ISSUE')->count());
    }

    public function test_partial_payment_reduces_liability(): void
    {
        $o = $this->issued();
        $this->command('payment', ['id' => $o->id, 'lines' => [['method' => 'Cash', 'amount' => '2000']]])->assertOk();
        $this->assertSame('PARTIALLY_PAID', $o->fresh()->status);
        $this->assertDecimal('4000.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
    }

    public function test_split_payment_closes_invoice(): void
    {
        $o = $this->issued();
        $this->command('payment', ['id' => $o->id, 'lines' => [['method' => 'Cash', 'amount' => '2000'], ['method' => 'M-Pesa', 'amount' => '4000']]])->assertOk();
        $this->assertSame('PAID', $o->fresh()->status);
        $this->assertDecimal('0.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertNotNull($o->fresh()->closed_at);
    }

    public function test_credit_does_not_falsely_settle_waiter_debt(): void
    {
        $o = $this->issued();
        $this->command('payment', ['id' => $o->id, 'lines' => [['method' => 'Credit', 'amount' => '6000']]])->assertOk();
        $this->assertDecimal('6000.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertSame('ISSUED', $o->fresh()->status);
    }

    public function test_payment_idempotency_and_payload_mismatch(): void
    {
        $o = $this->issued();
        $key = (string) Str::uuid();
        $body = ['id' => $o->id, 'lines' => [['method' => 'Cash', 'amount' => '2000']]];
        $this->command('payment', $body, $key)->assertOk();
        $this->command('payment', $body, $key)->assertOk();
        $this->assertSame(1, Payment::count());
        $body['lines'][0]['amount'] = '3000';
        $this->command('payment', $body, $key)->assertUnprocessable();
        $this->assertDecimal('4000', $o->fresh()->balance);
    }

    public function test_overpayment_is_rejected_without_ledger_effect(): void
    {
        $o = $this->issued();
        $this->command('payment', ['id' => $o->id, 'lines' => [['method' => 'Cash', 'amount' => '6001']]])->assertUnprocessable();
        $this->assertDecimal('6000.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertSame(0, Payment::count());
    }

    public function test_waiter_shift_closure_is_blocked_by_debt(): void
    {
        $this->issued();
        $this->actingAs($this->waiter);
        $s = Shift::where('user_id', $this->waiter->id)->firstOrFail();
        $this->command('shift-close', ['id' => $s->id])->assertForbidden();
        $this->assertSame('OPEN', $s->fresh()->status);
    }

    public function test_manager_override_requires_reason_and_is_audited(): void
    {
        $this->issued();
        $this->actingAs($this->owner);
        $s = Shift::where('user_id', $this->waiter->id)->firstOrFail();
        $this->command('shift-close', ['id' => $s->id])->assertUnprocessable();
        $this->command('shift-close', ['id' => $s->id, 'override_reason' => 'Approved pending bank reconciliation'])->assertOk();
        $this->assertSame('CLOSED', $s->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'SHIFT_OVERRIDE')->exists());
        $this->assertDecimal('6000.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
    }

    public function test_void_requires_approval_and_keeps_original_history(): void
    {
        $o = $this->issued();
        $this->command('void-request', ['id' => $o->id, 'reason' => 'Guest changed their order'])->assertOk();
        $v = VoidRequest::firstOrFail();
        $this->assertDecimal('140', $this->stock($this->counter));
        $this->command('void-decide', ['id' => $v->id, 'approved' => true, 'reason' => 'Goods recovered and checked'])->assertForbidden();
        $this->actingAs($this->owner);
        $this->command('void-decide', ['id' => $v->id, 'approved' => true, 'reason' => 'Goods recovered and checked'])->assertOk();
        $this->assertDecimal('142', $this->stock($this->counter));
        $this->assertDecimal('0.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertSame('VOIDED', $o->fresh()->status);
        $this->assertSame(2, WaiterLedgerEntry::count());
        $this->assertTrue(StockMovement::where('movement_type', 'VOID_REVERSAL')->exists());
    }

    public function test_paid_void_refunds_cash_without_deleting_payment(): void
    {
        $o = $this->issued();
        app(PaymentService::class)->receive($o->id, [['method' => 'Cash', 'amount' => '6000']]);
        $v = app(VoidService::class)->request($o->id, 'Unused drinks returned by guest');
        $this->actingAs($this->owner);
        app(VoidService::class)->decide($v->id, true, 'All drinks recovered and cash refunded');
        $this->assertSame(2, Payment::count());
        $this->assertDecimal('0', Payment::sum('amount'));
        $this->assertDecimal('0.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
    }

    public function test_void_rejection_restores_previous_status(): void
    {
        $o = $this->issued();
        $v = app(VoidService::class)->request($o->id, 'Guest requested cancellation');
        $this->actingAs($this->owner);
        app(VoidService::class)->decide($v->id, false, 'Goods already consumed by guests');
        $this->assertSame('ISSUED', $o->fresh()->status);
        $this->assertDecimal('140', $this->stock($this->counter));
    }

    public function test_grn_increases_stock_and_preserves_cost(): void
    {
        $this->actingAs($this->owner);
        $this->command('grn', ['stock_location_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'supplier_invoice' => 'SBL-TEST', 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $this->bucket->id, 'quantity' => '2', 'unit_cost' => '12000']]])->assertOk();
        $this->assertDecimal('492', $this->stock($this->store));
        $this->assertDecimal('24000', GoodsReceipt::where('supplier_invoice', 'SBL-TEST')->value('total_cost'));
    }

    public function test_transfer_stays_in_transit_until_received_and_cannot_post_twice(): void
    {
        $this->actingAs($this->owner);
        $t = app(StockTransferService::class)->create(['source_location_id' => $this->store->id, 'destination_location_id' => $this->vip->id, 'items' => [['product_unit_id' => $this->bucket->id, 'quantity' => '2']]]);
        app(StockTransferService::class)->send($t->id);
        app(StockTransferService::class)->send($t->id);
        $this->assertDecimal('468', $this->stock($this->store));
        $this->assertDecimal('96', $this->stock($this->vip));
        $this->assertSame('IN_TRANSIT', $t->fresh()->status);
        app(StockTransferService::class)->receive($t->id);
        app(StockTransferService::class)->receive($t->id);
        $this->assertDecimal('108', $this->stock($this->vip));
    }

    public function test_breakage_changes_stock_only_on_approval(): void
    {
        $this->actingAs($this->owner);
        $a = app(AdjustmentService::class)->request(['stock_location_id' => $this->counter->id, 'reason' => 'BREAKAGE', 'notes' => 'Bottles broken during service', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '2']]]);
        $this->assertDecimal('142', $this->stock($this->counter));
        app(AdjustmentService::class)->decide($a->id, true, 'Verified against breakage report');
        app(AdjustmentService::class)->decide($a->id, true, 'Verified against breakage report');
        $this->assertDecimal('140', $this->stock($this->counter));
    }

    public function test_return_restores_exact_base_quantity_and_liability(): void
    {
        $o = $this->issued('2', $this->bucket);
        $line = $o->items->first();
        $this->command('return', ['id' => $o->id, 'reason' => 'Unopened bucket returned by guest', 'items' => [['order_item_id' => $line->id, 'quantity' => '1']]])->assertOk();
        $this->assertDecimal('136', $this->stock($this->counter));
        $this->assertDecimal('16000.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertDecimal('16000', $o->fresh()->total);
    }

    public function test_return_cannot_exceed_unreturned_quantity(): void
    {
        $o = $this->issued();
        $line = $o->items->first();
        $this->command('return', ['id' => $o->id, 'reason' => 'Unused drinks returned by guest', 'items' => [['order_item_id' => $line->id, 'quantity' => '3']]])->assertUnprocessable();
        $this->assertDecimal('140', $this->stock($this->counter));
    }

    public function test_return_apportions_invoice_discount_without_rounding_leak(): void
    {
        $this->actingAs($this->waiter);
        $o = app(OrderService::class)->create(['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'discount' => '1000', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '3']]]);
        $this->actingAs($this->cashier);
        app(OrderService::class)->issue($o->id);
        $line = $o->items->first();
        for ($i = 0; $i < 3; $i++) {
            app(ReturnService::class)->process($o->id, ['reason' => 'Unused drinks returned by guest', 'items' => [['order_item_id' => $line->id, 'quantity' => '1']]]);
        }$this->assertDecimal('0', $o->fresh()->total);
        $this->assertDecimal('0.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
    }

    public function test_cross_company_order_access_is_denied_at_server(): void
    {
        $o = $this->issued();
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->command('order-issue', ['id' => $o->id])->assertNotFound();
        $this->command('payment', ['id' => $o->id, 'lines' => [['method' => 'Cash', 'amount' => '1']]])->assertNotFound();
        $this->get('/api/nightshift/receipts/'.$o->id)->assertNotFound();
    }

    public function test_cross_company_product_id_is_denied(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->command('adjustment-request', ['stock_location_id' => $this->counter->id, 'reason' => 'BREAKAGE', 'notes' => 'Attempted cross tenant adjustment', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]])->assertNotFound();
    }

    public function test_assigned_branch_access_is_enforced(): void
    {
        $this->actingAs($this->owner);
        $other = Branch::create(['name' => 'Mbezi']);
        $this->actingAs($this->cashier);
        $this->getJson('/api/nightshift/bootstrap?branch_id='.$other->id)->assertNotFound();
        $this->getJson('/api/nightshift/reports/Daily%20Sales?branch_id='.$other->id)->assertForbidden();
    }

    public function test_reports_and_dashboard_are_database_backed_and_isolated(): void
    {
        $this->issued('2');
        $this->actingAs($this->owner);
        $r = $this->getJson('/api/nightshift/reports/Daily%20Sales')->assertOk();
        $this->assertCount(1, $r->json('rows'));
        $snapshot = $this->getJson('/api/nightshift/bootstrap')->assertOk();
        $this->assertDecimal('6000.00', $snapshot->json('metrics.sales'));
        $this->assertDecimal('6000.00', $snapshot->json('metrics.outstanding'));
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->getJson('/api/nightshift/reports/Daily%20Sales')->assertJsonCount(0, 'rows');
    }

    public function test_history_cannot_be_mutated(): void
    {
        $this->issued();
        $entry = WaiterLedgerEntry::firstOrFail();
        $this->expectException(\LogicException::class);
        $entry->update(['credit' => '6000']);
    }

    public function test_conflicting_active_shift_is_blocked(): void
    {
        $this->actingAs($this->owner);
        $this->command('shift-open', ['user_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'type' => 'WAITER', 'opening_cash' => '0'])->assertUnprocessable();
    }

    public function test_cash_shortage_requires_reason_and_records_difference(): void
    {
        $this->issued();
        app(PaymentService::class)->receive(Order::firstOrFail()->id, [['method' => 'Cash', 'amount' => '6000']]);
        $s = Shift::where('user_id', $this->cashier->id)->firstOrFail();
        $this->command('shift-close', ['id' => $s->id, 'actual_cash' => '300000'])->assertUnprocessable();
        $this->command('shift-close', ['id' => $s->id, 'actual_cash' => '300000', 'notes' => 'Shortage confirmed during drawer reconciliation'])->assertOk();
        $this->assertDecimal('-6000', $s->fresh()->cash_difference);
    }

    public function test_receipt_is_protected_and_contains_real_invoice(): void
    {
        $o = $this->issued();
        $this->owner->company->update(['trading_name' => 'Garden Lounge', 'receipt_header' => 'Welcome to our bar', 'receipt_footer' => 'See you again soon', 'tin' => 'TIN-123', 'vrn' => 'VRN-456', 'currency' => 'USD']);
        $this->get('/api/nightshift/receipts/'.$o->id)->assertOk()->assertSee($o->invoice_number)->assertSee('80mm')->assertSee('Serengeti Lite')
            ->assertSee('Garden Lounge')->assertSee('Welcome to our bar')->assertSee('See you again soon')->assertSee('TIN-123')->assertSee('VRN-456')->assertSee('Total USD');
    }

    public function test_discount_shares_remain_nonnegative_and_reconcile_across_many_lines(): void
    {
        $this->actingAs($this->owner);
        $this->piece->update(['selling_price' => '0.01']);
        $lines = array_fill(0, 100, ['product_unit_id' => $this->piece->id, 'quantity' => '1']);
        $this->actingAs($this->waiter);
        $o = app(OrderService::class)->create(['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'discount' => '0.51', 'items' => $lines]);
        $this->assertDecimal('0.51', $o->items->sum('discount_share'));
        foreach ($o->items as $line) {
            $this->assertGreaterThanOrEqual(0, bccomp($line->discount_share, '0', 2));
            $this->assertLessThanOrEqual(0, bccomp($line->discount_share, $line->total, 2));
        }
        $this->assertDecimal('0.49', $o->total);
        $this->assertDecimal('142', $this->stock($this->counter));
    }

    public function test_submitted_item_price_and_tenant_cannot_override_server_values(): void
    {
        $this->actingAs($this->waiter);
        $this->command('order-create', ['stock_location_id' => $this->counter->id, 'waiter_id' => $this->waiter->id, 'discount' => '0', 'status' => 'WAITING_FOR_ISSUE', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '2', 'unit_price' => '1']]])->assertUnprocessable();
        $o = app(OrderService::class)->create(['company_id' => 999, 'waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '2', 'unit_price' => '1']]]);
        $this->assertSame($this->owner->company_id, $o->company_id);
        $this->assertDecimal('6000', $o->total);
    }

    public function test_waiter_cannot_read_or_cancel_another_waiters_invoice(): void
    {
        $o = $this->order();
        $other = $this->staff('Waiter');
        $this->actingAs($this->owner);
        app(ShiftService::class)->open(['user_id' => $other->id, 'stock_location_id' => $this->counter->id, 'type' => 'WAITER']);
        $this->actingAs($other);
        $this->get('/api/nightshift/receipts/'.$o->id)->assertForbidden();
        $this->command('order-cancel', ['id' => $o->id])->assertForbidden();
        $this->assertSame('WAITING_FOR_ISSUE', $o->fresh()->status);
    }

    public function test_waiter_cannot_open_a_cashier_shift(): void
    {
        $this->actingAs($this->owner);
        app(ShiftService::class)->close(Shift::where('user_id', $this->waiter->id)->firstOrFail()->id, []);
        $this->actingAs($this->waiter);
        $this->command('shift-open', ['stock_location_id' => $this->counter->id, 'type' => 'CASHIER', 'opening_cash' => '0'])->assertUnprocessable();
    }

    public function test_stock_reports_use_historical_movements_instead_of_current_stock(): void
    {
        $this->issued();
        $this->actingAs($this->owner);
        $this->getJson('/api/nightshift/reports/Opening%20Stock')->assertOk()->assertJsonCount(0, 'rows');
        $r = $this->getJson('/api/nightshift/reports/Closing%20Stock?stock_location_id='.$this->counter->id)->assertOk();
        $this->assertDecimal('140', $r->json('rows.0.2'));
        $sales = $this->getJson('/api/nightshift/reports/Sales%20by%20Waiter')->assertOk();
        $this->assertSame($this->waiter->name, $sales->json('rows.0.0'));
        $this->assertDecimal('6000', $sales->json('rows.0.1'));
        $cashier = $this->getJson('/api/nightshift/reports/Sales%20by%20Cashier')->assertOk();
        $this->assertSame($this->cashier->name, $cashier->json('rows.0.0'));
    }

    public function test_report_pages_and_csv_export_include_every_matching_invoice(): void
    {
        for ($i = 0; $i < 101; $i++) {
            $this->issued('1');
        }
        $this->actingAs($this->owner);
        $first = $this->getJson('/api/nightshift/reports/Daily%20Sales')->assertOk()->assertJsonCount(100, 'rows')->assertJsonPath('has_more', true);
        $second = $this->getJson('/api/nightshift/reports/Daily%20Sales?page=2')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('has_more', false);
        $this->assertNotContains($second->json('rows.0.0'), array_column($first->json('rows'), 0));
        $csv = $this->get('/api/nightshift/reports/Daily%20Sales/export')->assertOk()->streamedContent();
        $this->assertCount(102, explode("\n", trim($csv)));
        $this->assertStringContainsString($second->json('rows.0.0'), $csv);
    }

    public function test_small_value_repeated_returns_never_create_negative_credit(): void
    {
        $this->actingAs($this->owner);
        $this->piece->update(['selling_price' => '0.01']);
        $this->actingAs($this->waiter);
        $o = app(OrderService::class)->create(['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'discount' => '0.02', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '4']]]);
        $this->actingAs($this->cashier);
        app(OrderService::class)->issue($o->id);
        $line = $o->items->firstOrFail();
        for ($i = 0; $i < 4; $i++) {
            $r = app(ReturnService::class)->process($o->id, ['reason' => 'All unopened drinks recovered', 'items' => [['order_item_id' => $line->id, 'quantity' => '1']]]);
            $this->assertGreaterThanOrEqual(0, bccomp($r->total, '0', 2));
        }
        $this->assertSame('RETURNED', $o->fresh()->status);
        $this->assertDecimal('142', $this->stock($this->counter));
        $this->assertDecimal('0', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertDecimal('0.02', WaiterLedgerEntry::where('entry_type', 'RETURN')->sum('credit'));
    }

    public function test_paid_value_return_requires_refund_and_is_atomic(): void
    {
        $o = $this->issued();
        app(PaymentService::class)->receive($o->id, [['method' => 'Cash', 'amount' => '6000']]);
        $this->command('return', ['id' => $o->id, 'reason' => 'Guest returned unused drinks', 'items' => [['order_item_id' => $o->items->firstOrFail()->id, 'quantity' => '1']]])->assertUnprocessable();
        $this->assertDecimal('140', $this->stock($this->counter));
        $this->assertSame(0, OrderReturn::count());
    }

    public function test_credit_with_external_reference_remains_linked_to_its_invoice(): void
    {
        $o = $this->issued();
        $this->command('payment', ['id' => $o->id, 'lines' => [['method' => 'Credit', 'amount' => '6000', 'reference' => 'Customer credit register 123']]])->assertOk();
        $this->assertSame($o->id, Payment::firstOrFail()->order_id);
        $this->assertSame('Customer credit register 123', Payment::firstOrFail()->external_reference);
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonPath('payments.0.invoice', $o->id);
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_demo_seeder_is_repeatable_and_posts_real_history(): void
    {
        $this->seed(NightshiftDemoSeeder::class);
        $this->seed(NightshiftDemoSeeder::class);
        $this->actingAs(User::where('email', 'admin@nightshift.test')->firstOrFail());
        $this->assertSame(15, Product::count());
        $this->assertSame(60, Stock::count());
        $this->assertSame(3, Order::count());
        $this->assertSame(4, GoodsReceipt::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(3, WaiterLedgerEntry::count());
        $this->assertTrue(StockMovement::where('movement_type', 'ISSUE')->exists());
    }

    public function test_ledger_reconciles_across_partial_payment_return_settlement_and_paid_void(): void
    {
        $order = $this->issued();
        $assertReconciled = function (string $expected) use ($order): void {
            $this->assertDecimal($expected, $order->fresh()->balance);
            $this->assertDecimal($expected, app(WaiterLedgerService::class)->balance($this->waiter->id));
            $this->assertDecimal($expected, bcsub((string) WaiterLedgerEntry::sum('debit'), (string) WaiterLedgerEntry::sum('credit'), 2));
        };
        $assertReconciled('6000');
        $this->command('payment', ['id' => $order->id, 'lines' => [['method' => 'Cash', 'amount' => '2000']]])->assertOk();
        $assertReconciled('4000');
        $this->command('return', ['id' => $order->id, 'reason' => 'One unopened bottle recovered', 'items' => [['order_item_id' => $order->items->firstOrFail()->id, 'quantity' => '1']]])->assertOk();
        $assertReconciled('1000');
        $this->command('payment', ['id' => $order->id, 'lines' => [['method' => 'Cash', 'amount' => '1000']]])->assertOk();
        $assertReconciled('0');
        $this->assertSame('PAID', $order->fresh()->status);
        $this->command('void-request', ['id' => $order->id, 'reason' => 'Remaining bottle returned after settlement'])->assertOk();
        $assertReconciled('0');
        $this->actingAs($this->owner);
        $void = VoidRequest::where('order_id', $order->id)->firstOrFail();
        $this->command('void-decide', ['id' => $void->id, 'approved' => true, 'reason' => 'All remaining goods recovered and both payments refunded'])->assertOk();
        $assertReconciled('0');
        $this->assertSame('VOIDED', $order->fresh()->status);
        $this->assertDecimal('142', $this->stock($this->counter));
        $this->assertSame(4, Payment::count());
        $this->assertDecimal('0', Payment::sum('amount'));
        $this->assertSame(3, WaiterLedgerEntry::where('entry_type', 'REVERSAL')->count());
    }

    public function test_settled_waiter_shift_closes_without_an_override(): void
    {
        $order = $this->issued();
        $this->command('payment', ['id' => $order->id, 'lines' => [['method' => 'Cash', 'amount' => '2000'], ['method' => 'M-Pesa', 'amount' => '4000']]])->assertOk();
        $this->actingAs($this->owner);
        $shift = Shift::where('user_id', $this->waiter->id)->firstOrFail();
        $this->command('shift-close', ['id' => $shift->id])->assertOk();
        $this->assertSame('CLOSED', $shift->fresh()->status);
        $this->assertNotNull($shift->fresh()->closed_at);
        $this->assertNull($shift->fresh()->override_reason);
        $this->assertFalse(AuditLog::where('action', 'SHIFT_OVERRIDE')->exists());
        $this->assertTrue(AuditLog::where('action', 'SHIFT_CLOSED')->where('reference_id', (string) $shift->id)->exists());
        $this->assertDecimal('0', app(WaiterLedgerService::class)->balance($this->waiter->id));
    }

    public function test_workspace_counter_selection_is_deterministic_and_can_issue_at_the_open_shift(): void
    {
        $garden = StockLocation::create(['branch_id' => $this->branch->id, 'name' => 'Garden Counter', 'type' => 'COUNTER']);
        $snapshot = $this->getJson('/api/nightshift/bootstrap')->assertOk();
        $this->assertSame([$this->store->id, $this->counter->id, $this->vip->id, $garden->id], array_column($snapshot->json('locations'), 'id'));
        $snapshot->assertJsonPath('location_id', $this->counter->id);
        $created = $this->command('order-create', ['waiter_id' => $this->waiter->id, 'stock_location_id' => $snapshot->json('location_id'), 'status' => 'WAITING_FOR_ISSUE', 'discount' => '0', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '2']]])->assertOk();
        $this->command('order-issue', ['id' => $created->json('id')])->assertOk();
        $this->assertDecimal('140', $this->stock($this->counter));
        $this->assertDecimal('6000', app(WaiterLedgerService::class)->balance($this->waiter->id));
    }

    public function test_workspace_prefers_the_open_shift_counter_and_preserves_explicit_selection(): void
    {
        $shift = Shift::where('user_id', $this->owner->id)->firstOrFail();
        app(ShiftService::class)->close($shift->id, ['actual_cash' => '0']);
        // With no open shift, the first counter by ID is a stable fallback.
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonPath('location_id', $this->counter->id);
        app(ShiftService::class)->open(['user_id' => $this->owner->id, 'stock_location_id' => $this->vip->id, 'type' => 'CASHIER', 'opening_cash' => '0']);
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonPath('location_id', $this->vip->id);
        $this->getJson('/api/nightshift/bootstrap?stock_location_id='.$this->counter->id)->assertOk()->assertJsonPath('location_id', $this->counter->id);
    }

    public function test_receiving_without_invoice_keeps_numbering_posting_and_duplicate_protection(): void
    {
        $data = ['stock_location_id' => $this->counter->id, 'supplier_id' => $this->supplier->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1.5', 'unit_cost' => '2000']]];
        $first = $this->command('grn', $data)->assertOk();
        $second = $this->command('grn', [...$data, 'supplier_invoice' => ''])->assertOk();
        $a = GoodsReceipt::findOrFail($first->json('id'));
        $b = GoodsReceipt::findOrFail($second->json('id'));
        $this->assertNull($a->supplier_invoice);
        $this->assertNull($b->supplier_invoice);
        $this->assertNotSame($a->number, $b->number);
        $this->assertDecimal('145', $this->stock($this->counter));
        $this->command('grn', [...$data, 'supplier_invoice' => 'UX-INVOICE'])->assertOk();
        $this->command('grn', [...$data, 'supplier_invoice' => 'UX-INVOICE'])->assertUnprocessable();
        $this->assertDecimal('146.5', $this->stock($this->counter));
    }

    /** @return array<string, mixed> */
    private function uxProductData(): array
    {
        return ['name' => 'UX product', 'category_id' => $this->product->category_id, 'base_unit_id' => $this->product->base_unit_id, 'purchase_cost' => '2000', 'minimum_stock' => '4', 'active' => true, 'units' => [['unit_id' => $this->piece->unit_id, 'conversion_quantity' => '1', 'selling_price' => '3000']]];
    }

    public function test_optional_sku_is_unique_and_existing_hidden_product_data_is_preserved(): void
    {
        $before = $this->product->fresh()->getAttributes();
        $a = $this->command('product', $this->uxProductData())->assertOk();
        $b = $this->command('product', [...$this->uxProductData(), 'sku' => ''])->assertOk();
        $first = Product::findOrFail($a->json('id'));
        $second = Product::findOrFail($b->json('id'));
        $this->assertStringStartsWith('NS-', $first->sku);
        $this->assertNotSame($first->sku, $second->sku);
        $this->assertNull($first->image_path);
        $this->assertSame($before, $this->product->fresh()->getAttributes());
        $first->update(['brand' => 'Legacy brand', 'image_url' => 'https://example.com/legacy.png']);
        $this->command('product', [...$this->uxProductData(), 'id' => $first->id])->assertOk();
        $this->assertSame($first->sku, $first->fresh()->sku);
        $this->assertSame('Legacy brand', $first->fresh()->brand);
        $this->assertSame('https://example.com/legacy.png', $first->fresh()->image_url);
        $this->command('product', [...$this->uxProductData(), 'sku' => $second->sku])->assertUnprocessable();
    }

    public function test_product_image_upload_is_stored_previewable_idempotent_and_tenant_scoped(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
        $key = (string) Str::uuid();
        $data = ['payload' => json_encode([...$this->uxProductData(), 'idempotency_key' => $key]), 'image' => UploadedFile::fake()->createWithContent('product.png', $png)];
        $response = $this->post('/api/nightshift/commands/product', $data, ['Accept' => 'application/json'])->assertOk();
        $product = Product::findOrFail($response->json('id'));
        Storage::disk('local')->assertExists($product->image_path);
        $this->assertNull($product->image_url);
        $image = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json('products');
        $url = collect($image)->firstWhere('id', $product->id)['image'];
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/api/nightshift/commands/product', $data, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('id', $product->id);
        $this->assertCount(1, Storage::disk('local')->allFiles('product-images'));
        $this->command('product', [...$this->uxProductData(), 'id' => $product->id])->assertOk();
        $this->assertSame($product->image_path, $product->fresh()->image_path);
        $this->actingAs(User::factory()->create());
        $this->getJson(route('nightshift.product-image', $product->id))->assertNotFound();
    }

    public function test_invalid_and_oversized_product_images_are_rejected_without_creating_products(): void
    {
        $before = Product::count();
        foreach ([UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), UploadedFile::fake()->create('large.jpg', 2049, 'image/jpeg')] as $image) {
            $this->post('/api/nightshift/commands/product', ['payload' => json_encode([...$this->uxProductData(), 'idempotency_key' => (string) Str::uuid()]), 'image' => $image], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertSame($before, Product::count());
    }

    public function test_stock_movement_displays_actual_posting_actors_locations_and_fractional_quantities(): void
    {
        $transfer = app(StockTransferService::class)->create(['source_location_id' => $this->store->id, 'destination_location_id' => $this->counter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1.5']]]);
        app(StockTransferService::class)->send($transfer->id);
        $receiver = $this->staff('Manager');
        $this->actingAs($receiver);
        app(StockTransferService::class)->receive($transfer->id);
        $this->actingAs($this->owner);
        $movements = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json('movements');
        $received = collect($movements)->firstWhere('type', 'TRANSFER_RECEIVED');
        $this->assertSame($receiver->name, $received['user']);
        $this->assertSame($this->store->name, $received['from']);
        $this->assertSame($this->counter->name, $received['to']);
        $this->assertSame('1.5', $received['qty']);
        $grn = collect($movements)->firstWhere('type', 'GRN');
        $this->assertSame($this->owner->name, $grn['user']);
        $this->assertSame($this->supplier->name, $grn['from']);
        $this->assertDoesNotMatchRegularExpression('/\.0+$/', $grn['qty']);
        $historical = StockMovement::where('movement_type', 'GRN')->firstOrFail()->replicate();
        $historical->created_by = 999999;
        $this->assertNull(StockMovementPresenter::present($historical)['user']);
        $order = app(OrderService::class)->create(['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'status' => 'WAITING_FOR_ISSUE', 'discount' => '0', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '0.75']]]);
        $this->actingAs($this->cashier);
        app(OrderService::class)->issue($order->id);
        $this->actingAs($this->owner);
        $issued = collect($this->getJson('/api/nightshift/bootstrap')->assertOk()->json('movements'))->firstWhere('type', 'ISSUE');
        $this->assertSame($this->cashier->name, $issued['user']);
        $this->assertSame('-0.75', $issued['qty']);
        $adjustment = app(AdjustmentService::class)->request(['stock_location_id' => $this->counter->id, 'reason' => 'BREAKAGE', 'notes' => 'Broken during service', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]);
        $this->actingAs($receiver);
        app(AdjustmentService::class)->decide($adjustment->id, true, 'Verified broken bottle');
        $this->actingAs($this->owner);
        $adjusted = collect($this->getJson('/api/nightshift/bootstrap')->assertOk()->json('movements'))->firstWhere('type', 'BREAKAGE');
        $this->assertSame($receiver->name, $adjusted['user']);
        $report = $this->getJson('/api/nightshift/reports/Stock%20Movement')->assertOk();
        $this->assertContains('User', $report->json('headers'));
        $this->assertContains($receiver->name, array_column($report->json('rows'), 8));
    }

    public function test_supplier_categories_keep_legacy_text_and_use_stable_choices(): void
    {
        $this->supplier->update(['category' => 'Legacy specialty supplier']);
        $this->command('catalog-supplier', ['name' => 'UX supplier', 'contact' => '12345', 'category' => 'Beer & Cider'])->assertOk();
        $snapshot = $this->getJson('/api/nightshift/bootstrap')->assertOk();
        $this->assertContains('Beer & Cider', $snapshot->json('supplierCategories'));
        $this->assertContains('Other', $snapshot->json('supplierCategories'));
        $this->assertSame('Legacy specialty supplier', $this->supplier->fresh()->category);
    }

    public function test_product_upload_accepts_all_requested_formats_and_cleans_up_failed_saves(): void
    {
        Storage::fake('local');
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $image = UploadedFile::fake()->image('product.'.$extension, 20, 20);
            $response = $this->post('/api/nightshift/commands/product', ['payload' => json_encode([...$this->uxProductData(), 'idempotency_key' => (string) Str::uuid()]), 'image' => $image], ['Accept' => 'application/json'])->assertOk();
            $product = Product::findOrFail($response->json('id'));
            Storage::disk('local')->assertExists($product->image_path);
            $this->get(route('nightshift.product-image', $product->id))->assertOk();
        }
        $before = Storage::disk('local')->allFiles('product-images');
        $this->post('/api/nightshift/commands/product', ['payload' => json_encode([...$this->uxProductData(), 'sku' => $this->product->sku, 'idempotency_key' => (string) Str::uuid()]), 'image' => UploadedFile::fake()->image('duplicate.png')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame($before, Storage::disk('local')->allFiles('product-images'));
    }
}
