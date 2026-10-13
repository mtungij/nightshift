<?php

namespace Tests\MySql;

use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\ProductUnit;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\AdjustmentService;
use App\Services\OrderService;
use App\Services\StaffPinService;
use App\Services\WaiterLedgerService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Committed fixtures allow independent PHP processes to contend for real InnoDB locks. */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;

    private User $cashier;

    private User $waiter;

    private StockLocation $counter;

    private ProductUnit $piece;

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
}
