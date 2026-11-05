<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedPosSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
    }

    public function test_legacy_lock_flags_and_twenty_minutes_of_inactivity_do_not_lock_shared_pos(): void
    {
        $this->actingAs($this->cashier)->withSession(['staff_locked' => true, 'staff_last_activity' => time() - 1200]);
        $this->get('/dashboard')->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonMissingPath('quickLock')->assertJsonPath('user.id', $this->cashier->id);
        $this->travel(21)->minutes();
        $this->get('/dashboard')->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertOk();
        $this->assertAuthenticatedAs($this->cashier);
        $this->assertSame(960, config('session.lifetime'));
        $this->assertFalse(config('session.expire_on_close'));
    }

    public function test_removed_lock_and_heartbeat_endpoints_are_not_available(): void
    {
        $this->actingAs($this->cashier);
        $this->get('/quick-lock')->assertNotFound();
        $this->post('/quick-lock')->assertNotFound();
        $this->post('/quick-lock/unlock', ['pin' => '4567'])->assertNotFound();
        $this->post('/api/nightshift/activity')->assertNotFound();
    }

    private function persistedSession(int $idleMinutes): string
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $this->cashier->id,
            'payload' => base64_encode(json_encode([Auth::guard()->getName() => $this->cashier->id, '_token' => Str::random(40), 'shared_pos_marker' => 'must be invalidated'], JSON_THROW_ON_ERROR)),
            'last_activity' => now()->subMinutes($idleMinutes)->timestamp,
        ]);
        Auth::forgetGuards();
        $this->withCookie(config('session.cookie'), $id);

        return $id;
    }

    public function test_database_session_remains_valid_after_fifteen_hours(): void
    {
        $this->persistedSession(900);
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($this->cashier);
    }

    public function test_database_session_expires_after_960_idle_minutes(): void
    {
        $this->persistedSession(961);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->getJson('/api/nightshift/bootstrap')->assertUnauthorized();
    }

    public function test_manual_logout_invalidates_the_persisted_session_and_rotates_csrf_token(): void
    {
        $id = $this->persistedSession(20);
        $this->get('/dashboard')->assertOk();
        $oldToken = session()->token();
        $this->post('/logout')->assertRedirect('/')->assertSessionMissing('shared_pos_marker');
        $this->assertGuest();
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_csrf_middleware_still_rejects_authenticated_mutations_without_a_token(): void
    {
        $this->actingAs($this->cashier);
        // Laravel bypasses CSRF only in the testing environment; exercise the real middleware.
        app()->instance('env', 'local');
        $this->postJson('/api/nightshift/commands/order-send', [])->assertStatus(419);
    }
}
