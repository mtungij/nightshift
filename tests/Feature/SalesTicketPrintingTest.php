<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ProductUnit;
use App\Models\SalesTicketBatch;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\OrderService;
use App\Services\SalesTicketService;
use App\Services\StaffPinService;
use App\Services\WaiterLedgerService;
use App\Support\QuantityFormatter;
use App\Support\TicketAmountFormatter;
use App\Support\UiText;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SalesTicketPrintingTest extends TestCase
{
    // Real commits are essential: RefreshDatabase would defer afterCommit until teardown.
    use DatabaseTruncation;

    private static bool $prepared = false;

    protected function beforeTruncatingDatabase(): void
    {
        if (! self::$prepared || DB::getDriverName() === 'sqlite') {
            RefreshDatabaseState::$migrated = false;
            self::$prepared = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    private User $owner;

    private User $waiter;

    private StockLocation $counter;

    private ProductUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->counter = StockLocation::where('name', 'Main Counter')->firstOrFail();
        $this->unit = ProductUnit::whereHas('product', fn ($q) => $q->where('sku', 'BR-001'))->whereHas('unit', fn ($q) => $q->where('name', 'Piece'))->firstOrFail();
        app(StaffPinService::class)->set($this->waiter->id, '0123');
    }

    private function payload(): array
    {
        return ['waiter_id' => $this->waiter->id, 'pin' => '0123', 'stock_location_id' => $this->counter->id, 'discount' => '0', 'items' => [['product_unit_id' => $this->unit->id, 'quantity' => '1']]];
    }

    private function command(string $command, array $data, ?string $key = null): TestResponse
    {
        return $this->postJson('/api/nightshift/commands/'.$command, [...$data, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    private function settings(array $overrides = []): array
    {
        return array_replace(app(SalesTicketService::class)->preferences(), $overrides);
    }

    private function accounting(): array
    {
        return [Stock::orderBy('id')->pluck('quantity')->all(), StockMovement::count(), WaiterLedgerEntry::count(), Payment::count(), PaymentAllocation::count(), Order::orderBy('id')->get()->toArray()];
    }

    public function test_committed_send_generates_two_labeled_80mm_copies_and_audit(): void
    {
        $response = $this->command('order-send', $this->payload())->assertOk()->assertJsonPath('auto_print', true)->assertJsonPath('copies', ['WAITER COPY', 'CASHIER COPY']);
        $batch = SalesTicketBatch::firstOrFail();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($response->json('id'), $batch->order_id);
        $this->assertSame(app(WaiterLedgerService::class)->visibleBalance($this->waiter->id), $batch->ticket['outstanding']);
        $page = $this->get($response->json('print_url'))->assertOk()->assertSee('WAITER COPY')->assertSee('CASHIER COPY')->assertSee('ISSUED / UNPAID')->assertSee('Subtotal')->assertSee('Discount')->assertSee('Waiter outstanding after this order')->assertSee($batch->ticket['outstanding'])->assertSee('size:80mm 200mm', false)->assertSee('background:#fff', false)->assertSee('break-before:page', false)->assertSee('Unit price')->assertSee('Line total');
        $this->assertSame(2, substr_count($page->getContent(), '<article class="ticket"'));
        $this->assertSame(1, substr_count($page->getContent(), 'Waiter outstanding after this order'));
        $events = AuditLog::where('action', 'SALES_TICKET_PRINT')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['WAITER COPY', 'CASHIER COPY'], $events->pluck('new_values.copy_type')->all());
        $this->assertSame($this->owner->id, $events->first()->user_id);
        $this->assertNotNull($events->first()->created_at);
        $this->assertSame((string) $batch->order_id, $events->first()->reference_id);
        $this->assertFalse($events->first()->new_values['reprint']);
    }

    public function test_print_preparation_runs_only_after_outermost_commit(): void
    {
        DB::beginTransaction();
        $this->command('order-send', $this->payload())->assertOk()->assertJsonPath('auto_print', false)->assertJsonPath('print_url', null);
        $this->assertSame(0, SalesTicketBatch::count());
        $this->assertSame(0, AuditLog::where('action', 'SALES_TICKET_PRINT')->count());
        DB::commit();
        $this->assertSame(1, SalesTicketBatch::count());
        $this->assertSame(2, AuditLog::where('action', 'SALES_TICKET_PRINT')->count());
    }

    public function test_outer_transaction_rollback_never_prepares_or_prints(): void
    {
        $before = $this->accounting();
        DB::beginTransaction();
        $this->command('order-send', $this->payload())->assertOk()->assertJsonPath('print_url', null);
        DB::rollBack();
        $this->assertSame(0, SalesTicketBatch::count());
        $this->assertSame($before, $this->accounting());
        $this->assertSame(0, AuditLog::where('action', 'SALES_TICKET_PRINT')->count());
    }

    public function test_invalid_pin_never_generates_print_data(): void
    {
        $before = $this->accounting();
        $this->command('order-send', [...$this->payload(), 'pin' => '9999'])->assertUnprocessable()->assertJsonMissingPath('print_url');
        $this->assertSame(0, SalesTicketBatch::count());
        $this->assertSame($before, $this->accounting());
    }

    public function test_insufficient_stock_never_generates_print_data(): void
    {
        $before = $this->accounting();
        $data = $this->payload();
        $data['items'][0]['quantity'] = '999999';
        $this->command('order-send', $data)->assertUnprocessable()->assertJsonMissingPath('print_url');
        $this->assertSame(0, SalesTicketBatch::count());
        $this->assertSame($before, $this->accounting());
    }

    public function test_duplicate_send_reuses_one_print_batch_and_one_issue(): void
    {
        $key = (string) Str::uuid();
        $first = $this->command('order-send', $this->payload(), $key)->assertOk();
        $before = $this->accounting();
        $second = $this->command('order-send', $this->payload(), $key)->assertOk();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame($before, $this->accounting());
        $this->assertSame(1, SalesTicketBatch::count());
        $this->assertSame(2, AuditLog::where('action', 'SALES_TICKET_PRINT')->count());
        $this->assertSame(1, WaiterLedgerEntry::where('order_id', $first->json('id'))->where('entry_type', 'ISSUE')->count());
        $this->command('order-send', [...$this->payload(), 'discount' => '1'], $key)->assertUnprocessable();
    }

    public function test_reprint_keeps_invoice_and_accounting_and_is_idempotent(): void
    {
        $original = $this->command('order-send', $this->payload())->assertOk();
        $before = $this->accounting();
        $key = (string) Str::uuid();
        $reprint = $this->command('ticket-reprint', ['id' => $original->json('id')], $key)->assertOk()->assertJsonPath('copies', ['WAITER COPY', 'CASHIER COPY']);
        $this->command('ticket-reprint', ['id' => $original->json('id')], $key)->assertExactJson($reprint->json());
        $this->assertSame($before, $this->accounting());
        $batch = SalesTicketBatch::where('reprint', true)->firstOrFail();
        $this->get($reprint->json('print_url'))->assertOk()->assertSee('REPRINT')->assertSee($batch->ticket['invoice']);
        $this->assertSame(SalesTicketBatch::where('reprint', false)->firstOrFail()->ticket, $batch->ticket);
        $this->assertSame(2, AuditLog::where('action', 'SALES_TICKET_REPRINT')->count());
    }

    public function test_ticket_read_is_read_only_and_does_not_repeat_audit(): void
    {
        $response = $this->command('order-send', $this->payload())->assertOk();
        $before = $this->accounting();
        $auditCount = AuditLog::count();
        $this->get($response->json('print_url').'?print=1')->assertOk()->assertSee('sessionStorage.getItem', false);
        $this->get($response->json('print_url').'?print=1')->assertOk();
        $this->assertSame($auditCount, AuditLog::count());
        $this->assertSame($before, $this->accounting());
    }

    public function test_print_failure_cannot_undo_or_report_failure_for_committed_sale(): void
    {
        $this->mock(SalesTicketService::class, function ($mock) {
            $mock->shouldReceive('response')->with(null)->andReturn(['auto_print' => false, 'print_url' => null, 'print_batch' => null, 'copies' => []]);
            $mock->shouldReceive('prepare')->once()->andReturnUsing(function () {
                $this->assertSame(0, DB::transactionLevel());
                throw new \RuntimeException('Printer preparation unavailable');
            });
        });
        $response = $this->command('order-send', $this->payload())->assertOk()->assertJsonPath('print_error', true)->assertJsonPath('status', 'ISSUED');
        $this->assertSame(1, WaiterLedgerEntry::where('order_id', $response->json('id'))->where('entry_type', 'ISSUE')->count());
        $this->assertSame(1, StockMovement::where('reference_type', 'Order')->where('reference_id', $response->json('id'))->where('movement_type', 'ISSUE')->count());
    }

    public function test_auto_print_off_allows_manual_reprint(): void
    {
        $this->command('print-settings', $this->settings(['auto_print' => false]))->assertOk();
        $send = $this->command('order-send', $this->payload())->assertOk()->assertJsonPath('auto_print', false)->assertJsonPath('copies', []);
        $this->assertSame(0, SalesTicketBatch::count());
        $this->command('ticket-reprint', ['id' => $send->json('id')])->assertOk()->assertJsonPath('auto_print', true);
    }

    public function test_copy_preferences_and_total_copy_count(): void
    {
        $this->command('print-settings', $this->settings(['waiter_copy' => false, 'number_of_copies' => 3]))->assertOk();
        $this->command('order-send', $this->payload())->assertOk()->assertJsonPath('copies', ['CASHIER COPY', 'CASHIER COPY', 'CASHIER COPY']);
        $this->command('print-settings', $this->settings(['waiter_copy' => false, 'cashier_copy' => false]))->assertOk();
        $this->command('order-send', $this->payload())->assertOk()->assertJsonPath('auto_print', false)->assertJsonPath('copies', []);
    }

    public function test_settings_are_company_scoped_and_require_permission(): void
    {
        $company = $this->owner->company;
        $company->update(['settings' => ['unrelated' => ['keep' => true]]]);
        $this->command('print-settings', $this->settings(['auto_print' => false]))->assertOk();
        $this->assertTrue($company->fresh()->settings['unrelated']['keep']);
        $this->assertFalse($company->fresh()->settings['sales_print']['auto_print']);
        $foreign = User::factory()->create();
        $this->actingAs($foreign);
        $this->assertTrue(app(SalesTicketService::class)->preferences()['auto_print']);
        $this->command('print-settings', $this->settings(['number_of_copies' => 4]))->assertOk();
        $this->assertSame(2, $company->fresh()->settings['sales_print']['number_of_copies']);
        $this->actingAs($this->waiter);
        $this->command('print-settings', $this->settings())->assertForbidden();
    }

    public function test_cross_tenant_and_other_waiter_cannot_read_or_reprint_tickets(): void
    {
        $response = $this->command('order-send', $this->payload())->assertOk();
        $otherWaiter = User::where('email', 'james@nightshift.test')->firstOrFail();
        $this->actingAs($otherWaiter);
        $this->get($response->json('print_url'))->assertForbidden();
        $this->postJson($response->json('print_url').'/dispatch')->assertForbidden();
        $this->command('ticket-reprint', ['id' => $response->json('id')])->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->get($response->json('print_url'))->assertNotFound();
        $this->postJson($response->json('print_url').'/dispatch')->assertNotFound();
        $this->command('ticket-reprint', ['id' => $response->json('id')])->assertNotFound();
    }

    public function test_unissued_order_cannot_be_reprinted(): void
    {
        $data = $this->payload();
        unset($data['pin']);
        $order = app(OrderService::class)->create($data);
        $this->command('ticket-reprint', ['id' => $order->id])->assertStatus(409);
        $this->assertSame(0, SalesTicketBatch::count());
    }

    public function test_kiswahili_ticket_labels_and_reprint(): void
    {
        $this->owner->forceFill(['language_preference' => 'sw'])->save();
        $response = $this->command('order-send', $this->payload())->assertOk();
        $this->get($response->json('print_url'))->assertOk()->assertSee('NAKALA YA MHUDUMU')->assertSee('NAKALA YA KESHIA')->assertSee('HAIJALIPWA')->assertSee('JUMLA');
        $response = $this->command('ticket-reprint', ['id' => $response->json('id')])->assertOk();
        $this->get($response->json('print_url'))->assertOk()->assertSee('IMECHAPISHWA TENA');
    }

    public function test_dispatch_claim_prevents_duplicate_printing_across_tabs(): void
    {
        $send = $this->command('order-send', $this->payload())->assertOk();
        $before = $this->accounting();
        $url = $send->json('print_url').'/dispatch';
        $this->postJson($url)->assertOk()->assertJsonPath('print', true);
        $this->postJson($url)->assertOk()->assertJsonPath('print', false);
        $this->assertNotNull(SalesTicketBatch::firstOrFail()->dispatched_at);
        $this->assertSame(2, AuditLog::where('action', 'SALES_TICKET_DISPATCH')->count());
        $this->assertSame($before, $this->accounting());
    }

    public function test_reprint_after_payment_preserves_payment_and_historical_outstanding(): void
    {
        $send = $this->command('order-send', $this->payload())->assertOk();
        $original = SalesTicketBatch::firstOrFail();
        $this->command('payment', ['id' => $send->json('id'), 'lines' => [['method' => 'Cash', 'amount' => $original->ticket['total']]]])->assertOk();
        $before = $this->accounting();
        $response = $this->command('ticket-reprint', ['id' => $send->json('id')])->assertOk();
        $this->get($response->json('print_url'))->assertOk()->assertSee($original->ticket['outstanding'])->assertSee('REPRINT');
        $this->assertSame($before, $this->accounting());
        $this->assertSame('PAID', Order::findOrFail($send->json('id'))->status);
    }

    public function test_legacy_reprint_after_return_uses_original_issue_value(): void
    {
        $this->command('print-settings', $this->settings(['auto_print' => false]))->assertOk();
        $send = $this->command('order-send', $this->payload())->assertOk();
        $order = Order::findOrFail($send->json('id'));
        $total = $order->total;
        $this->command('return', ['id' => $order->id, 'reason' => 'Unopened bottle returned by guest', 'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => '1']]])->assertOk();
        $before = $this->accounting();
        $this->command('ticket-reprint', ['id' => $order->id])->assertOk();
        $batch = SalesTicketBatch::firstOrFail();
        $this->assertSame($total, $batch->ticket['total']);
        $this->assertSame('UNPAID', $batch->ticket['status']);
        $this->assertSame($before, $this->accounting());
    }

    public function test_58mm_settings_are_tenant_scoped_and_80mm_remains_the_default(): void
    {
        $this->assertSame('80mm', app(SalesTicketService::class)->preferences()['paper_width']);
        $this->command('print-settings', $this->settings(['paper_width' => '58mm']))->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonPath('printSettings.paper_width', '58mm');
        $this->assertSame('58mm', $this->owner->company->fresh()->settings['sales_print']['paper_width']);
        $this->actingAs(User::factory()->create());
        $this->assertSame('80mm', app(SalesTicketService::class)->preferences()['paper_width']);
    }

    public function test_58mm_renders_two_stacked_copies_with_readable_fractional_quantities(): void
    {
        $this->command('print-settings', $this->settings(['paper_width' => '58mm']))->assertOk();
        $name = 'Extra long product name for a narrow thermal printer / '.str_repeat('Serengeti', 8);
        $this->unit->product->update(['name' => $name]);
        $data = $this->payload();
        $data['items'] = array_map(fn ($quantity) => ['product_unit_id' => $this->unit->id, 'quantity' => $quantity], ['4.0000', '1.5', '0.75']);
        $send = $this->command('order-send', $data)->assertOk()->assertJsonPath('copies', ['WAITER COPY', 'CASHIER COPY']);
        $before = $this->accounting();
        $page = $this->get($send->json('print_url'))->assertOk()->assertSee('data-paper-width="58mm"', false)->assertSee('size:58mm 200mm;margin:0', false)->assertSee('width:48mm', false)->assertSee($name)->assertSee('4 × Piece')->assertSee('1.5 × Piece')->assertSee('0.75 × Piece')->assertDontSee('4.0000 ×')->assertSee('Outstanding Balance')->assertSee('18,750.00')->assertSee('ISSUED / UNPAID')->assertDontSee('<table', false);
        $this->assertSame(2, substr_count($page->getContent(), '<article class="ticket"'));
        $this->assertSame(1, substr_count($page->getContent(), 'Outstanding Balance'));
        $this->assertSame($before, $this->accounting());
        $batch = SalesTicketBatch::firstOrFail();
        $this->assertSame('18,750.00', TicketAmountFormatter::format($batch->ticket['total']));
        $this->postJson($send->json('print_url').'/dispatch')->assertJsonPath('print', true);
        $this->postJson($send->json('print_url').'/dispatch')->assertJsonPath('print', false);
        $this->assertSame($before, $this->accounting());
    }

    public function test_reprint_uses_current_width_without_changing_batch_or_accounting_data(): void
    {
        $send = $this->command('order-send', $this->payload())->assertOk();
        $original = SalesTicketBatch::firstOrFail();
        $this->get($send->json('print_url'))->assertSee('data-paper-width="80mm"', false);
        $this->command('print-settings', $this->settings(['paper_width' => '58mm']))->assertOk();
        $before = $this->accounting();
        $response = $this->command('ticket-reprint', ['id' => $send->json('id')])->assertOk();
        $this->get($response->json('print_url'))->assertOk()->assertSee('data-paper-width="58mm"', false)->assertSee('REPRINT')->assertSee($original->ticket['invoice']);
        $reprint = SalesTicketBatch::where('reprint', true)->firstOrFail();
        $this->assertSame($original->ticket, $reprint->ticket);
        $this->assertSame($before, $this->accounting());
        $this->command('print-settings', $this->settings(['paper_width' => '80mm']))->assertOk();
        $this->get($response->json('print_url'))->assertOk()->assertSee('data-paper-width="80mm"', false)->assertSee('<table', false);
        $this->assertSame($before, $this->accounting());
        $this->assertSame($original->ticket, $original->fresh()->ticket);
    }

    public function test_58mm_kiswahili_labels_and_large_amounts(): void
    {
        $this->owner->forceFill(['language_preference' => 'sw'])->save();
        $this->command('print-settings', $this->settings(['paper_width' => '58mm']))->assertOk();
        $send = $this->command('order-send', $this->payload())->assertOk();
        $this->get($send->json('print_url'))->assertOk()->assertSee('NAKALA YA MHUDUMU')->assertSee('NAKALA YA KESHIA')->assertSee('HAIJALIPWA')->assertSee('JUMLA')->assertSee('Salio Linalodaiwa');
        $this->assertSame('Upana wa Karatasi', UiText::get('Paper Width'));
        $this->assertSame('9,999,999,999.99', TicketAmountFormatter::format('9999999999.99'));
        $this->assertSame('0.75', QuantityFormatter::format('0.750000'));
        $this->assertSame('4', QuantityFormatter::format('4.000000'));
    }

    public function test_invalid_print_settings_are_rejected(): void
    {
        foreach ([['number_of_copies' => 1], ['number_of_copies' => 0], ['number_of_copies' => 11], ['paper_width' => 'A4'], ['auto_print' => 'invalid']] as $invalid) {
            $this->command('print-settings', $this->settings($invalid))->assertUnprocessable();
        }
    }
}
