<?php

namespace Tests\Feature;

use App\Domain\Decimal as D;
use App\Domain\OrderCollectionMode;
use App\Domain\OrderSource;
use App\Http\Middleware\RequireOperationalShift;
use App\Models\BusinessUnit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\ProductUnit;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\TicketCollection;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\ReportService;
use App\Services\ShiftService;
use App\Services\ShiftStockService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CounterSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Shift $shift;

    private ProductUnit $bottle;

    private ProductUnit $crate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->shift = Shift::where('user_id', $this->cashier->id)->where('type', 'CASHIER')->where('status', 'OPEN')->firstOrFail();
        $this->bottle = $this->sellingUnit('Piece');
        $this->crate = $this->sellingUnit('Crate');
    }

    private function sellingUnit(string $name): ProductUnit
    {
        return ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', $name))->firstOrFail();
    }

    private function allowCashier(): void
    {
        $this->cashier->role->permissions()->syncWithoutDetaching(Permission::where('name', 'counter-sales')->firstOrFail());
        $this->actingAs($this->cashier);
    }

    private function command(string $command, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/nightshift/commands/'.$command, [...$data, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function sale(?ProductUnit $unit = null, string $quantity = '1', ?array $lines = null): array
    {
        $unit ??= $this->bottle;

        return [
            'cashier_shift_id' => $this->shift->id, 'stock_location_id' => $this->shift->stock_location_id,
            'business_unit_id' => $this->shift->business_unit_id, 'branch_id' => $this->shift->branch_id,
            'items' => [['product_unit_id' => $unit->id, 'quantity' => $quantity]],
            'lines' => $lines ?? [['method' => 'Cash', 'amount' => D::mul($quantity, $unit->selling_price)]],
        ];
    }

    private function stock(): string
    {
        return Stock::where('stock_location_id', $this->shift->stock_location_id)->where('product_id', $this->bottle->product_id)->firstOrFail()->quantity;
    }

    public function test_checkout_is_atomic_idempotent_and_scoped_to_own_shift_in_a_paid_ticket_unit(): void
    {
        BusinessUnit::findOrFail($this->shift->business_unit_id)->update(['order_collection_mode' => OrderCollectionMode::PaidTicket]);
        $this->actingAs($this->cashier);
        $this->command('counter-sale', $this->sale())->assertForbidden();
        $this->allowCashier();
        $this->getJson('/api/nightshift/bootstrap?pos_context=1')->assertOk()->assertJsonPath('counter_sale_shift.id', $this->shift->id);
        $beforeStock = $this->stock();
        $expected = app(ShiftService::class)->expected($this->shift);
        $report = app(ShiftStockService::class)->report($this->shift->id);
        $sold = collect($report['rows'])->firstWhere('product_id', $this->bottle->product_id)['system_sold'];
        $key = (string) Str::uuid();
        $amount = $this->crate->selling_price;
        $data = $this->sale($this->crate, lines: [['method' => 'Cash', 'amount' => '10000'], ['method' => 'M-Pesa', 'amount' => D::sub($amount, '10000')]]);
        $sale = $this->command('counter-sale', $data, $key)->assertOk()->assertJsonPath('status', 'PAID')->assertJsonPath('order_source', 'CASHIER_DIRECT');
        $id = $sale->json('id');
        $this->command('counter-sale', $data, $key)->assertOk()->assertJsonPath('id', $id);
        $this->command('counter-sale', [...$data, 'order_note' => 'changed'], $key)->assertUnprocessable();
        $order = Order::findOrFail($id);
        $this->assertNull($order->waiter_id);
        $this->assertSame($this->cashier->id, $order->issued_by);
        $this->assertSame($this->cashier->id, $order->created_by);
        $this->assertSame($this->shift->id, $order->issued_shift_id);
        $this->assertSame($this->shift->business_unit_id, $order->business_unit_id);
        $this->assertSame($this->shift->stock_location_id, $order->stock_location_id);
        $this->assertSame('0.00', $order->balance);
        $this->assertSame($amount, $order->amount_paid);
        $this->assertSame(0, WaiterLedgerEntry::where('order_id', $id)->count());
        $this->assertSame(0, TicketCollection::where('order_id', $id)->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'Order')->where('reference_id', $id)->where('movement_type', 'ISSUE')->count());
        $this->assertSame(2, Payment::where('order_id', $id)->where('shift_id', $this->shift->id)->count());
        $this->assertSame(D::sub($beforeStock, '24', 6), $this->stock());
        $this->assertSame(D::add($expected, '10000'), app(ShiftService::class)->expected($this->shift));
        $report = app(ShiftStockService::class)->report($this->shift->id);
        $this->assertSame(D::add($sold, '24', 6), collect($report['rows'])->firstWhere('product_id', $this->bottle->product_id)['system_sold']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'COUNTER_SALE_PAID', 'reference_id' => (string) $id, 'user_id' => $this->cashier->id]);

        $sale->assertJsonPath('copies', ['CUSTOMER RECEIPT']);
        $this->get($sale->json('print_url'))->assertOk()->assertSee('Counter Sale')->assertSee('M-Pesa')->assertSee($this->cashier->name)->assertDontSee('WAITER COPY');
        $this->getJson('/api/nightshift/bootstrap?pos_context=1')->assertOk()->assertJsonFragment(['order_source' => 'CASHIER_DIRECT']);
        $this->actingAs($this->owner);
        app(\App\Services\SalesTicketService::class)->savePreferences(['paper_width' => '58mm', 'auto_print' => true, 'waiter_copy' => true, 'cashier_copy' => true, 'number_of_copies' => 2]);
        $reprint = $this->command('ticket-reprint', ['id' => $id])->assertOk()->assertJsonPath('copies', ['CUSTOMER RECEIPT']);
        $this->get($reprint->json('print_url'))->assertOk()->assertSee('58mm')->assertSee('REPRINT')->assertSee($order->invoice_number)->assertDontSee('WAITER COPY');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('An order source cannot be changed');
        DB::table('orders')->where('id', $id)->update(['order_source' => OrderSource::Waiter->value]);
    }

    public function test_invoice_reports_distinguish_sources_and_non_cash_does_not_add_physical_cash(): void
    {
        $this->allowCashier();
        $before = app(ShiftService::class)->expected($this->shift);
        $sale = $this->sale(lines: [['method' => 'M-Pesa', 'amount' => $this->bottle->selling_price]]);
        $id = $this->command('counter-sale', $sale)->assertOk()->json('id');
        $this->assertSame($before, app(ShiftService::class)->expected($this->shift));
        $this->actingAs($this->owner);
        $invoice = Order::findOrFail($id)->invoice_number;
        $waiter = Order::where('order_source', OrderSource::Waiter->value)->whereNotNull('issued_at')->firstOrFail()->invoice_number;
        $url = '/api/nightshift/reports/Daily%20Sales';
        $rows = $this->getJson($url)->assertOk()->json('rows');
        $this->assertContains($invoice, array_column($rows, 0));
        $this->assertContains($waiter, array_column($rows, 0));
        $this->getJson($url.'?order_source=CASHIER_DIRECT')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.0', $invoice)->assertJsonPath('rows.0.7', 'Counter Sale');
        $this->assertNotContains($invoice, array_column($this->getJson($url.'?order_source=WAITER')->assertOk()->json('rows'), 0));
        $this->assertContains('M-Pesa', array_column(app(ReportService::class)->generate('Payment Method Report')['rows'], 0));
    }

    public function test_invalid_tender_and_stock_roll_back_without_orders_payments_or_stock_changes(): void
    {
        $this->allowCashier();
        $before = $this->stock();
        $this->command('counter-sale', $this->sale(lines: [['method' => 'Cash', 'amount' => '1']]))->assertUnprocessable();
        $this->command('counter-sale', $this->sale($this->crate, '999'))->assertUnprocessable();
        $this->command('counter-sale', $this->sale(lines: [['method' => 'Credit', 'amount' => $this->bottle->selling_price]]))->assertJsonValidationErrors('lines.0.method');
        $this->assertSame(0, Order::where('order_source', OrderSource::CashierDirect->value)->count());
        $this->assertSame($before, $this->stock());
    }

    public function test_checkout_rejects_foreign_scope_inactive_staff_and_missing_shift(): void
    {
        $foreign = User::factory()->create();
        $this->allowCashier();
        $request = $this->sale();
        $this->command('counter-sale', [...$request, 'company_id' => $foreign->company_id])->assertJsonValidationErrors('company_id');
        $this->command('counter-sale', [...$request, 'business_unit_id' => $this->shift->business_unit_id + 1])->assertForbidden();
        $this->command('counter-sale', [...$request, 'branch_id' => $this->shift->branch_id + 1])->assertForbidden();
        $this->command('counter-sale', [...$request, 'stock_location_id' => $this->shift->stock_location_id + 1])->assertForbidden();
        $this->actingAs($foreign)->command('counter-sale', $request)->assertUnprocessable();
        $this->actingAs($this->owner)->command('counter-sale', $request)->assertUnprocessable();
        $this->actingAs($this->cashier);
        $this->withoutMiddleware(RequireOperationalShift::class);
        $location = StockLocation::findOrFail($this->shift->stock_location_id);
        $location->update(['active' => false]);
        $this->command('counter-sale', $request)->assertUnprocessable();
        $location->update(['active' => true]);
        $unit = BusinessUnit::findOrFail($this->shift->business_unit_id);
        $unit->update(['active' => false]);
        $this->command('counter-sale', $request)->assertUnprocessable();
        $unit->update(['active' => true]);
        $this->withMiddleware(RequireOperationalShift::class);
        $this->cashier->forceFill(['active' => false])->save();
        $this->command('counter-sale', $request)->assertForbidden();
        $this->cashier->forceFill(['active' => true])->save();
        $this->shift->update(['status' => 'CLOSED']);
        $this->command('counter-sale', $request)->assertStatus(409);
        $this->withoutMiddleware(RequireOperationalShift::class)->command('counter-sale', $request)->assertUnprocessable();
    }

    public function test_partial_return_and_approved_void_refund_the_remaining_tender_once(): void
    {
        $this->allowCashier();
        $stock = $this->stock();
        $cash = app(ShiftService::class)->expected($this->shift);
        $sales = app(ShiftStockService::class)->report($this->shift->id)['total_sales'];
        $id = $this->command('counter-sale', $this->sale(quantity: '2'))->assertOk()->json('id');
        $order = Order::findOrFail($id);
        $item = $order->items()->firstOrFail();
        $this->command('return', ['id' => $id, 'reason' => 'Customer returned unopened bottle', 'items' => [['order_item_id' => $item->id, 'quantity' => '1']]])->assertOk();
        $this->assertSame(D::sub($stock, '1', 6), $this->stock());
        $this->assertSame($this->bottle->selling_price, $order->fresh()->amount_paid);
        $this->assertSame(D::add($cash, $this->bottle->selling_price), app(ShiftService::class)->expected($this->shift));
        $this->assertSame(D::add($sales, $this->bottle->selling_price), app(ShiftStockService::class)->report($this->shift->id)['total_sales']);
        $void = $this->command('void-request', ['id' => $id, 'reason' => 'Remaining bottle returned unopened'])->assertOk()->json('id');
        $this->actingAs($this->owner);
        $this->command('void-decide', ['id' => $void, 'approved' => true, 'reason' => 'Confirmed remaining goods recovered'])->assertOk();
        $this->assertSame('VOIDED', $order->fresh()->status);
        $this->assertSame($stock, $this->stock());
        $this->assertSame($cash, app(ShiftService::class)->expected($this->shift));
        $this->assertSame(2, Payment::where('order_id', $id)->where('shift_id', $this->shift->id)->where('amount', '<', 0)->count());
        $this->assertSame(0, WaiterLedgerEntry::where('order_id', $id)->count());
    }

    public function test_full_return_restores_stock_and_refunds_original_non_cash_channel(): void
    {
        $this->allowCashier();
        $stock = $this->stock();
        $id = $this->command('counter-sale', $this->sale(lines: [['method' => 'Card', 'amount' => $this->bottle->selling_price]]))->assertOk()->json('id');
        $order = Order::findOrFail($id);
        $this->command('return', ['id' => $id, 'reason' => 'Customer returned the entire purchase', 'items' => [['order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => '1']]])->assertOk();
        $this->assertSame('RETURNED', $order->fresh()->status);
        $this->assertSame($stock, $this->stock());
        $this->assertDatabaseHas('payments', ['order_id' => $id, 'shift_id' => $this->shift->id, 'method' => 'Card', 'amount' => '-'.$this->bottle->selling_price]);
    }
}
