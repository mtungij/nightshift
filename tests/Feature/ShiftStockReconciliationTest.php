<?php

namespace Tests\Feature;

use App\Domain\Decimal as D;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftStockSnapshot;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Services\ShiftStockService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Services\TransactionService;
use App\Services\VoidService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CompletesCashDisposition;
use Tests\Support\SettlesCounterWaiters;
use Tests\TestCase;

class ShiftStockReconciliationTest extends TestCase
{
    use CompletesCashDisposition;
    use RefreshDatabase;
    use SettlesCounterWaiters;

    private User $owner;

    private User $cashier;

    private User $waiter;

    private Shift $shift;

    private ProductUnit $unit;

    private ShiftStockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        $this->waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->shift = Shift::where('user_id', $this->cashier->id)->where('type', 'CASHIER')->firstOrFail();
        $this->unit = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', 'Piece'))->firstOrFail();
        $this->service = app(ShiftStockService::class);
    }

    private function row(?array $report = null): array
    {
        return collect(($report ?? $this->service->report($this->shift->id))['rows'])->firstWhere('product_id', $this->unit->product_id);
    }

    private function save(string $difference = '0', string $reason = ''): array
    {
        $this->settleCounterWaiters($this->shift->stock_location_id);
        $r = $this->service->report($this->shift->id);
        $counts = array_map(fn ($line) => ['product_id' => $line['product_id'], 'quantity' => D::add($line['expected'], $line['product_id'] === $this->unit->product_id ? $difference : '0', 6)], $r['rows']);
        $this->service->count($this->shift->id, ['actual_cash' => app(ShiftService::class)->expected($this->shift), 'basis' => $r['basis'], 'revision' => $r['revision'], 'counts' => $counts, 'reason' => $reason]);

        return $this->service->report($this->shift->id);
    }

    private function move(string $amount, string $type = 'GRN', ?int $location = null): void
    {
        app(TransactionService::class)->run(fn () => app(StockService::class)->change($location ?? $this->shift->stock_location_id, $this->unit->product_id, $amount, $type, 'Shift', $this->shift->id));
    }

    private function issue(string $quantity = '1', ?ProductUnit $unit = null): Order
    {
        $order = app(OrderService::class)->create(['stock_location_id' => $this->shift->stock_location_id, 'waiter_id' => $this->waiter->id, 'items' => [['product_unit_id' => ($unit ?? $this->unit)->id, 'quantity' => $quantity]]]);

        return app(OrderService::class)->issue($order->id);
    }

    public function test_opening_is_frozen_and_every_expected_balance_matches_current_stock(): void
    {
        $snapshot = ShiftStockSnapshot::where('shift_id', $this->shift->id)->where('kind', 'OPENING')->firstOrFail();
        $before = $snapshot->data;
        $this->move('7');
        $this->assertSame($this->shift->id, StockMovement::latest('id')->firstOrFail()->shift_id);
        $r = $this->service->report($this->shift->id);
        $this->assertSame($before, $snapshot->fresh()->data);
        $this->assertSame(0, $r['totals']['ledger_mismatches']);
        foreach ($r['rows'] as $line) {
            $this->assertSame(0, D::cmp($line['expected'], Stock::where('stock_location_id', $this->shift->stock_location_id)->where('product_id', $line['product_id'])->value('quantity') ?? '0'));
        }
        $this->assertSame('7.000000', $this->row($r)['added']);
    }

    public function test_transfer_and_positive_adjustment_are_additions_without_double_counting(): void
    {
        $store = StockLocation::where('name', 'Main Store')->firstOrFail();
        $transfer = app(StockTransferService::class)->create(['source_location_id' => $store->id, 'destination_location_id' => $this->shift->stock_location_id, 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '4']]]);
        app(StockTransferService::class)->send($transfer->id);
        app(StockTransferService::class)->receive($transfer->id);
        app(StockTransferService::class)->receive($transfer->id);
        $this->move('0.75', 'APPROVED_POSITIVE_ADJUSTMENT');
        $this->assertSame('4.750000', $this->row()['added']);
    }

    public function test_bucket_and_crate_sales_use_base_quantity_and_unpaid_waiter_debt_does_not_restore_stock(): void
    {
        foreach (['Bucket', 'Crate'] as $name) {
            $unit = ProductUnit::where('product_id', $this->unit->product_id)->whereHas('unit', fn ($q) => $q->where('name', $name))->firstOrFail();
            $before = $this->row();
            $order = $this->issue('1', $unit);
            $after = $this->row();
            $this->assertSame($unit->conversion_quantity, D::sub($after['system_sold'], $before['system_sold'], 6));
            $this->assertSame($unit->conversion_quantity, D::sub($before['expected'], $after['expected'], 6));
            $this->assertSame($order->total, $order->balance);
        }
    }

    public function test_drafts_do_not_move_stock_and_returns_and_partial_voids_are_not_double_counted(): void
    {
        $before = $this->row();
        $draft = app(OrderService::class)->create(['stock_location_id' => $this->shift->stock_location_id, 'waiter_id' => $this->waiter->id, 'status' => 'DRAFT', 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '3']]]);
        $this->assertSame($before, $this->row());
        $this->assertSame('DRAFT', $draft->status);
        $order = $this->issue('3');
        app(ReturnService::class)->process($order->id, ['reason' => 'Unused sealed drinks', 'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => '1']]]);
        $void = app(VoidService::class)->request($order->id, 'Customer cancelled remaining drinks');
        app(VoidService::class)->decide($void->id, true, 'Recovered the remaining sealed drinks');
        $after = $this->row();
        $this->assertSame('3.000000', D::sub($after['returns'], $before['returns'], 6));
        $this->assertSame($before['expected'], $after['expected']);
    }

    public function test_breakages_and_transfers_out_reduce_expected_stock_but_are_not_system_sales(): void
    {
        $before = $this->row();
        $this->move('-0.75', 'BREAKAGE');
        $this->move('-1', 'COMPLIMENTARY');
        $this->move('-2', 'TRANSFER_SENT');
        $after = $this->row();
        $this->assertSame($before['system_sold'], $after['system_sold']);
        $this->assertSame('3.750000', D::sub($after['other_out'], $before['other_out'], 6));
        $this->assertSame('3.750000', D::sub($before['expected'], $after['expected'], 6));
    }

    public function test_physical_count_balanced_short_over_and_variance_values_use_selling_price(): void
    {
        $r = $this->save();
        $this->assertTrue($r['complete']);
        $this->assertSame('Balanced', $this->row($r)['status']);
        foreach (['-2' => 'Short', '0.75' => 'Over'] as $difference => $status) {
            $r = $this->save((string) $difference, 'Physical stock counted twice');
            $row = $this->row($r);
            $this->assertSame($status, $row['status']);
            $this->assertSame(D::mul(ltrim((string) $difference, '-'), $row['price']), $row['variance_value']);
            $this->assertSame(D::mul($row['physical_sold'], $row['price']), $row['sales_value']);
        }
    }

    public function test_close_requires_complete_count_and_shortage_requires_manager_approval(): void
    {
        $this->settleCounterWaiters($this->shift->stock_location_id);
        $this->actingAs($this->cashier);
        $this->postJson('/api/nightshift/commands/shift-close', ['id' => $this->shift->id, 'actual_cash' => app(ShiftService::class)->expected($this->shift), 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $r = $this->save('-2', 'Two bottles missing after recount');
        $this->postJson('/api/nightshift/commands/shift-close', ['id' => $this->shift->id, 'actual_cash' => app(ShiftService::class)->expected($this->shift), 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/nightshift/commands/shift-stock-approve', ['id' => $this->shift->id, 'basis' => $r['basis'], 'revision' => $r['revision'], 'note' => 'Manager reviewed missing bottles', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($this->owner);
        $this->service->approve($this->shift->id, $r['basis'], $r['revision'], 'Manager reviewed missing bottles');
        $before = [StockMovement::count(), WaiterLedgerEntry::count(), Payment::count()];
        $this->prepareCashDisposition($this->shift);
        app(ShiftService::class)->close($this->shift->id, ['actual_cash' => app(ShiftService::class)->expected($this->shift)]);
        $this->assertSame($before, [StockMovement::count(), WaiterLedgerEntry::count(), Payment::count()]);
        $this->assertSame('CLOSED', $this->shift->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'SHIFT_STOCK_APPROVED')->exists());
    }

    public function test_movement_after_count_invalidates_approval_and_requires_a_recount(): void
    {
        $r = $this->save('-1', 'Missing bottle verified twice');
        $this->service->approve($this->shift->id, $r['basis'], $r['revision'], 'Reviewed by company owner');
        $this->move('1');
        $changed = $this->service->report($this->shift->id);
        $this->assertTrue($changed['stale']);
        $this->assertFalse($changed['complete']);
        $this->assertFalse($changed['approved']);
        $this->postJson('/api/nightshift/commands/shift-close', ['id' => $this->shift->id, 'actual_cash' => app(ShiftService::class)->expected($this->shift), 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_stale_count_or_revision_is_rejected_without_overwriting_other_counts(): void
    {
        $old = $this->service->report($this->shift->id);
        $this->save();
        $payload = ['actual_cash' => app(ShiftService::class)->expected($this->shift), 'id' => $this->shift->id, 'basis' => $old['basis'], 'revision' => 0, 'counts' => array_map(fn ($r) => ['product_id' => $r['product_id'], 'quantity' => $r['expected']], $old['rows']), 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/nightshift/commands/shift-stock-count', $payload)->assertConflict();
        $this->move('1');
        $this->postJson('/api/nightshift/commands/shift-stock-count', [...$payload, 'revision' => 1])->assertConflict();
    }

    public function test_closed_report_is_frozen_and_count_edits_and_cashier_cost_visibility_are_blocked(): void
    {
        $this->settleCounterWaiters($this->shift->stock_location_id);
        $this->save();
        $this->prepareCashDisposition($this->shift);
        app(ShiftService::class)->close($this->shift->id, ['actual_cash' => app(ShiftService::class)->expected($this->shift)]);
        $before = $this->service->report($this->shift->id);
        $this->move('5');
        Product::findOrFail($this->unit->product_id)->update(['name' => 'Renamed after close']);
        $this->assertSame($before, $this->service->report($this->shift->id));
        $this->postJson('/api/nightshift/commands/shift-stock-count', ['id' => $this->shift->id, 'basis' => $before['basis'], 'revision' => $before['revision'], 'counts' => [], 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->actingAs($this->cashier);
        $this->getJson('/api/nightshift/shift-stock/'.$this->shift->id)->assertOk()->assertJsonMissingPath('rows.0.cost')->assertJsonMissingPath('totals.cost_variance_value');
    }

    public function test_snapshots_have_database_and_model_immutability_protection(): void
    {
        $snapshot = ShiftStockSnapshot::where('shift_id', $this->shift->id)->firstOrFail();
        try {
            $snapshot->update(['data' => []]);
            $this->fail('Expected immutable model');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('shift_stock_snapshots')->where('id', $snapshot->id)->update(['data' => '{}']);
            $this->fail('Expected immutable database');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('shift_stock_snapshots')->where('id', $snapshot->id)->delete();
            $this->fail('Expected immutable database');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_waiter_foreign_company_other_cashier_and_unassigned_branch_are_denied(): void
    {
        $this->actingAs($this->waiter)->getJson('/api/nightshift/shift-stock/'.$this->shift->id)->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/nightshift/shift-stock/'.$this->shift->id)->assertNotFound();
        $this->actingAs($this->cashier);
        $ownerShift = Shift::where('user_id', $this->owner->id)->firstOrFail();
        $this->getJson('/api/nightshift/shift-stock/'.$ownerShift->id)->assertForbidden();
        $manager = User::where('email', 'manager@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);

        $branch = Branch::create(['name' => 'Restricted branch']);

        $businessUnit = \App\Models\BusinessUnit::create([
            'branch_id' => $branch->id,
            'name' => 'Restricted Operations',
            'code' => 'RESTRICTED-'.$branch->id,
            'active' => true,
        ]);

        $counter = StockLocation::create([
            'branch_id' => $branch->id,
            'business_unit_id' => $businessUnit->id,
            'name' => 'Restricted counter',
            'type' => 'COUNTER',
        ]);

        $otherOwner = User::factory()->create([
            'company_id' => $this->owner->company_id,
            'role_id' => $this->owner->role_id,
        ]);

        $otherOwner->branches()->attach($branch->id, [
            'company_id' => $otherOwner->company_id,
        ]);

        $otherOwner->businessUnits()->syncWithoutDetaching([
            $businessUnit->id => [
                'company_id' => $otherOwner->company_id,
            ],
        ]);

        $other = app(ShiftService::class)->open([
            'user_id' => $otherOwner->id,
            'stock_location_id' => $counter->id,
            'type' => 'CASHIER',
        ]);
        $this->actingAs($manager)->getJson('/api/nightshift/shift-stock/'.$other->id)->assertNotFound();
    }

    public function test_payment_channels_refunds_waiter_outstanding_and_printable_languages_remain_separate(): void
    {
        $order = $this->issue('3');
        app(PaymentService::class)->receive($order->id, [['method' => 'Cash', 'amount' => '1000'], ['method' => 'M-Pesa', 'amount' => '2000'], ['method' => 'Card', 'amount' => '1000']]);
        $r = $this->service->report($this->shift->id);
        // All receipts at a counter belong to its single cashier operation.
        $this->assertSame('21000.00', $r['cash']['methods']['Cash']['net']);
        $ownerShift = $this->shift;
        $cash = $this->service->report($ownerShift->id)['cash'];
        $this->assertSame('21000.00', $cash['methods']['Cash']['net']);
        $this->assertSame('2000.00', $cash['methods']['M-Pesa']['net']);
        $this->assertSame('1000.00', $cash['methods']['Card']['net']);
        $this->assertSame('321000.00', $cash['expected']);
        $this->assertGreaterThan(0, D::cmp($cash['waiter_outstanding'], '0'));
        $void = app(VoidService::class)->request($order->id, 'Refund cancelled order');
        app(VoidService::class)->decide($void->id, true, 'Returned all goods and approved refund');
        $cash = $this->service->report($ownerShift->id)['cash'];
        $this->assertSame('1000.00', $cash['methods']['Cash']['refunds']);
        foreach (['en', 'sw'] as $locale) {
            $this->owner->forceFill(['language_preference' => $locale])->save();
            $this->actingAs($this->owner);
            $this->get('/shift-stock/'.$this->shift->id.'/print')->assertOk()->assertSee($locale === 'sw' ? 'Hesabu ya Stock ya Zamu' : 'Stock Reconciliation')->assertSee('A4 landscape')->assertSee('300000.00')->assertSee('Serengeti Lite');
        }
    }

    public function test_direct_counter_grn_and_fractional_counts_use_exact_base_units(): void
    {
        app(GoodsReceiptService::class)->post(['stock_location_id' => $this->shift->stock_location_id, 'supplier_id' => Supplier::firstOrFail()->id, 'supplier_invoice' => (string) Str::uuid(), 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '1.5', 'unit_cost' => '1200']]]);
        $this->assertSame('1.500000', $this->row()['added']);
        $this->move('-0.75', 'BREAKAGE');
        $r = $this->save();
        $this->assertSame(0, D::cmp($this->row($r)['physical'], $this->row($r)['expected']));
        $this->assertSame('Balanced', $this->row($r)['status']);
    }

    public function test_count_edits_clear_prior_approval_and_record_before_after_audit(): void
    {
        $r = $this->save('-1', 'One missing bottle verified');
        $this->service->approve($this->shift->id, $r['basis'], $r['revision'], 'Reviewed physical count and missing bottle');
        $this->assertTrue($this->service->report($this->shift->id)['approved']);
        $edited = $this->save('-2', 'Second bottle found missing on recount');
        $this->assertFalse($edited['approved']);
        $this->assertSame(2, $edited['revision']);
        $audit = AuditLog::where('action', 'SHIFT_STOCK_COUNTED')->latest('id')->firstOrFail();
        $this->assertSame(1, $audit->old_values['revision']);
        $this->assertSame(2, $audit->new_values['revision']);
    }

    public function test_missing_counts_invalid_precision_and_unexplained_variance_cannot_be_saved(): void
    {
        $r = $this->service->report($this->shift->id);
        $base = ['id' => $this->shift->id, 'basis' => $r['basis'], 'revision' => 0, 'idempotency_key' => (string) Str::uuid()];
        foreach ([[], [['product_id' => $this->unit->product_id, 'quantity' => '-1']], [['product_id' => $this->unit->product_id, 'quantity' => '0.1234567']]] as $counts) {
            $this->postJson('/api/nightshift/commands/shift-stock-count', [...$base, 'counts' => $counts])->assertUnprocessable();
        }
        $counts = array_map(fn ($row) => ['product_id' => $row['product_id'], 'quantity' => '0'], $r['rows']);
        $this->postJson('/api/nightshift/commands/shift-stock-count', [...$base, 'counts' => $counts])->assertUnprocessable();
        $this->assertDatabaseCount('shift_stock_counts', 0);
    }

    public function test_ledger_drift_is_visible_and_never_silently_adjusted(): void
    {
        $stock = Stock::where('stock_location_id', $this->shift->stock_location_id)->where('product_id', $this->unit->product_id)->firstOrFail();
        $stock->update(['quantity' => D::sub($stock->quantity, '1', 6)]);
        $r = $this->save();
        $this->assertSame(1, $r['totals']['ledger_mismatches']);
        $before = StockMovement::count();
        $this->postJson('/api/nightshift/commands/shift-close', ['id' => $this->shift->id, 'actual_cash' => app(ShiftService::class)->expected($this->shift), 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame($before, StockMovement::count());
    }

    public function test_opening_price_is_base_unit_price_and_later_catalog_changes_do_not_revalue_it(): void
    {
        $before = $this->row();
        $this->unit->update(['selling_price' => '99999']);
        $this->assertSame($before['price'], $this->row()['price']);
        $this->assertSame('3000.000000', $before['price']);
        $this->assertGreaterThan(1, count($before['conversions']));
    }

    public function test_supervisor_can_review_assigned_branch_but_cannot_gain_override_permission(): void
    {
        $supervisor = User::factory()->create([
            'company_id' => $this->owner->company_id,
            'role_id' => Role::where('name', 'Supervisor')->firstOrFail()->id,
        ]);

        $supervisor->branches()->attach($this->shift->branch_id, [
            'company_id' => $this->owner->company_id,
        ]);

        $shiftUnitId = StockLocation::whereKey($this->shift->stock_location_id)
            ->value('business_unit_id');

        $supervisor->businessUnits()->syncWithoutDetaching([
            $shiftUnitId => [
                'company_id' => $supervisor->company_id,
            ],
        ]);

        $this->actingAs($supervisor);
        $this->getJson('/api/nightshift/shift-stock/'.$this->shift->id)->assertOk()->assertJsonPath('can_approve', false)->assertJsonPath('can_edit', false);
    }

    public function test_legacy_shifts_are_not_given_fabricated_opening_snapshots(): void
    {
        $legacy = Shift::create(['branch_id' => $this->shift->branch_id, 'stock_location_id' => $this->shift->stock_location_id, 'user_id' => $this->owner->id, 'number' => 'LEGACY-RECON', 'type' => 'CASHIER', 'status' => 'OPEN', 'opened_at' => now()->subDay(), 'opening_cash' => '0']);
        $this->getJson('/api/nightshift/shift-stock/'.$legacy->id)->assertConflict();
        $this->assertFalse(ShiftStockSnapshot::where('shift_id', $legacy->id)->exists());
        try {
            $this->move('1');
            $this->fail('Stock must not be attributed to an arbitrary legacy cashier.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('overlapping legacy cashier shifts', $e->getMessage());
        }
        $this->expectException(ValidationException::class);
        app(TransactionService::class)->run(fn () => app(ShiftService::class)->counterCashier($this->shift->stock_location_id));
    }

    public function test_base_unit_price_is_preferred_over_a_fractional_pack_markup(): void
    {
        $half = Unit::create(['name' => 'Half base unit']);
        ProductUnit::create(['product_id' => $this->unit->product_id, 'unit_id' => $half->id, 'conversion_quantity' => '0.5', 'selling_price' => '2000']);
        $cashier = User::factory()->create([
            'company_id' => $this->owner->company_id,
            'role_id' => $this->owner->role_id,
        ]);

        $businessUnitId = StockLocation::whereKey($this->shift->stock_location_id)
            ->value('business_unit_id');

        $counter = StockLocation::create([
            'branch_id' => $this->shift->branch_id,
            'business_unit_id' => $businessUnitId,
            'name' => 'New price counter',
            'type' => 'COUNTER',
        ]);

        $cashier->branches()->attach($this->shift->branch_id, [
            'company_id' => $cashier->company_id,
        ]);

        $cashier->businessUnits()->syncWithoutDetaching([
            $businessUnitId => [
                'company_id' => $cashier->company_id,
            ],
        ]);

        $shift = app(ShiftService::class)->open([
            'user_id' => $cashier->id,
            'stock_location_id' => $counter->id,
            'type' => 'CASHIER',
        ]);
        $row = collect($this->service->report($shift->id)['rows'])->firstWhere('product_id', $this->unit->product_id);
        $this->assertSame('3000.000000', $row['price']);
    }
}
