<?php

namespace Tests\MySql;

use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\CashHandoverEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\SalesTicketBatch;
use App\Models\Shift;
use App\Models\ShiftStockSnapshot;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TreasuryEntry;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Models\WhatsAppDeliveryLog;
use App\Services\AdjustmentService;
use App\Services\BusinessUnitService;
use App\Services\CashHandoverService;
use App\Services\CounterCashierService;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\SalesTicketService;
use App\Services\ShiftService;
use App\Services\ShiftStockService;
use App\Services\StaffPinService;
use App\Services\StockService;
use App\Services\TransactionService;
use App\Services\TreasuryService;
use App\Services\UnitOperationalService;
use App\Services\WaiterLedgerService;
use App\Services\WhatsApp\Outbox;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\ScheduleService;
use App\Services\WhatsApp\SettingsService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CompletesCashDisposition;
use Tests\Support\SettlesCounterWaiters;
use Tests\TestCase;

/** Committed fixtures allow independent PHP processes to contend for real InnoDB locks. */
class ConcurrencyTest extends TestCase
{
    use CompletesCashDisposition;
    use DatabaseMigrations;
    use SettlesCounterWaiters;

    private User $owner;

    private User $cashier;

    private User $waiter;

    private StockLocation $counter;

    private ProductUnit $piece;

    // Custody migrations intentionally cannot roll back immutable history. These
    // committed fixtures rebuild only the explicitly disposable test database.
    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || ! preg_match('/_(?:test|validation)$/D', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('Concurrency fixtures require a disposable validation database.');
        }
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('mysql_validation', config('database.default'));
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        $this->waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->counter = StockLocation::where('name', 'Main Counter')->firstOrFail();
        $this->piece = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', 'Piece'))->firstOrFail();
    }

    private function order(): Order
    {
        return app(OrderService::class)->create(['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]);
    }

    private function quantity(): string
    {
        return (string) Stock::where('stock_location_id', $this->counter->id)->where('product_id', $this->piece->product_id)->value('quantity');
    }

    private function race(array $requests): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'nightshift-mysql-barrier-');
        if ($barrier === false) {
            throw new \RuntimeException('Cannot create concurrency barrier.');
        }
        unlink($barrier);
        $workers = [];
        try {
            foreach ($requests as $request) {
                $process = proc_open([PHP_BINARY, base_path('tests/MySql/transaction-worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                if (! is_resource($process)) {
                    throw new \RuntimeException('Cannot start MySQL worker.');
                }
                fwrite($pipes[0], json_encode([...$request, 'barrier' => $barrier], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 20);
                $workers[] = ['process' => $process, 'output' => $pipes[1], 'error' => $pipes[2]];
            }
            // Both independent connections must be ready before either may mutate.
            foreach ($workers as $worker) {
                $this->assertSame("READY\n", fgets($worker['output']));
            }
            touch($barrier);
            $results = [];
            foreach ($workers as $worker) {
                $line = fgets($worker['output']);
                $this->assertNotFalse($line, 'Worker timed out or returned no result.');
                $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('', stream_get_contents($worker['error']));
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                if (proc_get_status($worker['process'])['running']) {
                    proc_terminate($worker['process']);
                }
                fclose($worker['output']);
                fclose($worker['error']);
                proc_close($worker['process']);
            }
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }
    }

    public function test_simultaneous_cashiers_issue_the_same_invoice_exactly_once(): void
    {
        $order = $this->order();
        $before = $this->quantity();
        $results = $this->race([
            ['action' => 'issue', 'user_id' => $this->owner->id, 'order_id' => $order->id],
            ['action' => 'issue', 'user_id' => $this->cashier->id, 'order_id' => $order->id],
        ]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame(0, bccomp(bcsub($before, '1', 6), $this->quantity(), 6));
        $this->assertSame(1, StockMovement::where('reference_type', 'Order')->where('reference_id', $order->id)->where('movement_type', 'ISSUE')->count());
        $this->assertSame(1, WaiterLedgerEntry::where('order_id', $order->id)->where('entry_type', 'ISSUE')->count());
        $this->assertContains($order->fresh()->issued_by, [$this->owner->id, $this->cashier->id]);
        $this->assertNotNull($order->fresh()->issued_shift_id);
    }

    public function test_two_invoices_competing_for_the_last_drink_cannot_create_negative_stock(): void
    {
        $adjustment = app(AdjustmentService::class)->request(['stock_location_id' => $this->counter->id, 'reason' => 'OTHER', 'notes' => 'Prepare last-drink concurrency fixture', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => bcsub($this->quantity(), '1', 6)]]]);
        app(AdjustmentService::class)->decide($adjustment->id, true, 'Approved concurrency fixture stock count');
        $first = $this->order();
        $second = $this->order();
        $results = $this->race([
            ['action' => 'issue', 'user_id' => $this->owner->id, 'order_id' => $first->id],
            ['action' => 'issue', 'user_id' => $this->cashier->id, 'order_id' => $second->id],
        ]);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200, 422], $codes);
        $this->assertSame(0, bccomp('0', $this->quantity(), 6));
        $this->assertSame(1, Order::whereIn('id', [$first->id, $second->id])->where('status', 'ISSUED')->count());
        $this->assertSame(1, Order::whereIn('id', [$first->id, $second->id])->where('status', 'WAITING_FOR_ISSUE')->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'Order')->whereIn('reference_id', [$first->id, $second->id])->where('movement_type', 'ISSUE')->count());
    }

    public function test_simultaneous_retries_with_the_same_payment_key_post_once(): void
    {
        $order = app(OrderService::class)->issue($this->order()->id);
        $request = ['action' => 'payment', 'user_id' => $this->owner->id, 'order_id' => $order->id, 'amount' => $order->total, 'key' => (string) Str::uuid()];
        $results = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame(1, PaymentAllocation::where('order_id', $order->id)->count());
        $this->assertSame(1, WaiterLedgerEntry::where('order_id', $order->id)->where('entry_type', 'PAYMENT')->count());
        $this->assertSame('PAID', $order->fresh()->status);
        $this->assertSame(0, bccomp('0', $order->fresh()->balance, 2));
    }

    public function test_simultaneous_different_payment_keys_cannot_overpay(): void
    {
        $order = app(OrderService::class)->issue($this->order()->id);
        $request = ['action' => 'payment', 'user_id' => $this->owner->id, 'order_id' => $order->id, 'amount' => $order->total];
        $results = $this->race([[...$request, 'key' => (string) Str::uuid()], [...$request, 'key' => (string) Str::uuid()]]);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200, 422], $codes);
        $this->assertSame(1, PaymentAllocation::where('order_id', $order->id)->count());
        $this->assertSame(0, bccomp($order->total, (string) PaymentAllocation::where('order_id', $order->id)->sum('amount'), 2));
        $this->assertSame(0, bccomp('0', $order->fresh()->balance, 2));
    }

    private function sendRequest(string $key): array
    {
        app(StaffPinService::class)->set($this->waiter->id, '0123');

        return ['action' => 'send', 'user_id' => $this->waiter->id, 'key' => $key, 'payload' => ['waiter_id' => $this->waiter->id, 'pin' => '0123', 'stock_location_id' => $this->counter->id, 'discount' => '0', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]];
    }

    public function test_simultaneous_direct_send_retries_issue_and_debit_once(): void
    {
        $request = $this->sendRequest((string) Str::uuid());
        $before = $this->quantity();
        $results = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(0, bccomp(bcsub($before, '1', 6), $this->quantity(), 6));
        $this->assertSame(1, WaiterLedgerEntry::where('order_id', $results[0]['id'])->where('entry_type', 'ISSUE')->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'Order')->where('reference_id', $results[0]['id'])->where('movement_type', 'ISSUE')->count());
        $this->assertSame($results[0]['print_batch'], $results[1]['print_batch']);
        $this->assertNotNull($results[0]['print_batch']);
        $this->assertSame(1, SalesTicketBatch::count());
        $this->assertSame(2, AuditLog::where('action', 'SALES_TICKET_PRINT')->count());
    }

    public function test_shared_terminal_simultaneous_retries_issue_and_print_once(): void
    {
        $terminal = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => Role::where('name', 'POS Terminal')->firstOrFail()->id, 'terminal_stock_location_id' => $this->counter->id]);
        $request = [...$this->sendRequest((string) Str::uuid()), 'user_id' => $terminal->id];
        $before = $this->quantity();
        $results = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame($results[0]['print_batch'], $results[1]['print_batch']);
        $this->assertSame(bcsub($before, '1', 6), $this->quantity());
        $order = Order::findOrFail($results[0]['id']);
        $this->assertSame($terminal->id, $order->created_by);
        $this->assertSame($this->waiter->id, $order->waiter_id);
        $this->assertSame(1, WaiterLedgerEntry::where('order_id', $order->id)->where('entry_type', 'ISSUE')->count());
        $this->assertSame(1, SalesTicketBatch::where('order_id', $order->id)->count());
    }

    public function test_simultaneous_direct_sends_for_last_drink_roll_back_losing_order(): void
    {
        $adjustment = app(AdjustmentService::class)->request(['stock_location_id' => $this->counter->id, 'reason' => 'OTHER', 'notes' => 'Prepare direct-send concurrency stock', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => bcsub($this->quantity(), '1', 6)]]]);
        app(AdjustmentService::class)->decide($adjustment->id, true, 'Approved direct-send fixture stock count');
        $request = $this->sendRequest((string) Str::uuid());
        $before = Order::count();
        $results = $this->race([$request, [...$request, 'key' => (string) Str::uuid()]]);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200, 422], $codes);
        $this->assertSame($before + 1, Order::count());
        $this->assertSame(0, bccomp('0', $this->quantity(), 6));
    }

    public function test_simultaneous_waiter_settlement_retries_credit_once(): void
    {
        $balance = app(WaiterLedgerService::class)->balance($this->waiter->id);
        $request = ['action' => 'waiter-payment', 'user_id' => $this->owner->id, 'key' => (string) Str::uuid(), 'payload' => ['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'amount' => $balance]];
        $results = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame('0.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
        $this->assertSame(0, Order::where('waiter_id', $this->waiter->id)->whereNotNull('issued_at')->where('balance', '>', 0)->count());
    }

    public function test_simultaneous_different_waiter_settlement_keys_cannot_overpay(): void
    {
        $balance = app(WaiterLedgerService::class)->balance($this->waiter->id);
        $before = PaymentAllocation::count();
        $request = ['action' => 'waiter-payment', 'user_id' => $this->owner->id, 'payload' => ['waiter_id' => $this->waiter->id, 'stock_location_id' => $this->counter->id, 'amount' => $balance]];
        $results = $this->race([[...$request, 'key' => (string) Str::uuid()], [...$request, 'key' => (string) Str::uuid()]]);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200, 422], $codes);
        $this->assertSame($before + 1, PaymentAllocation::count());
        $this->assertSame('0.00', app(WaiterLedgerService::class)->balance($this->waiter->id));
    }

    public function test_simultaneous_ticket_dispatch_opens_one_print_request(): void
    {
        $order = app(OrderService::class)->issue($this->order()->id);
        $batch = app(SalesTicketService::class)->prepare($order->id, (string) Str::uuid());
        $request = ['action' => 'ticket-dispatch', 'user_id' => $this->owner->id, 'token' => $batch->token];
        $before = $this->quantity();
        $results = $this->race([$request, $request]);
        $prints = array_column($results, 'print');
        sort($prints);
        $this->assertSame([false, true], $prints);
        $this->assertSame(2, AuditLog::where('action', 'SALES_TICKET_DISPATCH')->count());
        $this->assertSame($before, $this->quantity());
    }

    public function test_simultaneous_reprint_retries_create_one_batch_without_business_changes(): void
    {
        $order = app(OrderService::class)->issue($this->order()->id);
        $before = [$this->quantity(), WaiterLedgerEntry::count(), PaymentAllocation::count(), $order->toArray()];
        $request = ['action' => 'ticket-reprint', 'user_id' => $this->owner->id, 'order_id' => $order->id, 'key' => (string) Str::uuid()];
        $results = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, SalesTicketBatch::where('reprint', true)->count());
        $this->assertSame(2, AuditLog::where('action', 'SALES_TICKET_REPRINT')->count());
        $this->assertSame($before, [$this->quantity(), WaiterLedgerEntry::count(), PaymentAllocation::count(), $order->fresh()->toArray()]);
    }

    private function prepareReconciliation(bool $disposition = true): Shift
    {
        $this->settleCounterWaiters($this->counter->id);
        $shift = Shift::where('user_id', $this->cashier->id)->where('type', 'CASHIER')->firstOrFail();
        $service = app(ShiftStockService::class);
        $report = $service->report($shift->id);
        $service->count($shift->id, ['actual_cash' => app(ShiftService::class)->expected($shift), 'basis' => $report['basis'], 'revision' => $report['revision'], 'counts' => array_map(fn ($row) => ['product_id' => $row['product_id'], 'quantity' => $row['expected']], $report['rows'])]);

        if ($disposition) {
            $this->prepareCashDisposition($shift);
        }

        return $shift;
    }

    public function test_stock_movement_and_final_close_have_one_serialized_cutoff(): void
    {
        $shift = $this->prepareReconciliation();
        $before = $this->quantity();
        $ledger = WaiterLedgerEntry::count();
        $payments = Payment::count();
        $results = $this->race([
            ['action' => 'recon-close', 'user_id' => $this->cashier->id, 'shift_id' => $shift->id, 'actual_cash' => app(ShiftService::class)->expected($shift), 'key' => (string) Str::uuid()],
            ['action' => 'recon-move', 'user_id' => $this->owner->id, 'location_id' => $this->counter->id, 'product_id' => $this->piece->product_id, 'shift_id' => $shift->id],
        ]);
        $this->assertSame(200, $results[0]['code']);
        $this->assertContains($results[1]['code'], [200, 422]);
        $this->assertSame($results[1]['code'] === 200 ? bcadd($before, '1', 6) : $before, $this->quantity());
        $this->assertSame($ledger, WaiterLedgerEntry::count());
        $this->assertSame($payments, Payment::count());
        $report = app(ShiftStockService::class)->report($shift->id);
        $this->assertSame('CLOSED', $shift->fresh()->status);
        $this->assertSame($before, collect($report['rows'])->firstWhere('product_id', $this->piece->product_id)['expected']);
        $this->assertFalse($report['stale']);
    }

    public function test_duplicate_close_claim_cannot_duplicate_final_reconciliation(): void
    {
        $shift = $this->prepareReconciliation();
        $request = ['action' => 'recon-close', 'user_id' => $this->cashier->id, 'shift_id' => $shift->id, 'actual_cash' => app(ShiftService::class)->expected($shift), 'key' => (string) Str::uuid()];
        $results = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame(1, ShiftStockSnapshot::where('shift_id', $shift->id)->where('kind', 'CLOSING')->count());
        $this->assertSame(1, AuditLog::where('action', 'SHIFT_STOCK_FINALIZED')->where('reference_id', (string) $shift->id)->count());
    }

    private ?int $raceUnitId = null;

    private function emptyCounter(): StockLocation
    {
        $unit = app(BusinessUnitService::class)->save(['branch_id' => $this->counter->branch_id, 'name' => 'Race '.Str::random(8), 'code' => 'RACE_'.strtoupper(Str::random(8)), 'active' => true, 'advanced_locations_enabled' => true, 'max_active_cashiers' => 2]);
        app(BusinessUnitService::class)->product(['business_unit_id' => $unit->id, 'product_id' => $this->piece->product_id, 'available' => true, 'prices' => []]);
        $this->raceUnitId = $unit->id;
        $counter = StockLocation::findOrFail($unit->default_stock_location_id);
        $counter->update(['name' => 'Race counter', 'max_active_cashiers' => 1]);

        return $counter;
    }

    private function freshCashier(): User
    {
        $user = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => $this->cashier->role_id]);
        $user->branches()->attach($this->counter->branch_id, ['company_id' => $user->company_id]);

        app(BusinessUnitService::class)->assign($user, array_values(array_unique(array_filter([$this->counter->business_unit_id, $this->raceUnitId]))));

        return $user;
    }

    public function test_manager_and_cashier_racing_open_create_only_one_counter_operation(): void
    {
        $counter = $this->emptyCounter();
        $a = $this->freshCashier();
        $b = $this->freshCashier();
        $results = $this->race([
            ['action' => 'shift-open', 'user_id' => $a->id, 'payload' => ['type' => 'CASHIER', 'stock_location_id' => $counter->id]],
            ['action' => 'shift-open', 'user_id' => $this->owner->id, 'payload' => ['user_id' => $b->id, 'type' => 'CASHIER', 'stock_location_id' => $counter->id]],
        ]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame(1, Shift::where('stock_location_id', $counter->id)->where('status', 'OPEN')->count());
    }

    public function test_new_cashier_cannot_overlap_a_racing_final_close(): void
    {
        $counter = $this->emptyCounter();
        $a = $this->freshCashier();
        $b = $this->freshCashier();
        $shift = app(ShiftService::class)->open(['user_id' => $a->id, 'type' => 'CASHIER', 'stock_location_id' => $counter->id]);
        $service = app(ShiftStockService::class);
        $r = $service->report($shift->id);
        $service->count($shift->id, ['actual_cash' => app(ShiftService::class)->expected($shift), 'basis' => $r['basis'], 'revision' => 0, 'counts' => array_map(fn ($row) => ['product_id' => $row['product_id'], 'quantity' => $row['expected']], $r['rows'])]);
        $results = $this->race([
            ['action' => 'recon-close', 'user_id' => $a->id, 'shift_id' => $shift->id, 'actual_cash' => '0', 'key' => (string) Str::uuid()],
            ['action' => 'shift-open', 'user_id' => $b->id, 'payload' => ['type' => 'CASHIER', 'stock_location_id' => $counter->id]],
        ]);
        $this->assertSame(200, $results[0]['code']);
        $this->assertContains($results[1]['code'], [200, 422]);
        $this->assertSame('CLOSED', $shift->fresh()->status);
        $this->assertLessThanOrEqual(1, Shift::where('stock_location_id', $counter->id)->where('status', 'OPEN')->count());
        if ($results[1]['code'] === 200) {
            $this->assertTrue(Shift::findOrFail($results[1]['id'])->opened_at->greaterThanOrEqualTo($shift->fresh()->closed_at));
        }
    }

    public function test_waiter_settlement_racing_close_never_closes_with_debt(): void
    {
        $james = User::where('email', 'james@nightshift.test')->firstOrFail();
        $shift = Shift::where('user_id', $james->id)->where('status', 'OPEN')->firstOrFail();
        $balance = app(WaiterLedgerService::class)->balance($james->id);
        $results = $this->race([
            ['action' => 'waiter-close', 'user_id' => $james->id, 'shift_id' => $shift->id],
            ['action' => 'waiter-payment', 'user_id' => $this->cashier->id, 'key' => (string) Str::uuid(), 'payload' => ['waiter_id' => $james->id, 'stock_location_id' => $this->counter->id, 'amount' => $balance]],
        ]);
        $this->assertSame(200, $results[1]['code']);
        $this->assertContains($results[0]['code'], [200, 422]);
        $this->assertSame('0.00', app(WaiterLedgerService::class)->balance($james->id));
        if ($results[0]['code'] === 200) {
            $this->assertSame('CLOSED', $shift->fresh()->status);
        }
    }

    public function test_waiter_order_racing_waiter_close_cannot_issue_into_closed_session(): void
    {
        $waiter = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => $this->waiter->role_id]);
        $waiter->branches()->attach($this->counter->branch_id, ['company_id' => $waiter->company_id]);
        app(StaffPinService::class)->set($waiter->id, '0123');
        $shift = app(ShiftService::class)->open(['user_id' => $waiter->id, 'type' => 'WAITER', 'stock_location_id' => $this->counter->id]);
        $results = $this->race([
            ['action' => 'waiter-close', 'user_id' => $waiter->id, 'shift_id' => $shift->id],
            ['action' => 'send', 'user_id' => $waiter->id, 'key' => (string) Str::uuid(), 'payload' => ['waiter_id' => $waiter->id, 'pin' => '0123', 'stock_location_id' => $this->counter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]],
        ]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        if ($shift->fresh()->status === 'CLOSED') {
            $this->assertSame('0.00', app(WaiterLedgerService::class)->balance($waiter->id));
        } else {
            $this->assertSame('3000.00', app(WaiterLedgerService::class)->balance($waiter->id));
        }
    }

    public function test_waiter_order_racing_cashier_close_keeps_counter_operation_open(): void
    {
        app(StaffPinService::class)->set($this->waiter->id, '0123');
        $shift = Shift::where('user_id', $this->cashier->id)->where('type', 'CASHIER')->firstOrFail();
        $results = $this->race([
            ['action' => 'recon-close', 'user_id' => $this->cashier->id, 'shift_id' => $shift->id, 'actual_cash' => '320000', 'key' => (string) Str::uuid()],
            ['action' => 'send', 'user_id' => $this->waiter->id, 'key' => (string) Str::uuid(), 'payload' => ['waiter_id' => $this->waiter->id, 'pin' => '0123', 'stock_location_id' => $this->counter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]],
        ]);
        $this->assertSame([422, 200], array_column($results, 'code'));
        $this->assertSame('OPEN', $shift->fresh()->status);
        $this->assertSame($shift->id, Order::findOrFail($results[1]['id'])->issued_shift_id);
    }

    public function test_distinct_close_requests_cannot_finalize_twice(): void
    {
        $shift = $this->prepareReconciliation();
        $request = ['action' => 'recon-close', 'user_id' => $this->cashier->id, 'shift_id' => $shift->id, 'actual_cash' => app(ShiftService::class)->expected($shift)];
        $results = $this->race([
            [...$request, 'key' => (string) Str::uuid()],
            [...$request, 'key' => (string) Str::uuid()],
        ]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame(1, ShiftStockSnapshot::where('shift_id', $shift->id)->where('kind', 'CLOSING')->count());
    }

    private function unreceivedTreasuryHandover(): array
    {
        $shift = $this->prepareReconciliation(false);
        $h = app(CashHandoverService::class)->stage($shift->id, ['destination' => 'TREASURY', 'to_user_id' => $this->owner->id, 'amount' => app(ShiftService::class)->expected($shift)]);

        return [$shift, $h];
    }

    private function fundedTreasury(): string
    {
        $shift = $this->prepareReconciliation();
        $amount = app(ShiftService::class)->expected($shift);
        app(ShiftService::class)->close($shift->id, ['actual_cash' => $amount]);

        return $amount;
    }

    private function spendPayload(string $amount, string $reference): array
    {
        return ['transaction_type' => 'EXPENSE', 'branch_id' => $this->counter->branch_id, 'amount' => $amount, 'category' => 'Transport', 'description' => 'Concurrent authorized cash use', 'external_reference' => $reference, 'occurred_at' => now()->format('Y-m-d\TH:i')];
    }

    public function test_same_handover_cannot_be_received_twice(): void
    {
        [$shift, $h] = $this->unreceivedTreasuryHandover();
        $request = ['action' => 'cash-receive', 'user_id' => $this->owner->id, 'payload' => ['id' => $h->id, 'amount' => $h->amount]];
        $results = $this->race([[...$request, 'key' => (string) Str::uuid()], [...$request, 'key' => (string) Str::uuid()]]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame(1, CashHandoverEvent::where('handover_id', $h->id)->where('status', 'RECEIVED')->count());
        app(ShiftService::class)->close($shift->id, ['actual_cash' => $h->amount]);
        $this->assertSame(1, TreasuryEntry::where('handover_id', $h->id)->count());
    }

    public function test_retrying_the_same_receipt_key_reuses_the_result(): void
    {
        [$shift, $h] = $this->unreceivedTreasuryHandover();
        $request = ['action' => 'cash-receive', 'user_id' => $this->owner->id, 'payload' => ['id' => $h->id, 'amount' => $h->amount], 'key' => (string) Str::uuid()];
        $results = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame(1, CashHandoverEvent::where('handover_id', $h->id)->where('status', 'RECEIVED')->count());
    }

    public function test_two_users_cannot_take_receipt_of_the_same_cash(): void
    {
        [$shift, $h] = $this->unreceivedTreasuryHandover();
        $other = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => Role::where('name', 'Accountant')->firstOrFail()->id]);
        $other->branches()->attach($this->counter->branch_id, ['company_id' => $other->company_id]);
        $request = ['action' => 'cash-receive', 'payload' => ['id' => $h->id, 'amount' => $h->amount]];
        $results = $this->race([[...$request, 'user_id' => $this->owner->id, 'key' => (string) Str::uuid()], [...$request, 'user_id' => $other->id, 'key' => (string) Str::uuid()]]);
        $this->assertSame([200, 403], array_column($results, 'code'));
        $this->assertSame(1, CashHandoverEvent::where('handover_id', $h->id)->where('status', 'RECEIVED')->count());
    }

    public function test_two_treasury_spends_cannot_overdraw_available_cash(): void
    {
        $balance = $this->fundedTreasury();
        $request = ['action' => 'treasury-spend', 'user_id' => $this->owner->id];
        $results = $this->race([
            [...$request, 'key' => (string) Str::uuid(), 'payload' => $this->spendPayload('200000', 'RACE-EXPENSE-A')],
            [...$request, 'key' => (string) Str::uuid(), 'payload' => $this->spendPayload('200000', 'RACE-EXPENSE-B')],
        ]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame(bcsub($balance, '200000', 2), app(TreasuryService::class)->balance($this->owner->id, $this->counter->branch_id));
    }

    public function test_duplicate_expense_business_reference_posts_once(): void
    {
        $this->fundedTreasury();
        $request = ['action' => 'treasury-spend', 'user_id' => $this->owner->id, 'payload' => $this->spendPayload('10000', 'ONE-BILL')];
        $results = $this->race([[...$request, 'key' => (string) Str::uuid()], [...$request, 'key' => (string) Str::uuid()]]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame(1, TreasuryEntry::where('transaction_type', 'EXPENSE')->count());
    }

    public function test_duplicate_purchase_payments_with_different_references_cannot_overpay(): void
    {
        $this->fundedTreasury();
        $grn = app(GoodsReceiptService::class)->post(['stock_location_id' => $this->counter->id, 'supplier_id' => Supplier::firstOrFail()->id, 'supplier_invoice' => 'TREASURY-RACE-GRN', 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1', 'unit_cost' => '100000']]]);
        $payload = [...$this->spendPayload('100000', 'PURCHASE-A'), 'transaction_type' => 'PURCHASE', 'goods_receipt_id' => $grn->id];
        $request = ['action' => 'treasury-spend', 'user_id' => $this->owner->id];
        $results = $this->race([[...$request, 'key' => (string) Str::uuid(), 'payload' => $payload], [...$request, 'key' => (string) Str::uuid(), 'payload' => [...$payload, 'external_reference' => 'PURCHASE-B']]]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame(1, TreasuryEntry::where('goods_receipt_id', $grn->id)->count());
    }

    public function test_receiving_cashier_open_race_applies_handover_exactly_once(): void
    {
        $shift = $this->prepareReconciliation(false);
        $cashier = $this->freshCashier();
        $h = app(CashHandoverService::class)->stage($shift->id, ['destination' => 'NEXT_CASHIER', 'to_user_id' => $cashier->id, 'amount' => app(ShiftService::class)->expected($shift)]);
        app(ShiftService::class)->close($shift->id, ['actual_cash' => $h->amount]);
        $request = ['action' => 'shift-open', 'user_id' => $cashier->id, 'payload' => ['type' => 'CASHIER', 'stock_location_id' => $this->counter->id, 'handover_id' => $h->id]];
        $results = $this->race([$request, $request]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame(1, Shift::where('user_id', $cashier->id)->count());
        $this->assertSame($h->amount, Shift::where('user_id', $cashier->id)->firstOrFail()->opening_cash);
        $this->assertSame(1, CashHandoverEvent::where('handover_id', $h->id)->where('status', 'OPENED')->count());
    }

    private function newUnit(): BusinessUnit
    {
        return app(BusinessUnitService::class)->save(['branch_id' => $this->counter->branch_id, 'name' => 'Kitchen', 'code' => 'KITCHEN', 'active' => true]);
    }

    public function test_concurrent_unit_assignments_are_complete_sets(): void
    {
        $unit = $this->newUnit();
        $general = $this->counter->business_unit_id;
        $user = $this->freshCashier();
        $request = ['action' => 'unit-command', 'method' => 'assign', 'user_id' => $this->owner->id];
        $results = $this->race([[...$request, 'payload' => ['user_id' => $user->id, 'unit_ids' => [$general]]], [...$request, 'payload' => ['user_id' => $user->id, 'unit_ids' => [$unit->id]]]]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame(1, DB::table('business_unit_user')->where('user_id', $user->id)->count());
    }

    public function test_price_configuration_and_pos_send_use_one_serialized_price(): void
    {
        app(StaffPinService::class)->set($this->waiter->id, '0123');
        $results = $this->race([
            ['action' => 'unit-command', 'method' => 'product', 'user_id' => $this->owner->id, 'payload' => ['business_unit_id' => $this->counter->business_unit_id, 'product_id' => $this->piece->product_id, 'available' => true, 'prices' => [['product_unit_id' => $this->piece->id, 'selling_price' => '4500']]]],
            ['action' => 'send', 'user_id' => $this->waiter->id, 'key' => (string) Str::uuid(), 'payload' => ['waiter_id' => $this->waiter->id, 'pin' => '0123', 'stock_location_id' => $this->counter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]],
        ]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $order = Order::findOrFail($results[1]['id']);
        $this->assertContains($order->total, ['3000.00', '4500.00']);
        $this->assertSame($order->total, $order->items()->firstOrFail()->unit_price);
    }

    public function test_unit_deactivation_racing_shift_open_never_leaves_open_inactive_unit(): void
    {
        $unit = $this->newUnit();
        $counter = StockLocation::create(['branch_id' => $unit->branch_id, 'business_unit_id' => $unit->id, 'name' => 'Kitchen counter', 'type' => 'COUNTER']);
        $employee = $this->freshCashier();
        app(TransactionService::class)->run(fn () => app(BusinessUnitService::class)->assign($employee, [$unit->id]));
        $request = ['action' => 'unit-command', 'method' => 'save', 'user_id' => $this->owner->id, 'payload' => ['id' => $unit->id, 'branch_id' => $unit->branch_id, 'name' => $unit->name, 'code' => $unit->code, 'active' => false]];
        $results = $this->race([$request, ['action' => 'shift-open', 'user_id' => $this->owner->id, 'payload' => ['type' => 'CASHIER', 'stock_location_id' => $counter->id, 'user_id' => $employee->id]]]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertFalse(! $unit->fresh()->active && Shift::where('stock_location_id', $counter->id)->where('status', 'OPEN')->exists());
    }

    public function test_counter_reassignment_racing_shift_open_keeps_snapshot_consistent(): void
    {
        $unit = $this->newUnit();
        $counter = StockLocation::create(['branch_id' => $unit->branch_id, 'name' => 'Race counter', 'type' => 'COUNTER']);
        $results = $this->race([
            ['action' => 'unit-command', 'method' => 'location', 'user_id' => $this->owner->id, 'payload' => ['id' => $counter->id, 'unit_ids' => [$unit->id], 'is_shared' => false]],
            ['action' => 'shift-open', 'user_id' => $this->owner->id, 'payload' => ['type' => 'CASHIER', 'stock_location_id' => $counter->id, 'user_id' => $this->freshCashier()->id]],
        ]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $shift = Shift::where('stock_location_id', $counter->id)->where('status', 'OPEN')->first();
        if ($shift) {
            $this->assertSame($counter->fresh()->business_unit_id, $shift->business_unit_id);
        }
    }

    public function test_cross_unit_transfers_racing_cannot_overspend_source_stock(): void
    {
        $unit = $this->newUnit();
        $source = StockLocation::create(['branch_id' => $unit->branch_id, 'name' => 'Shared race store', 'type' => 'STORE']);
        $destination = StockLocation::create(['branch_id' => $unit->branch_id, 'business_unit_id' => $unit->id, 'name' => 'Kitchen store', 'type' => 'STORE']);
        app(TransactionService::class)->run(fn () => app(StockService::class)->change($source->id, $this->piece->product_id, '2', 'GRN', 'Validation', 1));
        $request = ['action' => 'transfer-post', 'user_id' => $this->owner->id, 'payload' => ['source_location_id' => $source->id, 'destination_location_id' => $destination->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '2']]]];
        $results = $this->race([$request, $request]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame('0.000000', Stock::where('stock_location_id', $source->id)->where('product_id', $this->piece->product_id)->firstOrFail()->quantity);
        $this->assertSame('2.000000', Stock::where('stock_location_id', $destination->id)->where('product_id', $this->piece->product_id)->firstOrFail()->quantity);
    }

    public function test_availability_change_racing_pos_send_never_partially_issues(): void
    {
        app(StaffPinService::class)->set($this->waiter->id, '0123');
        $before = $this->quantity();
        $results = $this->race([
            ['action' => 'unit-command', 'method' => 'product', 'user_id' => $this->owner->id, 'payload' => ['business_unit_id' => $this->counter->business_unit_id, 'product_id' => $this->piece->product_id, 'available' => false, 'prices' => []]],
            ['action' => 'send', 'user_id' => $this->waiter->id, 'key' => (string) Str::uuid(), 'payload' => ['waiter_id' => $this->waiter->id, 'pin' => '0123', 'stock_location_id' => $this->counter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]],
        ]);
        $this->assertSame(200, $results[0]['code']);
        $this->assertContains($results[1]['code'], [200, 422]);
        $this->assertSame($results[1]['code'] === 200 ? bcsub($before, '1', 6) : $before, $this->quantity());
    }

    public function test_terminal_deactivation_racing_pos_send_rechecks_actor_after_the_lock(): void
    {
        app(StaffPinService::class)->set($this->waiter->id, '0123');
        $terminal = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => Role::where('name', 'POS Terminal')->firstOrFail()->id, 'terminal_stock_location_id' => $this->counter->id, 'active' => true]);
        $before = $this->quantity();
        $results = $this->race([
            ['action' => 'unit-command', 'method' => 'status', 'user_id' => $this->owner->id, 'payload' => ['user_id' => $terminal->id, 'active' => false]],
            ['action' => 'unit-pos-send', 'user_id' => $terminal->id, 'key' => (string) Str::uuid(), 'payload' => ['waiter_id' => $this->waiter->id, 'pin' => '0123', 'stock_location_id' => $this->counter->id, 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]],
        ]);
        $this->assertSame(200, $results[0]['code']);
        $this->assertContains($results[1]['code'], [200, 403]);
        $this->assertFalse((bool) $terminal->fresh()->active);
        $this->assertSame($results[1]['code'] === 200 ? bcsub($before, '1', 6) : $before, $this->quantity());
        $this->assertSame($results[1]['code'] === 200 ? 1 : 0, Order::where('created_by', $terminal->id)->count());
    }

    public function test_unit_cashier_capacity_one_serializes_simultaneous_opens(): void
    {
        $this->raceUnitCapacity(1);
    }

    public function test_unit_cashier_capacity_two_serializes_three_simultaneous_opens(): void
    {
        $this->raceUnitCapacity(2);
    }

    private function raceUnitCapacity(int $capacity): void
    {
        $unit = app(BusinessUnitService::class)->save(['branch_id' => $this->counter->branch_id, 'name' => 'Unit race', 'code' => 'UNIT_RACE', 'active' => true, 'max_active_cashiers' => $capacity]);
        $requests = [];
        for ($i = 0; $i <= $capacity; $i++) {
            $user = $this->freshCashier();
            app(BusinessUnitService::class)->assign($user, [$unit->id]);
            $requests[] = ['action' => 'shift-open', 'user_id' => $user->id, 'payload' => ['type' => 'CASHIER', 'business_unit_id' => $unit->id]];
        }
        $results = $this->race($requests);
        $this->assertEqualsCanonicalizing([...array_fill(0, $capacity, 200), 422], array_column($results, 'code'));
        $this->assertSame($capacity, app(UnitOperationalService::class)->activeCashiers($unit->id));
        $this->assertSame($capacity, ShiftStockSnapshot::where('stock_location_id', $unit->default_stock_location_id)->where('kind', 'OPENING')->count());
    }

    public function test_cashier_limit_two_allows_exactly_two_of_three_simultaneous_opens(): void
    {
        $counter = $this->emptyCounter();
        app(CounterCashierService::class)->update($counter->id, 2);
        $requests = [];
        foreach ([$this->freshCashier(), $this->freshCashier(), $this->freshCashier()] as $user) {
            $requests[] = ['action' => 'shift-open', 'user_id' => $user->id, 'payload' => ['type' => 'CASHIER', 'stock_location_id' => $counter->id]];
        }
        $results = $this->race($requests);
        $this->assertEqualsCanonicalizing([200, 200, 422], array_column($results, 'code'));
        $this->assertSame(2, $counter->active_cashiers);
        $this->assertSame(2, ShiftStockSnapshot::where('stock_location_id', $counter->id)->where('kind', 'OPENING')->count());
    }

    public function test_cashier_limit_reduction_and_open_are_serialized(): void
    {
        $counter = $this->emptyCounter();
        app(CounterCashierService::class)->update($counter->id, 2);
        $a = $this->freshCashier();
        $b = $this->freshCashier();
        app(ShiftService::class)->open(['user_id' => $a->id, 'type' => 'CASHIER', 'stock_location_id' => $counter->id]);
        $results = $this->race([
            ['action' => 'cashier-limit', 'user_id' => $this->owner->id, 'counter_id' => $counter->id, 'limit' => 1],
            ['action' => 'shift-open', 'user_id' => $b->id, 'payload' => ['type' => 'CASHIER', 'stock_location_id' => $counter->id]],
        ]);
        $this->assertEqualsCanonicalizing([200, 422], array_column($results, 'code'));
        $this->assertSame($counter->fresh()->max_active_cashiers, $counter->active_cashiers);
    }

    public function test_cashier_limit_two_close_open_race_never_exceeds_capacity(): void
    {
        $counter = $this->emptyCounter();
        app(CounterCashierService::class)->update($counter->id, 2);
        $a = $this->freshCashier();
        $b = $this->freshCashier();
        $c = $this->freshCashier();
        app(ShiftService::class)->open(['user_id' => $a->id, 'type' => 'CASHIER', 'stock_location_id' => $counter->id]);
        $shift = app(ShiftService::class)->open(['user_id' => $b->id, 'type' => 'CASHIER', 'stock_location_id' => $counter->id]);
        $r = app(ShiftStockService::class)->report($shift->id);
        app(ShiftStockService::class)->count($shift->id, ['actual_cash' => '0', 'basis' => $r['basis'], 'revision' => 0, 'counts' => array_map(fn ($row) => ['product_id' => $row['product_id'], 'quantity' => $row['expected']], $r['rows'])]);
        $results = $this->race([
            ['action' => 'recon-close', 'user_id' => $b->id, 'shift_id' => $shift->id, 'actual_cash' => '0', 'key' => (string) Str::uuid()],
            ['action' => 'shift-open', 'user_id' => $c->id, 'payload' => ['type' => 'CASHIER', 'stock_location_id' => $counter->id]],
        ]);
        $this->assertSame(200, $results[0]['code']);
        $this->assertContains($results[1]['code'], [200, 422]);
        $this->assertSame('CLOSED', $shift->fresh()->status);
        $this->assertSame($results[1]['code'] === 200 ? 2 : 1, $counter->active_cashiers);
        if ($results[1]['code'] === 200) {
            $this->assertTrue(Shift::findOrFail($results[1]['id'])->opened_at->greaterThanOrEqualTo($shift->fresh()->closed_at));
        }
    }

    public function test_concurrent_schedule_ticks_create_one_outbox_delivery(): void
    {
        $settings = app(SettingsService::class)->current();
        $settings->update(['device_id' => 'concurrent-company-'.$this->owner->company_id, 'options' => [...$settings->preferences(), 'enabled' => true]]);
        $recipient = app(RecipientService::class)->save(['name' => 'Concurrency owner', 'phone' => '0712345678', 'all_branches' => true, 'branch_ids' => [], 'daily_report' => true, 'shift_reports' => true, 'critical_alerts' => true, 'active' => true]);
        $schedule = app(ScheduleService::class)->save(['name' => 'Concurrent daily', 'report_type' => 'DAILY', 'enabled' => true, 'send_time' => '06:00', 'timezone' => 'Africa/Dar_es_Salaam', 'cutoff_time' => '05:00', 'weekday' => 1, 'month_day' => 1, 'month_end' => false, 'grace_minutes' => 60, 'format' => 'TEXT', 'language' => 'en', 'scope' => 'COMPANY', 'branch_id' => null, 'business_unit_id' => null, 'stock_location_id' => null, 'per_branch' => false, 'unit_breakdown' => true, 'recipient_ids' => [$recipient->id]]);
        $request = ['action' => 'whatsapp-schedule', 'user_id' => $this->owner->id, 'schedule_id' => $schedule->id, 'period' => ['period_start' => '2026-11-10T05:00:00+03:00', 'period_end' => '2026-11-11T05:00:00+03:00', 'scheduled_at' => '2026-11-11T06:00:00+03:00']];
        $results = $this->race([$request, $request]);
        $this->assertSame([200, 200], array_column($results, 'code'));
        $this->assertSame(1, WhatsAppDeliveryLog::where('schedule_id', $schedule->id)->count());
    }

    public function test_concurrent_delivery_workers_only_send_one_mocked_message(): void
    {
        $settings = app(SettingsService::class)->current();
        $settings->update(['device_id' => 'concurrent-delivery-'.$this->owner->company_id, 'options' => [...$settings->preferences(), 'enabled' => true]]);
        $recipient = app(RecipientService::class)->save(['name' => 'Delivery owner', 'phone' => '0712345678', 'all_branches' => true, 'branch_ids' => [], 'daily_report' => true, 'shift_reports' => true, 'critical_alerts' => true, 'active' => true]);
        $log = app(Outbox::class)->enqueue($recipient, 'TEST', 'concurrent-test', [$this->counter->branch_id], message: 'Mock concurrent delivery');
        $path = tempnam('/tmp', 'nightshift-wa-send-');
        try {
            $request = ['action' => 'whatsapp-deliver', 'user_id' => $this->owner->id, 'company_id' => $this->owner->company_id, 'delivery_id' => $log->id, 'record_file' => $path];
            $results = $this->race([$request, $request]);
            $this->assertSame([200, 200], array_column($results, 'code'));
            $this->assertSame("sent\n", file_get_contents($path));
            $this->assertSame('SENT', $log->fresh()->status);
            $this->assertSame(1, $log->fresh()->attempt_count);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
