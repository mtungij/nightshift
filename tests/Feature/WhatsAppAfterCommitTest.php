<?php

namespace Tests\Feature;

use App\Jobs\WhatsApp\SendOperationalAlertJob;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppAfterCommitTest extends TestCase
{
    use WhatsAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // Real commits are required here; RefreshDatabase wraps the entire test.
        // A fresh disposable database also avoids unrelated SQLite down-migration limitations.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
        $this->setupWhatsApp();
        Queue::fake();
    }

    public function test_shift_report_signal_is_dispatched_only_after_outer_commit(): void
    {
        $this->actingAs($this->cashier);
        DB::beginTransaction();
        app(ShiftService::class)->close($this->cashierShift->id, ['actual_cash' => '0']);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SendOperationalAlertJob::class, fn ($job) => $job->companyId === $this->owner->company_id);
        $this->assertSame('CLOSED', $this->cashierShift->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_rolled_back_shift_does_not_queue_whatsapp(): void
    {
        $this->actingAs($this->cashier);
        DB::beginTransaction();
        app(ShiftService::class)->close($this->cashierShift->id, ['actual_cash' => '0']);
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertSame('OPEN', $this->cashierShift->fresh()->status);
        Http::assertNothingSent();
    }
}
