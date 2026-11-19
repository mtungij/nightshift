<?php

namespace Tests\Feature;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReturnService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SalesProfitabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $waiter;

    private User $cashier;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->shift = Shift::where('user_id', $this->cashier->id)->where('status', 'OPEN')->firstOrFail();
        Order::query()->update(['is_demo' => true]);
    }

    public function test_shared_profitability_uses_issue_cost_saved_discount_net_returns_and_base_units(): void
    {
        $beer = Product::where('sku', 'BR-001')->firstOrFail();
        $other = Product::where('sku', 'SD-001')->firstOrFail();
        $order = $this->order([
            [$beer, 'Crate', '2'], [$beer, 'Bucket', '1'], [$beer, 'Piece', '1'], [$other, 'Bottle', '3'],
        ], '100.01');
        $beer->update(['purchase_cost' => '1234.50']);
        app(TenantContext::class)->run($this->cashier, fn () => app(OrderService::class)->issue($order->id));
        $beer->update(['purchase_cost' => '9999.00']);
        $crate = $order->items()->where('unit_name', 'Crate')->firstOrFail();
        app(TenantContext::class)->run($this->cashier, fn () => app(ReturnService::class)->process($order->id, [
            'reason' => 'One unopened crate returned',
            'items' => [['order_item_id' => $crate->id, 'quantity' => '1']],
        ]));
        $order->refresh();
        $this->waiter->update(['active' => false, 'archived_at' => now()]);

        $daily = $this->report()->assertOk()->assertJsonCount(4, 'rows')->json();
        $this->assertEquals(array_fill(0, 4, $order->invoice_number), array_column($daily['rows'], 11));
        $this->assertSame([$beer->name, '1', 'Crate'], array_slice($daily['rows'][0], 0, 3));
        $this->assertSame('29628.00', $daily['rows'][0][5]);
        $this->assertSame('7407.00', $daily['rows'][1][5]);
        $this->assertSame('1234.50', $daily['rows'][2][5]);
        $this->assertSame('Waiter Sale', $daily['rows'][0][10]);
        $this->assertSame($this->cashier->name, $daily['rows'][0][9]);
        $this->assertSame($order->total, $daily['summary']['Sales']);
        $this->assertSame($order->discount, $order->items->reduce(fn ($sum, $line) => D::add($sum, $line->discount_share), '0.00'));
        $this->assertSame('41269.50', $daily['summary']['Cost']);
        $profit = D::sub($order->total, '41269.50');
        $this->assertSame($profit, $daily['summary']['Gross Profit']);
        $this->assertSame(number_format((float) $profit / (float) $order->total * 100, 2, '.', ''), $daily['summary']['Margin %']);

        $this->report('Sales by Product', ['product_id' => $beer->id, 'category_id' => $beer->category_id, 'issued_by' => $this->cashier->id, 'order_source' => 'WAITER'])
            ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.0', $beer->name)
            ->assertJsonPath('rows.0.2', '1 Bucket · 1 Crate · 1 Piece')->assertJsonPath('rows.0.4', '38269.50');
        $this->report('Sales by Product', ['product_id' => $beer->id, 'category_id' => $other->category_id])->assertJsonCount(0, 'rows');
        $this->report(filters: ['category_id' => $other->category_id])->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.0', $other->name);
        $this->report(filters: ['issued_by' => $this->owner->id])->assertJsonCount(0, 'rows');
        $this->report(filters: ['order_source' => 'CASHIER_DIRECT'])->assertJsonCount(0, 'rows');
        $this->report(filters: ['filter_options' => 1])->assertJsonCount(2, 'filter_options.product')
            ->assertJsonPath('filter_options.cashier.0.id', $this->cashier->id);

        $this->getJson('/api/nightshift/reports/sales-invoice/'.$order->id.'?product_id='.$beer->id)
            ->assertOk()->assertJsonPath('details.Waiter', $this->waiter->name)->assertJsonCount(4, 'rows')
            ->assertJsonPath('rows.0.5', '29628.00');
        $this->getJson('/api/nightshift/reports/sales-invoice/'.$order->id.'?order_source=CASHIER_DIRECT')->assertNotFound();

        $otherUnit = BusinessUnit::whereKeyNot($order->business_unit_id)->firstOrFail();
        $this->report(filters: ['business_unit_id' => $otherUnit->id])->assertJsonCount(0, 'rows');
        $this->getJson('/api/nightshift/reports/sales-invoice/'.$order->id.'?business_unit_id='.$otherUnit->id)->assertNotFound();
        $otherBranch = Branch::create(['name' => 'Sales reporting branch']);
        $this->report(filters: ['branch_id' => $otherBranch->id])->assertJsonCount(0, 'rows');
        $foreign = User::factory()->create();
        $this->actingAs($foreign);
        $this->report(filters: ['company_id' => $this->owner->company_id])->assertForbidden();
        $this->getJson('/api/nightshift/reports/sales-invoice/'.$order->id)->assertNotFound();
    }

    public function test_missing_cost_is_explicit_and_non_sales_and_demo_orders_are_excluded(): void
    {
        $product = Product::where('sku', 'BR-001')->firstOrFail();
        $zero = $this->report()->assertOk()->assertJsonCount(0, 'rows');
        $zero->assertJsonPath('summary.Sales', '0.00')->assertJsonPath('summary.Margin %', '0.00');
        $legacy = $this->order([[$product, 'Piece', '1']]);
        DB::table('order_items')->where('order_id', $legacy->id)->update(['purchase_cost' => 0]);
        $legacy->update(['status' => 'ISSUED', 'issued_at' => now(), 'issued_by' => $this->cashier->id]);
        $snapshot = $this->order([[$product, 'Piece', '1']]);
        $snapshot->update(['status' => 'ISSUED', 'issued_at' => now(), 'issued_by' => $this->cashier->id]);
        foreach (['VOIDED', 'CANCELLED', 'DRAFT', 'WAITING_FOR_ISSUE'] as $status) {
            $this->order([[$product, 'Piece', '1']])->update(['status' => $status, 'issued_at' => now()]);
        }
        $product->update(['purchase_cost' => '900']);
        $this->report()->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.5', '2000.00')
            ->assertJsonPath('rows.1.5', null)->assertJsonPath('rows.1.6', null)->assertJsonPath('summary.Sales', '6000.00')
            ->assertJsonPath('summary.Cost', null)->assertJsonPath('summary.Gross Profit', null)->assertJsonPath('summary.Margin %', null);
        $this->report('Sales by Product')->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.3', '6000.00')->assertJsonPath('rows.0.4', null);
        $csv = $this->get('/api/nightshift/reports/Daily%20Sales/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Unavailable,Unavailable,Unavailable', $csv);
        $metrics = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json('metrics');
        $this->assertSame('6000.00', $metrics['sales']);
    }

    public function test_closed_shift_preserves_profit_before_a_later_return(): void
    {
        $product = Product::where('sku', 'BR-001')->firstOrFail();
        $order = $this->order([[$product, 'Piece', '1']]);
        app(TenantContext::class)->run($this->cashier, fn () => app(OrderService::class)->issue($order->id));
        $cutoff = now()->addSecond();
        $this->travel(2)->minutes();
        $line = $order->items()->firstOrFail();
        app(TenantContext::class)->run($this->cashier, fn () => app(ReturnService::class)->process($order->id, [
            'reason' => 'Returned during a later period',
            'items' => [['order_item_id' => $line->id, 'quantity' => '1']],
        ]));
        $this->shift->update(['status' => 'CLOSED', 'closed_at' => $cutoff]);

        $this->report(filters: ['shift_id' => $this->shift->id])->assertOk()
            ->assertJsonPath('summary.Sales', '3000.00')->assertJsonPath('summary.Cost', '2000.00')
            ->assertJsonPath('summary.Gross Profit', '1000.00');
        $this->report('Sales by Product')->assertOk()->assertJsonPath('summary.Sales', '0.00')
            ->assertJsonPath('summary.Cost', '0.00')->assertJsonPath('summary.Margin %', '0.00');
    }

    private function order(array $lines, string $discount = '0'): Order
    {
        $items = array_map(fn ($line) => [
            'product_unit_id' => ProductUnit::where('product_id', $line[0]->id)->whereHas('unit', fn ($q) => $q->where('name', $line[1]))->firstOrFail()->id,
            'quantity' => $line[2],
        ], $lines);

        return app(TenantContext::class)->run($this->waiter, fn () => app(OrderService::class)->create([
            'waiter_id' => $this->waiter->id, 'stock_location_id' => $this->shift->stock_location_id,
            'discount' => $discount, 'items' => $items,
        ]));
    }

    private function report(string $name = 'Daily Sales', array $filters = [])
    {
        return $this->getJson('/api/nightshift/reports/'.rawurlencode($name).'?'.http_build_query($filters));
    }
}
