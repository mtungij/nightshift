<?php

namespace Tests\Feature;

use App\Domain\Decimal as D;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Services\AdjustmentService;
use App\Services\GoodsReceiptService;
use App\Services\Gowa;
use App\Services\PaymentService;
use App\Services\ShiftService;
use App\Services\VoidService;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\GowaException;
use App\Services\WhatsApp\Outbox;
use App\Services\WhatsApp\ReportBuilder;
use App\Services\WhatsApp\Scheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppIntegrationTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupWhatsApp();
    }

    public function test_company_devices_recipients_logs_and_actions_are_isolated(): void
    {
        $log = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'isolation', [$this->branch->id], message: 'Company A private message');
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->get('/settings/whatsapp')->assertOk()->assertDontSee('Company A private message')->assertDontSee($this->settings->device_id)->assertDontSee('Owner Recipient');
        $this->postJson('/settings/whatsapp/send/test', ['recipient_id' => $this->recipient->id, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/settings/whatsapp/connection/status', ['device_id' => $this->settings->device_id])->assertUnprocessable();
        $this->expectException(HttpException::class);
        app(Gowa::class)->deviceStatus($this->settings);
    }

    public function test_device_slot_is_generated_for_current_company_and_link_fields_are_backend_only(): void
    {
        $this->settings->update(['device_id' => null, 'provisioned' => false]);
        Http::fake(['notify.buildcore.site/devices' => Http::response(['code' => 'SUCCESS', 'results' => []]), 'notify.buildcore.site/devices/*/login/code*' => Http::response(['code' => 'SUCCESS', 'results' => ['pair_code' => 'ABCD-1234']])]);
        $this->postJson('/settings/whatsapp/connection/code', ['phone' => '0629123456'])->assertOk()->assertJsonPath('pair_code', 'ABCD-1234');
        $device = $this->settings->fresh()->device_id;
        $this->assertStringStartsWith('nightshift-'.$this->owner->company_id.'-', $device);
        Http::assertSent(fn ($r) => $r->url() === 'https://notify.buildcore.site/devices' && $r['device_id'] === $device && $r->hasHeader('X-Device-Id', $device));
        $this->get('/settings/whatsapp')->assertOk()->assertDontSee('fake-password-not-for-real-use')->assertDontSee('fake-user');
    }

    public function test_qr_is_proxied_without_credentials_and_cross_origin_images_are_rejected(): void
    {
        Http::fake(['notify.buildcore.site/devices/*/login' => Http::response(['code' => 'SUCCESS', 'results' => ['qr_link' => 'https://notify.buildcore.site/statics/images/qrcode/scan.png', 'qr_duration' => 30]]), 'notify.buildcore.site/statics/images/qrcode/scan.png' => Http::response('fake-png-for-proxy', 200, ['Content-Type' => 'image/png'])]);
        $this->postJson('/settings/whatsapp/connection/qr')->assertOk()->assertJsonPath('expires_in', 30)->assertJsonPath('qr_image', 'data:image/png;base64,'.base64_encode('fake-png-for-proxy'));
        $this->expectException(GowaException::class);
        app(Gowa::class)->qrImage($this->settings, 'https://attacker.test/scan.png');
    }

    public function test_managers_can_only_manage_recipients_and_reports_in_assigned_branches(): void
    {
        $manager = $this->whatsAppStaff('Manager');
        $this->actingAs($manager);
        $this->get('/settings/whatsapp')->assertOk()->assertDontSee('Owner Recipient');
        $data = ['name' => 'Manager recipient', 'phone' => '0712345678', 'label' => 'Manager', 'all_branches' => false, 'branch_ids' => [$this->branch->id], 'daily_report' => true, 'shift_reports' => true, 'critical_alerts' => true, 'active' => true];
        $this->postJson('/settings/whatsapp/recipients', $data)->assertOk();
        $this->postJson('/settings/whatsapp/recipients', [...$data, 'phone' => '0712345679', 'branch_ids' => [$this->otherBranch->id]])->assertUnprocessable();
        $this->postJson('/settings/whatsapp/recipients', [...$data, 'all_branches' => true])->assertForbidden();
        $this->postJson('/settings/whatsapp/recipients/'.$this->recipient->id, $data)->assertForbidden();
        $this->postJson('/settings/whatsapp', $this->settings->preferences())->assertForbidden();
        $this->postJson('/settings/whatsapp/send/report', ['recipient_id' => $this->recipient->id, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_cashier_waiter_storekeeper_and_inactive_users_cannot_access_settings(): void
    {
        foreach ([$this->cashier, $this->waiter, $this->whatsAppStaff('Store Keeper')] as $user) {
            $this->actingAs($user);
            $this->get('/settings/whatsapp')->assertForbidden();
            $this->postJson('/settings/whatsapp/connection/status')->assertForbidden();
        }
        $this->owner->forceFill(['active' => false])->save();
        $this->actingAs($this->owner);
        $this->get('/settings/whatsapp')->assertForbidden();
    }

    public function test_settings_validation_cannot_assign_another_device_or_change_credentials(): void
    {
        $data = [...$this->settings->preferences(), 'language' => 'en', 'waiter_threshold' => '250000'];
        $this->postJson('/settings/whatsapp', [...$data, 'device_id' => 'another-company', 'gowa_password' => 'bad'])->assertOk();
        $this->assertSame('company-'.$this->owner->company_id, $this->settings->fresh()->device_id);
        $this->assertSame('en', $this->settings->fresh()->preferences()['language']);
        $this->postJson('/settings/whatsapp', [...$data, 'daily_time' => '28:00'])->assertUnprocessable();
        $this->postJson('/settings/whatsapp', [...$data, 'large_sale_threshold' => '-1'])->assertUnprocessable();
    }

    public function test_phone_validation_and_test_message_are_queued_and_deduplicated(): void
    {
        $this->assertSame('255629123456', $this->recipient->phone);
        $this->postJson('/settings/whatsapp/recipients', ['name' => 'Invalid', 'phone' => '12345', 'all_branches' => true, 'branch_ids' => [], 'daily_report' => true, 'shift_reports' => true, 'critical_alerts' => true, 'active' => true])->assertUnprocessable();
        $payload = ['recipient_id' => $this->recipient->id, 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/settings/whatsapp/send/test', $payload)->assertAccepted();
        $this->postJson('/settings/whatsapp/send/test', $payload)->assertAccepted();
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'TEST')->count());
        Http::assertNothingSent();
        Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
        $this->connectedFake();
        $log = WhatsAppDeliveryLog::where('message_type', 'TEST')->firstOrFail();
        (new SendWhatsAppMessageJob($this->owner->company_id, $log->id))->handle(app(CompanyContext::class));
        $this->assertSame('SENT', $log->fresh()->status);
        $this->assertSame('FAKE-MESSAGE-ID', $log->fresh()->provider_message_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/send/message') && $r['phone'] === '255629123456' && str_contains($r['message'], "\n\n") && $r->hasHeader('X-Device-Id', $this->settings->device_id));
    }

    public function test_disconnected_device_records_retry_without_sending(): void
    {
        $this->settings->update(['connection_status' => 'DISCONNECTED']);
        $this->postJson('/settings/whatsapp/send/test', ['recipient_id' => $this->recipient->id, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        Http::fake(['notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['is_connected' => false, 'is_logged_in' => false]])]);
        $log = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'disconnected', [$this->branch->id], message: 'Test');
        (new SendWhatsAppMessageJob($this->owner->company_id, $log->id))->handle(app(CompanyContext::class));
        $this->assertSame('RETRYING', $log->fresh()->status);
        $this->assertSame('DISCONNECTED', $this->settings->fresh()->connection_status);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/send/message'));
    }

    public function test_retry_backoff_and_successful_delivery_are_never_repeated(): void
    {
        Http::fake(['notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['state' => 'logged_in']]), 'notify.buildcore.site/send/message' => Http::sequence()->push(['code' => 'ERROR'], 503)->push(['code' => 'SUCCESS', 'results' => ['message_id' => 'RETRY-SUCCESS']], 200)]);
        $log = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'retry', [$this->branch->id], message: '*bold*\n_italic_');
        $job = new SendWhatsAppMessageJob($this->owner->company_id, $log->id);
        $this->assertSame([10, 60, 300], $job->backoff());
        $job->handle(app(CompanyContext::class));
        $this->assertSame('RETRYING', $log->fresh()->status);
        $this->travel(11)->seconds();
        $job->handle(app(CompanyContext::class));
        $job->handle(app(CompanyContext::class));
        $this->assertSame('SENT', $log->fresh()->status);
        $this->assertSame(2, $log->fresh()->attempt_count);
        Http::assertSentCount(4);
    }

    public function test_ambiguous_delivery_does_not_automatically_resend_or_log_provider_secrets(): void
    {
        Http::fake(['notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['state' => 'logged_in']]), 'notify.buildcore.site/send/message' => Http::failedConnection('fake-password-not-for-real-use Authorization: Basic secret')]);
        $log = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'ambiguous', [$this->branch->id], message: 'Test');
        $job = new SendWhatsAppMessageJob($this->owner->company_id, $log->id);
        $job->handle(app(CompanyContext::class));
        $job->handle(app(CompanyContext::class));
        $this->assertSame('FAILED', $log->fresh()->status);
        $this->assertStringNotContainsString('fake-password-not-for-real-use', $log->fresh()->error_message);
        $this->assertSame(1, $log->fresh()->attempt_count);
    }

    public function test_reports_reuse_validated_sales_payments_ledger_and_quantities_in_both_languages(): void
    {
        $order = $this->issueWhatsAppOrder('1.5');
        $this->actingAs($this->cashier);
        app(PaymentService::class)->receive($order->id, [['method' => 'Cash', 'amount' => '3000']]);
        $this->actingAs($this->owner);
        $date = now()->toDateString();
        $data = app(ReportBuilder::class)->dailyData($date, [$this->branch->id]);
        $this->assertSame(0, D::cmp('4500', $data['sales']));
        $this->assertSame(0, D::cmp('3000', $data['collected']));
        $this->assertSame(0, D::cmp('1500', $data['outstanding']));
        $this->assertSame(0, D::cmp('1.5', $data['units'], 6));
        $logs = app(Scheduler::class)->daily($this->recipient, $date);
        $sw = app(ReportBuilder::class)->daily($logs[0]);
        $this->assertStringContainsString('TAARIFA YA LEO', $sw);
        $this->assertStringContainsString('TSh 4,500', $sw);
        $this->assertStringContainsString('Bidhaa Zilizouzwa: 1.5', $sw);
        $log = $logs[0];
        $log->update(['payload' => [...$log->payload, 'language' => 'en']]);
        $this->assertStringContainsString('DAILY OWNER REPORT', app(ReportBuilder::class)->daily($log));
    }

    public function test_consolidated_and_per_branch_reports_respect_recipient_scope(): void
    {
        $this->issueWhatsAppOrder();
        $data = app(ReportBuilder::class)->dailyData(now()->toDateString(), [$this->branch->id, $this->otherBranch->id]);
        $this->assertCount(2, $data['branches']);
        $this->assertSame(0, D::cmp('6000', $data['sales']));
        $this->recipient->update(['all_branches' => false, 'branch_ids' => [$this->otherBranch->id]]);
        $log = app(Scheduler::class)->daily($this->recipient, now()->toDateString())[0];
        $this->assertSame([$this->otherBranch->id], $log->scope_branch_ids);
        $this->assertStringNotContainsString('Masaki', app(ReportBuilder::class)->daily($log));
        $this->recipient->update(['all_branches' => true, 'branch_ids' => []]);
        $this->settings->update(['options' => [...$this->settings->preferences(), 'report_mode' => 'branch']]);
        $this->assertCount(2, app(Scheduler::class)->daily($this->recipient, now()->toDateString()));
    }

    public function test_scheduler_honors_company_timezone_daily_time_and_duplicate_periods(): void
    {
        $this->owner->company->update(['timezone' => 'Africa/Dar_es_Salaam']);
        $this->settings->update(['options' => [...$this->settings->preferences(), 'daily_time' => '23:00']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 19:59:00', 'UTC'));
        app(Scheduler::class)->tick();
        $this->assertSame(0, WhatsAppDeliveryLog::where('message_type', 'DAILY')->count());
        $this->travel(1)->minutes();
        app(Scheduler::class)->tick();
        app(Scheduler::class)->tick();
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'DAILY')->count());
        $this->assertSame('2026-10-12', WhatsAppDeliveryLog::where('message_type', 'DAILY')->firstOrFail()->payload['date']);
        Http::assertNothingSent();
    }

    public function test_waiter_threshold_cooldown_and_large_sale_alert(): void
    {
        $this->settings->update(['options' => [...$this->settings->preferences(), 'waiter_outstanding' => true, 'waiter_threshold' => '6000', 'large_sale' => true, 'large_sale_threshold' => '6000']]);
        $this->issueWhatsAppOrder();
        $this->scanWhatsApp();
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'WAITER_OUTSTANDING')->count());
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'LARGE_SALE')->count());
        $this->issueWhatsAppOrder('1');
        $this->scanWhatsApp();
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'WAITER_OUTSTANDING')->count());
        $this->travel(361)->minutes();
        $this->issueWhatsAppOrder('1');
        $this->scanWhatsApp();
        $this->assertSame(2, WhatsAppDeliveryLog::where('message_type', 'WAITER_OUTSTANDING')->count());
    }

    public function test_low_stock_is_suppressed_until_further_drop_or_replenishment(): void
    {
        $this->settings->update(['options' => [...$this->settings->preferences(), 'low_stock' => true]]);
        $this->issueWhatsAppOrder('80');
        $this->scanWhatsApp();
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'LOW_STOCK')->count());
        $this->issueWhatsAppOrder('1');
        $this->scanWhatsApp();
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'LOW_STOCK')->count());
        $this->issueWhatsAppOrder('5');
        $this->scanWhatsApp();
        $this->assertSame(2, WhatsAppDeliveryLog::where('message_type', 'LOW_STOCK')->count());
        // Replenish through the real GRN service, then cross the minimum again.
        $supplier = Supplier::firstOrFail();
        app(GoodsReceiptService::class)->post(['stock_location_id' => $this->counter->id, 'supplier_id' => $supplier->id, 'received_on' => now()->toDateString(), 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '20', 'unit_cost' => '2000']]]);
        $this->scanWhatsApp();
        $this->issueWhatsAppOrder('15');
        $this->scanWhatsApp();
        $this->assertSame(3, WhatsAppDeliveryLog::where('message_type', 'LOW_STOCK')->count());
    }

    public function test_shift_summary_and_cash_shortage_threshold_are_queued(): void
    {
        $this->settings->update(['options' => [...$this->settings->preferences(), 'shift_report' => true, 'cash_shortage' => true, 'cash_threshold' => '1000']]);
        $order = $this->issueWhatsAppOrder();
        $this->actingAs($this->cashier);
        app(PaymentService::class)->receive($order->id, [['method' => 'Cash', 'amount' => '6000']]);
        app(ShiftService::class)->close($this->cashierShift->id, ['actual_cash' => '4000', 'notes' => 'Verified cash shortage']);
        $this->actingAs($this->owner);
        $this->scanWhatsApp();
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'SHIFT')->count());
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'CASH_SHORTAGE')->count());
        $log = WhatsAppDeliveryLog::where('message_type', 'SHIFT')->firstOrFail();
        $this->assertStringContainsString('Cash Halisi: TSh 4,000', app(ReportBuilder::class)->shift($log));
        $this->assertStringContainsString('Mauzo: TSh 6,000', app(ReportBuilder::class)->shift($log));
        $this->scanWhatsApp();
        $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', 'SHIFT')->count());
        Http::assertNothingSent();
    }

    public function test_void_breakage_and_complimentary_use_approved_source_records(): void
    {
        $this->settings->update(['options' => [...$this->settings->preferences(), 'void' => true, 'breakage' => true, 'complimentary' => true, 'breakage_threshold' => '2000', 'complimentary_threshold' => '2000']]);
        $order = $this->issueWhatsAppOrder();
        $void = app(VoidService::class)->request($order->id, 'Customer changed order');
        app(VoidService::class)->decide($void->id, true, 'Recovered all the goods');
        foreach (['BREAKAGE', 'COMPLIMENTARY'] as $reason) {
            $adjustment = app(AdjustmentService::class)->request(['stock_location_id' => $this->counter->id, 'reason' => $reason, 'notes' => 'Verified operational reason', 'items' => [['product_unit_id' => $this->piece->id, 'quantity' => '1']]]);
            app(AdjustmentService::class)->decide($adjustment->id, true, 'Approved by manager');
        }
        $this->scanWhatsApp();
        foreach (['VOID', 'BREAKAGE', 'COMPLIMENTARY'] as $type) {
            $this->assertSame(1, WhatsAppDeliveryLog::where('message_type', $type)->count());
        }
        $this->assertStringContainsString($this->owner->name, WhatsAppDeliveryLog::where('message_type', 'VOID')->firstOrFail()->message);
        Http::assertNothingSent();
    }

    public function test_recipient_revocation_prevents_queued_delivery(): void
    {
        $this->connectedFake();
        $log = app(Outbox::class)->enqueue($this->recipient, 'TEST', 'revoked', [$this->branch->id], message: 'Test');
        $this->recipient->update(['active' => false]);
        (new SendWhatsAppMessageJob($this->owner->company_id, $log->id))->handle(app(CompanyContext::class));
        $this->assertSame('SKIPPED', $log->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_provider_failures_never_rollback_payment_or_shift_closing(): void
    {
        $this->settings->update(['options' => [...$this->settings->preferences(), 'shift_report' => true]]);
        $order = $this->issueWhatsAppOrder();
        $this->actingAs($this->cashier);
        app(PaymentService::class)->receive($order->id, [['method' => 'Cash', 'amount' => '6000']]);
        app(ShiftService::class)->close($this->cashierShift->id, ['actual_cash' => '6000']);
        $this->actingAs($this->owner);
        $this->scanWhatsApp();
        Http::fake(['*' => Http::response(['message' => 'fake-password-not-for-real-use'], 401)]);
        $log = WhatsAppDeliveryLog::where('message_type', 'SHIFT')->firstOrFail();
        (new SendWhatsAppMessageJob($this->owner->company_id, $log->id))->handle(app(CompanyContext::class));
        $this->assertSame('FAILED', $log->fresh()->status);
        $this->assertSame('CLOSED', $this->cashierShift->fresh()->status);
        $this->assertSame(0, D::cmp('6000', $order->fresh()->amount_paid));
        $this->assertSame(0, D::cmp('0', $order->fresh()->balance));
    }

    public function test_send_file_and_registration_check_use_saved_device_and_safe_fields(): void
    {
        Http::fake(['notify.buildcore.site/send/file' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'FILE-ID']]), 'notify.buildcore.site/user/check*' => Http::response(['code' => 'SUCCESS', 'results' => ['is_on_whatsapp' => true]])]);
        $this->assertTrue(app(Gowa::class)->isOnWhatsApp($this->settings, '0629123456'));
        $this->assertSame('FILE-ID', app(Gowa::class)->sendFile($this->settings, '0629123456', 'fake report', 'report.txt', 'Owner report')['results']['message_id']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/send/file') && $r->hasHeader('X-Device-Id', $this->settings->device_id));
    }

    public function test_generic_provider_methods_reject_foreign_urls_and_device_overrides(): void
    {
        foreach (['https://attacker.test/devices/'.$this->settings->device_id.'/status', '//attacker.test/devices/'.$this->settings->device_id.'/status', '/devices/another-company/status', '/devices/'.$this->settings->device_id.'/status?device_id=another-company'] as $path) {
            try {
                app(Gowa::class)->get($this->settings, $path);
                $this->fail('Unsafe provider request was accepted.');
            } catch (GowaException) {
                $this->addToAssertionCount(1);
            }
        }
        Http::assertNothingSent();
    }
}
