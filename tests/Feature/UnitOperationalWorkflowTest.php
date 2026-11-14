<?php

namespace Tests\Feature;

use App\Domain\BusinessUnitContext;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AdjustmentService;
use App\Services\BusinessUnitService;
use App\Services\GoodsReceiptService;
use App\Services\ShiftService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Services\UnitOperationalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UnitOperationalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_explicit_new_unit_has_idempotent_empty_internal_default(): void
    {
        $branch = Branch::create(['name' => 'Main']);

        $this->assertSame(0, BusinessUnit::where('branch_id', $branch->id)->count());

        $unit = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'BAR',
            'code' => 'BAR',
            'active' => true,
        ]);
        $first = app(UnitOperationalService::class)->ensureDefault($unit);
        $second = app(UnitOperationalService::class)->ensureDefault($unit);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($unit->id, $first->business_unit_id);
        $this->assertSame($branch->id, $first->branch_id);
        $this->assertTrue($first->system_managed);
        $this->assertFalse($unit->advanced_locations_enabled);
        $this->assertSame(1, StockLocation::where('business_unit_id', $unit->id)->count());
        $this->assertSame(0, Stock::count());
    }

    public function test_unit_context_resolves_default_and_rejects_another_units_location(): void
    {
        $branch = Branch::create(['name' => 'Main']);

        $unit = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'Restaurant',
            'code' => 'REST',
            'active' => true,
        ]);

        $other = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'Bar',
            'code' => 'BAR',
            'active' => true,
        ]);

        $resolved = app(UnitOperationalService::class)->resolve([
            'business_unit_id' => $unit->id,
        ]);

        $this->assertSame($unit->default_stock_location_id, $resolved->id);

        $this->postJson('/api/nightshift/commands/shift-open', [
            'business_unit_id' => $unit->id,
            'stock_location_id' => $other->default_stock_location_id,
            'type' => 'CASHIER',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable();
    }

    public function test_supervised_default_adoption_keeps_the_old_location_and_does_not_create_stock(): void
    {
        $branch = Branch::create(['name' => 'Main']);

        $unit = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'Bar',
            'code' => 'BAR',
            'active' => true,
        ]);

        $previous = $unit->default_stock_location_id;
        $legacy = StockLocation::create(['branch_id' => $branch->id, 'business_unit_id' => $unit->id, 'name' => 'Existing bar', 'type' => 'COUNTER']);
        app(UnitOperationalService::class)->adoptDefault($unit, $legacy);
        $this->assertSame($legacy->id, $unit->fresh()->default_stock_location_id);
        $this->assertFalse(StockLocation::findOrFail($previous)->active);
        $this->assertSame(0, Stock::count());
        $this->assertSame($legacy->id, app(UnitOperationalService::class)->ensureDefault($unit->fresh())->id);
    }

    public function test_cashier_capacity_is_unit_wide_and_can_be_increased_to_two(): void
    {
        $branch = Branch::create(['name' => 'Main']);

        $unit = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'Cashier Unit',
            'code' => 'CASHIER-UNIT',
            'active' => true,
        ]);

        $owner = auth()->user();
        $second = User::factory()->create(['company_id' => $owner->company_id]);
        $third = User::factory()->create(['company_id' => $owner->company_id]);
        $service = app(ShiftService::class);
        $first = $service->open(['business_unit_id' => $unit->id, 'type' => 'CASHIER']);
        $this->assertSame($unit->default_stock_location_id, $first->stock_location_id);
        try {
            $service->open(['business_unit_id' => $unit->id, 'user_id' => $second->id, 'type' => 'CASHIER']);
            $this->fail('A second cashier must be blocked at capacity one.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('maximum', $exception->getMessage());
        }
        app(UnitOperationalService::class)->updateLimit($unit, 2);
        $service->open(['business_unit_id' => $unit->id, 'user_id' => $second->id, 'type' => 'CASHIER']);
        $this->assertSame(2, app(UnitOperationalService::class)->activeCashiers($unit->id));
        $this->expectException(ValidationException::class);
        $service->open(['business_unit_id' => $unit->id, 'user_id' => $third->id, 'type' => 'CASHIER']);
    }

    public function test_supervised_cutover_posts_paired_movements_without_duplicating_legacy_stock(): void
    {
        $branch = Branch::create(['name' => 'Main']);

        $legacy = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'Legacy Unit',
            'code' => 'LEGACY',
            'active' => true,
        ]);

        $destination = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'BAR',
            'code' => 'BAR',
            'active' => true,
        ]);
        $category = Category::create(['name' => 'Drinks']);
        $measure = Unit::create(['name' => 'Bottle']);
        $product = Product::create(['name' => 'Water', 'sku' => 'W1', 'category_id' => $category->id, 'base_unit_id' => $measure->id, 'purchase_cost' => '1000', 'active' => true]);
        $selling = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $measure->id, 'conversion_quantity' => '1', 'selling_price' => '2000']);
        $supplier = Supplier::create(['name' => 'Supply', 'contact' => 'Local']);
        foreach ([$legacy, $destination] as $unit) {
            app(BusinessUnitService::class)->product(['product_id' => $product->id, 'business_unit_id' => $unit->id, 'available' => true, 'prices' => []]);
        }
        app(GoodsReceiptService::class)->post(['business_unit_id' => $legacy->id, 'supplier_id' => $supplier->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $selling->id, 'quantity' => '10', 'unit_cost' => '1000']]]);
        $normal = app(StockTransferService::class)->create(['source_business_unit_id' => $legacy->id, 'destination_business_unit_id' => $destination->id, 'items' => [['product_unit_id' => $selling->id, 'quantity' => '2']]]);
        app(StockTransferService::class)->send($normal->id);
        app(StockTransferService::class)->receive($normal->id);
        app(BusinessUnitContext::class)->select(null);
        $adjustment = app(AdjustmentService::class)->request(['business_unit_id' => $destination->id, 'reason' => 'BREAKAGE', 'notes' => 'Verified breakage allocation', 'items' => [['product_unit_id' => $selling->id, 'quantity' => '1']]]);
        $this->assertSame($destination->default_stock_location_id, $adjustment->stock_location_id);
        app(AdjustmentService::class)->decide($adjustment->id, true);
        app(BusinessUnitContext::class)->select(null);
        $history = DB::table('stock_movements')->where('movement_type', 'GRN')->orderBy('id')->get()->toJson();
        app(BusinessUnitService::class)->save(['id' => $legacy->id, 'branch_id' => $branch->id, 'name' => $legacy->name, 'code' => $legacy->code, 'active' => false]);
        $transfer = app(StockTransferService::class)->cutover(['source_location_id' => $legacy->default_stock_location_id, 'destination_business_unit_id' => $destination->id, 'notes' => 'Verified physical allocation', 'items' => [['product_unit_id' => $selling->id, 'quantity' => '4']]]);
        app(BusinessUnitContext::class)->select(null);
        $this->assertSame('RECEIVED', $transfer->status);
        $this->assertSame('4.000000', Stock::where('stock_location_id', $legacy->default_stock_location_id)->firstOrFail()->quantity);
        $this->assertSame('5.000000', Stock::where('stock_location_id', $destination->default_stock_location_id)->firstOrFail()->quantity);
        $this->assertSame($history, DB::table('stock_movements')->where('movement_type', 'GRN')->orderBy('id')->get()->toJson());
        $this->assertFalse($legacy->fresh()->active);
        $this->expectException(ValidationException::class);
        app(StockService::class)->location($legacy->default_stock_location_id);
    }

    public function test_terminal_creation_uses_unit_default_without_requiring_a_physical_location(): void
    {
        $branch = Branch::create(['name' => 'Main']);

        $unit = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'Terminal Unit',
            'code' => 'TERMINAL',
            'active' => true,
        ]);

        $this->postJson('/api/nightshift/commands/catalog-user', ['name' => 'BAR terminal', 'email' => 'bar-terminal@example.test', 'role' => 'POS Terminal', 'branch_id' => $branch->id, 'business_unit_ids' => [$unit->id], 'pin' => '0123', 'active' => true, 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $terminal = User::where('email', 'bar-terminal@example.test')->firstOrFail();
        $this->assertSame($unit->id, $terminal->terminal_business_unit_id);
        $this->assertSame($unit->default_stock_location_id, $terminal->terminal_stock_location_id);
        $this->actingAs($terminal)->getJson('/api/nightshift/bootstrap?pos_context=1')->assertOk()->assertJsonPath('operational_context.simple_mode', true)->assertJsonPath('business_unit_id', $unit->id)->assertJsonPath('location_id', $unit->default_stock_location_id)->assertJsonPath('pos_context.locked', true);
    }

    public function test_foreign_company_cannot_resolve_or_create_unit_defaults(): void
    {
        $branch = Branch::create(['name' => 'Main']);

        $unit = app(BusinessUnitService::class)->save([
            'branch_id' => $branch->id,
            'name' => 'Owner Unit',
            'code' => 'OWNER-UNIT',
            'active' => true,
        ]);

        $other = User::factory()->create();
        app(TenantContext::class)->run($other, function () use ($unit) {
            $this->expectException(HttpException::class);
            app(UnitOperationalService::class)->ensureDefault($unit);
        });
    }
}
