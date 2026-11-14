<?php

namespace Tests\Feature;

use App\Contracts\ReportPdfRenderer;
use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Permission;
use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppReportSchedule;
use App\Services\AuditService;
use App\Services\OperationalPeriodReportService;
use App\Services\ReportService;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\Outbox;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\ReportBuilder;
use App\Services\WhatsApp\Scheduler;
use App\Services\WhatsApp\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppSchedulesTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 18:00:00', 'Africa/Dar_es_Salaam'));
        $this->setupWhatsApp();
    }

    private function schedule(array $extra = []): WhatsAppReportSchedule
    {
        return app(ScheduleService::class)->save(['name' => 'Daily owner', 'report_type' => 'DAILY', 'enabled' => true, 'send_time' => '06:00', 'timezone' => 'Africa/Dar_es_Salaam', 'cutoff_time' => '05:00', 'weekday' => 1, 'month_day' => 1, 'month_end' => false, 'grace_minutes' => 60, 'format' => 'TEXT', 'language' => 'en', 'scope' => 'COMPANY', 'branch_id' => null, 'business_unit_id' => null, 'stock_location_id' => null, 'per_branch' => false, 'unit_breakdown' => true, 'recipient_ids' => [$this->recipient->id], ...$extra]);
    }

    private function period(): array
    {
        return ['period_start' => '2026-11-10T05:00:00+03:00', 'period_end' => '2026-11-11T05:00:00+03:00', 'scheduled_at' => '2026-11-11T06:00:00+03:00', 'timezone' => 'Africa/Dar_es_Salaam'];
    }

    private function send(WhatsAppDeliveryLog $log): void
    {
        (new SendWhatsAppMessageJob($this->owner->company_id, $log->id))->handle(app(CompanyContext::class));
    }

    public function test_manual_device_replacement_preserves_schedules_and_uses_new_device_for_new_deliveries(): void
    {
        $schedule = $this->schedule();
        $original = $schedule->fresh()->getAttributes();
        $old = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'before-device-change', [$this->branch->id], message: 'Old device');
        Http::fake([
            'notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['is_connected' => true, 'is_logged_in' => true]]),
            'notify.buildcore.site/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'MANUAL-DEVICE-SENT']]),
        ]);
        $this->postJson('/settings/whatsapp/connection/device', ['device_id' => 'externally-linked-device'])->assertOk()->assertJsonPath('status', 'CONNECTED');
        $this->assertSame($original, $schedule->fresh()->getAttributes());
        $this->settings->fresh()->update(['provisioned' => false]); // Legacy flag never gates delivery.
        $this->send($old);
        $this->assertSame('SKIPPED', $old->fresh()->status);
        $new = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'after-device-change', [$this->branch->id], message: 'New device');
        $this->send($new);
        $this->assertSame('SENT', $new->fresh()->status);
        $this->assertSame('externally-linked-device', $new->fresh()->device_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/send/message') && $r->hasHeader('X-Device-Id', 'externally-linked-device'));
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->owner->company_id, 'action' => 'WHATSAPP_DEVICE_UPDATED']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->owner->company_id, 'action' => 'WHATSAPP_CONNECTION_STATUS_CHANGED']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/devices'));
    }

    public function test_daily_send_time_grace_and_stable_period_deduplication(): void
    {
        $schedule = $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 05:59:00', 'Africa/Dar_es_Salaam'));
        app(Scheduler::class)->tick();
        $this->assertSame(0, WhatsAppDeliveryLog::count());
        $this->travel(6)->minutes();
        app(Scheduler::class)->tick();
        app(Scheduler::class)->tick();
        $log = WhatsAppDeliveryLog::firstOrFail();
        $this->assertSame($schedule->id, $log->schedule_id);
        $this->assertSame('2026-11-10T05:00:00+03:00', $log->payload['period_start']);
        $this->assertSame(1, WhatsAppDeliveryLog::count());
        $schedule->update(['send_time' => '06:10', 'per_branch' => true]);
        $this->travel(5)->minutes();
        app(Scheduler::class)->tick();
        $this->assertSame(1, WhatsAppDeliveryLog::count());
        Http::assertNothingSent();
    }

    public function test_stale_schedule_is_not_replayed_indefinitely(): void
    {
        $schedule = $this->schedule();
        $this->assertNull(app(ScheduleService::class)->due($schedule, CarbonImmutable::parse('2026-11-11 08:00:00', 'Africa/Dar_es_Salaam')));
        app(ScheduleService::class)->enqueue($schedule, $this->period());
        $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'Africa/Dar_es_Salaam'));
        $log = WhatsAppDeliveryLog::firstOrFail();
        $this->send($log);
        $this->assertSame('SKIPPED', $log->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_grace_crosses_midnight_in_company_timezone(): void
    {
        $schedule = $this->schedule(['send_time' => '23:50']);
        $due = app(ScheduleService::class)->due($schedule, CarbonImmutable::parse('2026-11-11 00:05:00', 'Africa/Dar_es_Salaam'));
        $this->assertSame('2026-11-10T23:50:00+03:00', $due['scheduled_at']);
    }

    public function test_different_company_timezones_and_send_times_remain_independent(): void
    {
        $schedule = $this->schedule();
        $other = User::factory()->create();
        $second = app(TenantContext::class)->run($other, function () use ($other) {
            Branch::create(['name' => 'Other timezone']);

            return WhatsAppReportSchedule::create(['name' => 'UTC company', 'report_type' => 'DAILY', 'enabled' => true, 'send_time' => '06:00', 'timezone' => 'UTC', 'cutoff_time' => '05:00', 'recipient_ids' => [], 'created_by' => $other->id]);
        });
        $instant = CarbonImmutable::parse('2026-11-11 03:05:00', 'UTC');
        $this->assertNotNull(app(ScheduleService::class)->due($schedule, $instant));
        $this->assertNull(app(ScheduleService::class)->due($second, $instant));
        $this->getJson('/settings/whatsapp')->assertOk()->assertDontSee('UTC company');
    }

    public function test_weekly_and_monthly_periods_are_complete_and_month_days_clamp(): void
    {
        $weekly = $this->schedule(['report_type' => 'WEEKLY', 'weekday' => 3]);
        $due = app(ScheduleService::class)->due($weekly, CarbonImmutable::parse('2026-11-11 06:00:00', 'Africa/Dar_es_Salaam'));
        $this->assertSame('2026-11-04T05:00:00+03:00', $due['period_start']);
        $monthly = $this->schedule(['report_type' => 'MONTHLY', 'month_day' => 31]);
        $due = app(ScheduleService::class)->due($monthly, CarbonImmutable::parse('2027-02-28 06:00:00', 'Africa/Dar_es_Salaam'));
        $this->assertSame('2027-01-01T05:00:00+03:00', $due['period_start']);
        $this->assertSame('2027-02-01T05:00:00+03:00', $due['period_end']);
        $monthly->update(['month_end' => true, 'month_day' => 1]);
        $this->assertNotNull(app(ScheduleService::class)->due($monthly, CarbonImmutable::parse('2027-04-30 06:00:00', 'Africa/Dar_es_Salaam')));
    }

    public function test_overnight_live_period_matches_exact_shift_reports_and_is_read_only(): void
    {
        $before = $this->issueWhatsAppOrder('1');
        $this->travelTo(CarbonImmutable::parse('2026-11-11 02:00:00', 'Africa/Dar_es_Salaam'));
        $after = $this->issueWhatsAppOrder('2');
        $snapshot = [];
        foreach (['orders', 'payments', 'stocks', 'stock_movements', 'waiter_ledger_entries', 'shifts'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $data = app(OperationalPeriodReportService::class)->build([...$this->period(), 'branch_ids' => [$this->branch->id], 'business_unit_ids' => [$this->counter->business_unit_id]]);
        $screen = app(ReportService::class)->generate('Daily Sales', ['shift_id' => $this->cashierShift->id]);
        $this->assertEquals(9000, $data['metrics']['sales']);
        $this->assertCount(2, $screen['rows']);
        $this->assertSame(0, D::cmp($data['metrics']['sales'], (string) array_sum(array_column($screen['rows'], 3))));
        $this->assertSame(2, $data['metrics']['open_shifts']);
        foreach ($snapshot as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    public function test_schedule_intersects_recipient_branch_and_unit_scope(): void
    {
        $this->recipient->update(['all_branches' => false, 'branch_ids' => [$this->branch->id], 'all_business_units' => false, 'business_unit_ids' => [$this->counter->business_unit_id], 'language' => 'sw']);
        $schedule = $this->schedule();
        app(ScheduleService::class)->enqueue($schedule, $this->period());
        $log = WhatsAppDeliveryLog::firstOrFail();
        $this->assertSame([$this->branch->id], $log->scope_branch_ids);
        $this->assertSame([$this->counter->business_unit_id], $log->scope_business_unit_ids);
        $this->assertSame('sw', $log->payload['language']);
        $message = app(ReportBuilder::class)->daily($log);
        $this->assertStringContainsString('TAARIFA YA SIKU', $message);
        $this->assertStringNotContainsString($this->otherBranch->name, $message);
    }

    public function test_per_recipient_matrix_can_disable_one_schedule_type(): void
    {
        $this->recipient->update(['enabled_types' => ['SHIFT']]);
        app(ScheduleService::class)->enqueue($this->schedule(), $this->period());
        $this->assertSame(0, WhatsAppDeliveryLog::count());
        $this->assertTrue(app(RecipientService::class)->enabled($this->recipient, 'TEST'));
    }

    public function test_queued_schedule_is_skipped_after_disabling_or_revoking_units(): void
    {
        $schedule = $this->schedule();
        app(ScheduleService::class)->enqueue($schedule, $this->period());
        $log = WhatsAppDeliveryLog::firstOrFail();
        $schedule->update(['enabled' => false]);
        $this->send($log);
        $this->assertSame('SKIPPED', $log->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_pdf_and_text_share_frozen_source_data_and_confirmed_text_is_not_repeated(): void
    {
        Storage::fake('local');
        $this->issueWhatsAppOrder('2');
        $schedule = $this->schedule(['format' => 'TEXT_PDF']);
        app(ScheduleService::class)->enqueue($schedule, $this->period());
        $log = WhatsAppDeliveryLog::firstOrFail();
        $renderer = new class implements ReportPdfRenderer
        {
            public string $html = '';

            public function render(string $html, string $orientation): string
            {
                $this->html = $html;

                return '%PDF-1.7 test';
            }
        };
        $this->app->instance(ReportPdfRenderer::class, $renderer);
        Http::fake(['notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['state' => 'logged_in']]), 'notify.buildcore.site/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'TEXT-1']]), 'notify.buildcore.site/send/file' => Http::sequence()->push([], 503)->push(['code' => 'SUCCESS', 'results' => ['message_id' => 'PDF-1']])]);
        $this->send($log);
        $log->refresh();
        $this->assertSame('RETRYING', $log->status);
        $this->assertSame('TEXT-1', $log->provider_message_id);
        $this->assertStringContainsString('6,000', $log->message);
        $this->assertStringContainsString('6,000', $renderer->html);
        $this->assertSame('6000.00', $log->report_data['metrics']['sales']);
        $this->travel(11)->seconds();
        $this->send($log);
        $this->send($log);
        $this->assertSame('SENT', $log->fresh()->status);
        $this->assertSame('PDF-1', $log->fresh()->attachment_message_id);
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/send/message'));
        $this->assertCount(1, $sent);
        Storage::disk('local')->assertExists($log->fresh()->attachment_path);
    }

    public function test_ambiguous_pdf_is_not_automatically_resent(): void
    {
        Storage::fake('local');
        $this->app->instance(ReportPdfRenderer::class, new class implements ReportPdfRenderer
        {
            public function render(string $html, string $orientation): string
            {
                return '%PDF-1.7';
            }
        });
        $schedule = $this->schedule(['format' => 'TEXT_PDF']);
        app(ScheduleService::class)->enqueue($schedule, $this->period());
        $log = WhatsAppDeliveryLog::firstOrFail();
        Http::fake(['notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['state' => 'logged_in']]), 'notify.buildcore.site/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'CONFIRMED']]), 'notify.buildcore.site/send/file' => Http::failedConnection()]);
        $this->send($log);
        $this->send($log);
        $this->assertSame('FAILED', $log->fresh()->status);
        $this->assertSame('AMBIGUOUS', $log->fresh()->attachment_status);
        Http::assertSentCount(3);
    }

    public function test_settings_schedule_and_recipients_are_authorized_and_audited_without_secrets(): void
    {
        $schedule = $this->schedule();
        $this->postJson('/settings/whatsapp/schedules', [...$schedule->toArray(), 'branch_id' => $this->branch->id])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'WHATSAPP_SCHEDULE_SAVED', 'reference_id' => (string) $schedule->id]);
        $this->get('/settings/whatsapp')->assertOk()->assertSee('Scheduled Reports')->assertSee('data-workspace-page="whatsapp"', false)->assertDontSee('fake-password-not-for-real-use');
        foreach ([$this->cashier, $this->waiter] as $staff) {
            $this->actingAs($staff);
            $this->getJson('/settings/whatsapp')->assertForbidden();
            $this->postJson('/settings/whatsapp/schedules', [])->assertForbidden();
        }
    }

    public function test_low_stock_does_not_repeat_after_cooldown_when_unchanged(): void
    {
        $this->settings->update(['options' => [...$this->settings->preferences(), 'low_stock' => true, 'cooldown_minutes' => 1]]);
        $this->issueWhatsAppOrder('80');
        $this->scanWhatsApp();
        $count = WhatsAppDeliveryLog::where('message_type', 'LOW_STOCK')->count();
        $this->assertSame(1, $count);
        $this->travel(5)->minutes();
        app(AuditService::class)->record('GRN_POSTED', 'Inventory', 'StockLocation', $this->counter->id, [], [], $this->branch->id);
        $this->scanWhatsApp();
        $this->assertSame($count, WhatsAppDeliveryLog::where('message_type', 'LOW_STOCK')->count());
    }

    public function test_legacy_delivery_keys_are_not_resent_after_module_upgrade(): void
    {
        $log = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'legacy-period', [$this->branch->id], message: 'Legacy test');
        $key = hash('sha256', json_encode(['TEST', 'legacy-period', $this->recipient->id, [$this->branch->id], null, null], JSON_THROW_ON_ERROR));
        $log->update(['idempotency_key' => $key, 'status' => 'SENT']);
        $again = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'legacy-period', [$this->branch->id], message: 'New test');
        $this->assertSame($log->id, $again->id);
        $this->assertSame(1, WhatsAppDeliveryLog::count());
    }

    public function test_scope_order_does_not_change_delivery_idempotency(): void
    {
        $units = BusinessUnit::pluck('id')->all();
        $first = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'scope-order', [$this->branch->id, $this->otherBranch->id], ['scope_business_unit_ids' => $units], message: 'Test');
        $second = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'scope-order', [$this->otherBranch->id, $this->branch->id], ['scope_business_unit_ids' => array_reverse($units)], message: 'Test');
        $this->assertSame($first->id, $second->id);
    }

    public function test_legacy_unscoped_report_cannot_send_after_recipient_is_unit_restricted(): void
    {
        $log = app(Outbox::class)->enqueue($this->recipient, 'DAILY', 'legacy-report', [$this->branch->id], message: 'Old branch-wide report');
        $log->update(['scope_business_unit_ids' => null]);
        $this->recipient->update(['all_business_units' => false, 'business_unit_ids' => [$this->counter->business_unit_id]]);
        $this->send($log);
        $this->assertSame('SKIPPED', $log->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_queue_reservation_outlives_pdf_worker_timeout(): void
    {
        $job = new SendWhatsAppMessageJob($this->owner->company_id, 1);
        $this->assertGreaterThan($job->timeout, config('queue.connections.'.config('gowa.queue_connection').'.retry_after'));
        $this->assertTrue(config('queue.connections.'.config('gowa.queue_connection').'.after_commit'));
    }

    public function test_treasury_only_pdf_never_includes_sales_or_invoice_details(): void
    {
        Storage::fake('local');
        $order = $this->issueWhatsAppOrder('2');
        $this->recipient->update(['enabled_types' => ['TREASURY_SUMMARY']]);
        $schedule = $this->schedule(['report_type' => 'TREASURY_SUMMARY', 'format' => 'TEXT_PDF']);
        app(ScheduleService::class)->enqueue($schedule, $this->period());
        $log = WhatsAppDeliveryLog::firstOrFail();
        $renderer = new class implements ReportPdfRenderer
        {
            public string $html = '';

            public function render(string $html, string $orientation): string
            {
                $this->html = $html;

                return '%PDF-1.7';
            }
        };
        $this->app->instance(ReportPdfRenderer::class, $renderer);
        $this->connectedFake();
        Http::fake(['notify.buildcore.site/send/file' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'TREASURY-PDF']])]);
        $this->send($log);
        $log->refresh();
        $this->assertSame('SENT', $log->status);
        $this->assertSame('6000.00', $log->report_data['metrics']['sales']);
        $this->assertStringContainsString('TREASURY SUMMARY', $log->message);
        $this->assertStringNotContainsString('Total sales', $log->message);
        $this->assertStringNotContainsString($order->invoice_number, $renderer->html);
        $this->assertStringNotContainsString('6,000', $renderer->html);
        $this->assertSame([], $log->report_data['source_reports']);
    }

    public function test_manager_reports_obey_erp_permissions_and_cancel_retries_after_authorization_changes(): void
    {
        Storage::fake('local');
        $order = $this->issueWhatsAppOrder('2');
        $manager = $this->whatsAppStaff('Manager');
        $log = app(TenantContext::class)->run($manager, function () use ($manager) {
            $recipient = app(RecipientService::class)->save(['name' => 'Scoped manager', 'phone' => '0712345688', 'all_branches' => false, 'branch_ids' => [$this->branch->id], 'all_business_units' => false, 'business_unit_ids' => [$this->counter->business_unit_id], 'daily_report' => true, 'shift_reports' => true, 'critical_alerts' => true, 'active' => true, 'language' => 'en']);

            return app(Scheduler::class)->daily($recipient, '2026-11-10', $manager->id)[0];
        });
        $log->update(['payload' => [...$log->payload, 'format' => 'TEXT_PDF']]);
        $renderer = new class implements ReportPdfRenderer
        {
            public string $html = '';

            public function render(string $html, string $orientation): string
            {
                $this->html = $html;

                return '%PDF-1.7';
            }
        };
        $this->app->instance(ReportPdfRenderer::class, $renderer);
        $this->connectedFake();
        Http::fake(['notify.buildcore.site/send/file' => Http::response([], 503)]);
        $this->send($log);
        $log->refresh();
        $this->assertSame('RETRYING', $log->status);
        $this->assertFalse($log->report_data['can_view_treasury']);
        $this->assertStringContainsString('6,000', $log->message);
        $this->assertStringNotContainsString('*Treasury*', $log->message);
        $this->assertStringContainsString($order->invoice_number, $renderer->html);
        $this->assertStringNotContainsString('Cash received from source shifts', $renderer->html);
        $manager->role->permissions()->detach(Permission::where('name', 'view-staff-operations')->value('id'));
        $this->travel(11)->seconds();
        $this->send($log);
        $this->assertSame('SKIPPED', $log->fresh()->status);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/send/file')));
        app(Outbox::class)->enqueue($this->recipient, 'TREASURY_SUMMARY', 'private-preview', [$this->branch->id], message: 'PRIVATE_TREASURY_FIGURES');
        $this->actingAs($manager);
        $this->get('/settings/whatsapp')->assertOk()->assertDontSee('PRIVATE_TREASURY_FIGURES');

    }
}
