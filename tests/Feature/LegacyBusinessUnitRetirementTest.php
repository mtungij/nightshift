<?php

namespace Tests\Feature;

use App\Domain\BusinessUnitContext;
use App\Domain\StaffVisibility;
use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BusinessUnitService;
use App\Services\ReportService;
use App\Services\WorkspaceService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyBusinessUnitRetirementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /** @return array{int, int, int} */
    private function legacyUnit(): array
    {
        $branch = BusinessUnit::where('code', 'BAR')->firstOrFail()->branch_id;
        $scope = ['company_id' => $this->owner->company_id, 'branch_id' => $branch];
        $unit = DB::table('business_units')->insertGetId([...$scope, 'name' => 'General', 'code' => 'GENERAL']);
        $location = DB::table('stock_locations')->insertGetId([...$scope, 'name' => 'General Stock', 'business_unit_id' => $unit, 'type' => 'COUNTER', 'system_managed' => true]);
        DB::table('business_units')->where('id', $unit)->update(['default_stock_location_id' => $location]);

        return [$unit, $location, $branch];
    }

    private function retire(): void
    {
        (require database_path('migrations/2026_11_18_000001_retire_legacy_business_units.php'))->up();
    }

    public function test_unused_unit_and_system_location_are_deleted_with_only_their_own_pivots(): void
    {
        [$unit, $location, $branch] = $this->legacyUnit();
        $company = $this->owner->company_id;
        $product = Product::firstOrFail();
        $selling = $product->sellingUnits()->firstOrFail();
        $pivots = [
            'business_unit_products' => ['business_unit_id' => $unit, 'product_id' => $product->id, 'available' => true],
            'business_unit_prices' => ['business_unit_id' => $unit, 'product_unit_id' => $selling->id, 'selling_price' => '1234.00'],
            'business_unit_user' => ['business_unit_id' => $unit, 'user_id' => $this->owner->id],
            'business_unit_location' => ['business_unit_id' => $unit, 'stock_location_id' => $location, 'branch_id' => $branch],
            'counter_user' => ['stock_location_id' => $location, 'user_id' => $this->owner->id],
        ];
        foreach ($pivots as $table => $data) {
            DB::table($table)->insert(['company_id' => $company, ...$data]);
        }
        $before = DB::table('stocks')->orderBy('id')->get()->toArray();
        $otherMappings = DB::table('business_unit_products')->where('business_unit_id', '<>', $unit)->count();

        $this->retire();
        $this->retire();

        $this->assertDatabaseMissing('business_units', ['id' => $unit]);
        $this->assertDatabaseMissing('stock_locations', ['id' => $location]);
        foreach ($pivots as $table => $data) {
            $this->assertDatabaseMissing($table, $data);
        }
        $this->assertSame($otherMappings, DB::table('business_unit_products')->count());
        $this->assertEquals($before, DB::table('stocks')->orderBy('id')->get()->toArray());
    }

    public function test_referenced_records_are_archived_and_hidden_from_operations_but_kept_in_history(): void
    {
        [$unit, $location, $branch] = $this->legacyUnit();
        $scope = ['company_id' => $this->owner->company_id, 'branch_id' => $branch, 'stock_location_id' => $location];
        $shift = DB::table('shifts')->insertGetId([...$scope, 'user_id' => $this->owner->id, 'number' => 'SHF-LEGACY', 'type' => 'CASHIER', 'status' => 'CLOSED', 'opened_at' => now(), 'closed_at' => now()]);
        $order = DB::table('orders')->insertGetId([...$scope, 'waiter_id' => $this->owner->id, 'created_by' => $this->owner->id, 'shift_id' => $shift, 'issued_shift_id' => $shift, 'invoice_number' => 'INV-LEGACY', 'status' => 'PAID', 'issued_at' => now(), 'subtotal' => '50.00', 'total' => '50.00', 'amount_paid' => '50.00', 'collection_mode' => 'PAID_TICKET', 'created_at' => now(), 'updated_at' => now()]);
        $ticket = DB::table('ticket_collections')->insertGetId([...$scope, 'order_id' => $order, 'business_unit_id' => $unit, 'cashier_shift_id' => $shift, 'amount' => '50.00', 'status' => 'COLLECTED', 'collected_at' => now(), 'collected_by' => $this->owner->id]);
        foreach (['shifts' => $shift, 'orders' => $order, 'ticket_collections' => $ticket] as $type => $record) {
            DB::table('operational_unit_contexts')->insert([...$scope, 'business_unit_id' => $unit, 'record_type' => $type, 'record_id' => $record, 'created_at' => now()]);
        }
        $contexts = DB::table('operational_unit_contexts')->where('business_unit_id', $unit)->orderBy('id')->get()->toArray();
        $this->retire();

        $this->assertDatabaseHas('business_units', ['id' => $unit, 'active' => false, 'default_stock_location_id' => $location]);
        $this->assertDatabaseHas('stock_locations', ['id' => $location, 'business_unit_id' => $unit, 'active' => false]);
        $this->assertDatabaseHas('ticket_collections', ['id' => $ticket, 'business_unit_id' => $unit, 'status' => 'COLLECTED']);
        $this->assertEquals($contexts, DB::table('operational_unit_contexts')->where('business_unit_id', $unit)->orderBy('id')->get()->toArray());
        $this->assertSame($unit, Order::findOrFail($order)->business_unit_id);
        $this->assertTrue(app(StaffVisibility::class)->canViewShift($this->owner, Shift::findOrFail($shift)));
        $this->assertContains('INV-LEGACY', array_column(app(ReportService::class)->generate('Paid Orders')['rows'], 0));
        $this->assertContains('INV-LEGACY', array_column(app(ReportService::class)->generate('Paid Orders', ['shift_id' => $shift])['rows'], 0));
        $this->assertFalse($this->owner->canAccessBusinessUnit($unit));
        $this->assertFalse($this->owner->canAccessCounter($location));
        $this->assertNull(BusinessUnit::find($unit));
        $this->assertNull(StockLocation::find($location));
        $this->get('/business-units')->assertOk()->assertDontSee('General Stock')->assertDontSee('>General<', false);
        $this->getJson('/api/nightshift/bootstrap?business_unit_id='.$unit)->assertForbidden();
        foreach (['pos_context', 'unit_context'] as $mode) {
            $response = $this->getJson('/api/nightshift/bootstrap?'.$mode.'=1')->assertOk();
            foreach (['business_units', 'pos_unit_options'] as $key) {
                $this->assertNotContains($unit, array_column($response->json($key), 'id'));
            }
        }
    }

    public function test_unreconciled_stock_aborts_without_modifying_any_candidate_or_quantity(): void
    {
        [$unit, $location, $branch] = $this->legacyUnit();
        $product = Product::firstOrFail();
        DB::table('stocks')->insert(['company_id' => $this->owner->company_id, 'branch_id' => $branch, 'stock_location_id' => $location, 'product_id' => $product->id, 'quantity' => '3.500000']);
        $before = DB::table('stocks')->orderBy('id')->get()->toArray();
        try {
            $this->retire();
            $this->fail('Expected retirement to stop for stock reconciliation.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('stock (location/product): '.$location.'/'.$product->id, $error->getMessage());
        }

        $this->assertDatabaseHas('business_units', ['id' => $unit, 'active' => true, 'archived_at' => null]);
        $this->assertDatabaseHas('stock_locations', ['id' => $location, 'active' => true, 'archived_at' => null]);
        $this->assertEquals($before, DB::table('stocks')->orderBy('id')->get()->toArray());
        $this->assertDatabaseMissing('stock_movements', ['stock_location_id' => $location]);
    }

    public function test_staff_requires_explicit_assignment_and_contextless_audit_stays_unassigned(): void
    {
        [$unit, , $branch] = $this->legacyUnit();
        $waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $waiter->forceFill(['business_units_configured' => false])->save();
        $bar = BusinessUnit::where('code', 'BAR')->firstOrFail();
        $context = app(BusinessUnitContext::class);
        $this->assertSame([$bar->id], $context->ids($waiter));
        $this->assertFalse($waiter->canAccessBusinessUnit($unit));
        $this->actingAs($waiter);
        $this->getJson('/api/nightshift/bootstrap?business_unit_id='.$unit)->assertForbidden();

        $this->actingAs($this->owner);
        $log = app(AuditService::class)->record('BRANCH_ONLY', 'Business', 'Branch', $branch, [], [], $branch);
        $this->assertNull(AuditLog::findOrFail($log->id)->business_unit_id);
    }

    public function test_retired_identifiers_cannot_be_recreated_and_all_inventory_uses_explicit_availability(): void
    {
        $bar = BusinessUnit::where('code', 'BAR')->firstOrFail();
        $this->postJson('/api/business-units/save', ['idempotency_key' => (string) Str::uuid(), 'branch_id' => $bar->branch_id, 'name' => 'New unit', 'code' => 'GENERAL', 'active' => true])->assertUnprocessable();
        $this->postJson('/api/nightshift/commands/catalog-location', ['idempotency_key' => (string) Str::uuid(), 'branch_id' => $bar->branch_id, 'business_unit_id' => $bar->id, 'name' => 'General Stock', 'type' => 'STORE'])->assertUnprocessable();

        $product = Product::firstOrFail()->replicate(['sku', 'barcode']);
        $product->name = 'Unassigned inventory fixture';
        $product->sku = 'UNASSIGNED';
        $product->save();
        $inventory = app(WorkspaceService::class)->snapshot(['unit_context' => true]);
        $this->assertNotContains($product->id, array_column($inventory['products'], 'id'));

        app(BusinessUnitService::class)->product(['product_id' => $product->id, 'business_unit_id' => $bar->id, 'available' => true, 'prices' => []]);
        $inventory = app(WorkspaceService::class)->snapshot(['unit_context' => true]);
        $this->assertContains($product->id, array_column($inventory['products'], 'id'));
    }
}
