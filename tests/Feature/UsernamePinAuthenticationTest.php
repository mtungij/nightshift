<?php

namespace Tests\Feature;

use App\Domain\OperationalShift;
use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\ShiftService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsernamePinAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['Company Admin / Owner', 'Super Admin', 'Manager', 'Supervisor', 'Store Keeper', 'Cashier', 'Waiter']);
    }

    private function staff(string $role = 'Company Admin / Owner'): User
    {
        $admin = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $user = User::factory()->unverified()->create(['company_id' => $admin->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'operational_pin' => Hash::make('0123')]);
        app(TenantContext::class)->run($admin, fn () => $user->branches()->attach(Branch::firstOrFail()->id, ['company_id' => $admin->company_id]));

        return $user;
    }

    #[DataProvider('roles')]
    public function test_each_role_logs_in_without_email_verification_and_keeps_permissions(string $role): void
    {
        $user = $this->staff($role);
        $permissions = $user->role->permissions->pluck('name')->all();
        $type = app(OperationalShift::class)->type($user);
        if ($type) {
            $admin = User::where('email', 'admin@nightshift.test')->firstOrFail();
            app(TenantContext::class)->run($admin, fn () => app(ShiftService::class)->open(['user_id' => $user->id, 'stock_location_id' => StockLocation::where('name', 'Main Counter')->firstOrFail()->id, 'type' => $type]));
        }
        $destination = $type ? '/dashboard#'.($type === 'WAITER' ? 'pos' : 'waiters') : '/dashboard';
        $this->post('/login', ['username' => strtoupper($user->username), 'pin' => '0123'])->assertSessionHasNoErrors()->assertRedirect($destination);
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        $this->get('/dashboard')->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonPath('role', $role)->assertJsonPath('permissions', $permissions)->assertJsonPath('quickLock.enabled', true);
        $this->get('/quick-lock')->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertStatus(423);
        $this->post('/quick-lock/unlock', ['pin' => '0123'])->assertRedirect('/dashboard');
        $this->getJson('/api/nightshift/bootstrap')->assertOk();
    }

    public function test_login_form_has_only_username_and_pin_and_rejects_password_login(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="username"', false)->assertSee('name="pin"', false)->assertDontSee('name="email"', false)->assertDontSee('name="password"', false);
        $user = $this->staff();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors(['username', 'pin']);
        $this->assertGuest();
    }

    public function test_malformed_and_invalid_pin_are_rejected_without_flashing_secrets(): void
    {
        $user = $this->staff();
        foreach ([null, '123', '12345', 'abcd', 1234, '１２３４', ' 0123 '] as $index => $pin) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])->post('/login', ['username' => $user->username, 'pin' => $pin])->assertSessionHasErrors('pin');
            $this->assertGuest();
            $this->assertArrayNotHasKey('pin', session()->getOldInput());
            $this->assertNull(request()->input('pin'));
        }
        $response = $this->post('/login', ['username' => $user->username, 'pin' => '0987'])->assertSessionHasErrors('pin');
        $response->assertDontSee('0987')->assertDontSee($user->operational_pin);
        $this->assertArrayNotHasKey('pin', session()->getOldInput());
        $this->assertNull(request()->input('pin'));
        $this->assertSame(1, $user->fresh()->pin_failed_attempts);
    }

    public function test_account_locks_across_ips_and_unlocks_after_expiry(): void
    {
        $user = $this->staff();
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])->post('/login', ['username' => $user->username, 'pin' => '9999'])->assertSessionHasErrors('pin');
        }
        $this->assertTrue($user->fresh()->pin_locked_until->isFuture());
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])->post('/login', ['username' => $user->username, 'pin' => '0123'])->assertStatus(429);
        $this->assertGuest();
        $this->travel(16)->minutes();
        $this->post('/login', ['username' => $user->username, 'pin' => '0123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->fresh()->pin_failed_attempts);
    }

    public function test_unknown_usernames_are_rate_limited_and_inactive_accounts_cannot_login(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'missing-user', 'pin' => '0123'])->assertSessionHasErrors('username');
        }
        $this->post('/login', ['username' => 'missing-user', 'pin' => '0123'])->assertStatus(429);
        $user = $this->staff();
        $user->forceFill(['active' => false])->save();
        $this->post('/login', ['username' => $user->username, 'pin' => '0123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_company_context_cannot_authenticate_another_company_user(): void
    {
        $a = $this->staff();
        $b = User::factory()->create(['operational_pin' => Hash::make('0123')]);
        $this->get(route('company.login', $b->company_id))->assertOk();
        $this->post('/login', ['username' => $a->username, 'pin' => '0123', 'company_id' => $a->company_id])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->post('/login', ['username' => $b->username, 'pin' => '0123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($b);
        $snapshot = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertNotContains($a->id, array_column($snapshot['team'], 'id'));
    }

    public function test_admin_resets_any_role_pin_and_foreign_or_non_admin_reset_is_denied(): void
    {
        $admin = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $manager = $this->staff('Manager');
        $this->actingAs($admin);
        $data = ['id' => $manager->id, 'pin' => '0987', 'idempotency_key' => (string) Str::uuid()];
        $response = $this->postJson('/api/nightshift/commands/staff-pin', $data)->assertOk();
        $this->assertTrue(Hash::check('0987', $manager->fresh()->operational_pin));
        foreach ([$response->getContent(), AuditLog::all()->toJson(), DB::table('idempotency_keys')->get()->toJson(), $manager->fresh()->toJson()] as $serialized) {
            $this->assertStringNotContainsString('0987', $serialized);
            $this->assertStringNotContainsString($manager->fresh()->operational_pin, $serialized);
        }
        $foreign = User::factory()->create();
        $this->postJson('/api/nightshift/commands/staff-pin', [...$data, 'id' => $foreign->id, 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($manager)->postJson('/api/nightshift/commands/staff-pin', [...$data, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post('/logout');
        $this->post('/login', ['username' => $manager->username, 'pin' => '0987'])->assertRedirect('/dashboard');
        $this->get('/quick-lock')->assertOk();
        $this->post('/quick-lock/unlock', ['pin' => '0987'])->assertRedirect('/dashboard');
    }

    public function test_migration_preserves_legacy_data_and_pin_hashes_and_assigns_unique_usernames(): void
    {
        $user = $this->staff('Waiter');
        $before = $user->only(['name', 'email', 'company_id', 'role_id', 'password', 'operational_pin']);
        $migration = require database_path('migrations/2026_10_12_000001_add_login_usernames.php');
        $migration->down();
        $migration->up();
        $fresh = User::findOrFail($user->id);
        $this->assertSame($before, $fresh->only(array_keys($before)));
        $this->assertNotEmpty($fresh->username);
        $this->assertSame(User::count(), User::distinct()->count('username'));
    }

    public function test_owner_without_pin_can_be_provisioned_by_secure_console_prompt(): void
    {
        $owner = $this->staff();
        $owner->forceFill(['operational_pin' => null])->save();
        $this->artisan('nightshift:set-login-pin', ['username' => $owner->username])->expectsQuestion('New 4-digit PIN', '0987')->expectsQuestion('Confirm PIN', '0987')->expectsOutput('PIN configured.')->assertSuccessful();
        $this->assertTrue(Hash::check('0987', $owner->fresh()->operational_pin));
        $this->post('/login', ['username' => $owner->username, 'pin' => '0987'])->assertRedirect('/dashboard');
    }

    public function test_login_usernames_are_unique_across_companies_and_normalized(): void
    {
        $foreign = User::factory()->create(['username' => 'unique-username']);
        $admin = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->actingAs($admin);
        $this->postJson('/api/nightshift/commands/catalog-user', ['idempotency_key' => (string) Str::uuid(), 'name' => 'Duplicate username', 'username' => strtoupper($foreign->username), 'email' => 'duplicate@pin.test', 'pin' => '0123', 'role' => 'Manager', 'branch_id' => Branch::firstOrFail()->id])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertDatabaseMissing('users', ['email' => 'duplicate@pin.test']);
    }
}
