<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\StaffPinService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffCreationPinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->actingAs(User::where('email', 'admin@nightshift.test')->firstOrFail());
    }

    private function payload(string $role = 'Waiter'): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'name' => 'New staff', 'email' => 'newstaff@test.example', 'branch_id' => Branch::firstOrFail()->id, 'role' => $role];
    }

    public function test_operational_staff_require_pin_and_generate_private_password_atomically(): void
    {
        foreach (config('business.staff_roles') as $role) {
            $data = [...$this->payload($role), 'email' => str_replace(' ', '-', strtolower($role)).'@new.test', 'pin' => '0987', 'password' => 'Known!Password2026'];
            $response = $this->postJson('/api/nightshift/commands/catalog-user', $data)->assertOk();
            $this->postJson('/api/nightshift/commands/catalog-user', $data)->assertOk()->assertExactJson($response->json());
            $this->assertSame(1, User::where('email', $data['email'])->count());
            $staff = User::where('email', $data['email'])->firstOrFail();
            $this->assertTrue(Hash::check('0987', $staff->operational_pin));
            $this->assertFalse(Hash::check($data['password'], $staff->password));
            $this->assertTrue(Hash::isHashed($staff->password));
            $this->assertSame(auth()->user()->company_id, $staff->company_id);
            $this->assertSame($role, $staff->role->name);
            $this->assertSame($staff->id, app(StaffPinService::class)->verify($staff->id, '0987')->id);
            foreach ([$response->getContent(), $staff->toJson(), AuditLog::all()->toJson(), DB::table('idempotency_keys')->get()->toJson()] as $serialized) {
                $this->assertStringNotContainsString('0987', $serialized);
                $this->assertStringNotContainsString($staff->password, $serialized);
                $this->assertStringNotContainsString($staff->operational_pin, $serialized);
            }
            app(StaffPinService::class)->set($staff->id, '6543');
            $this->assertTrue(Hash::check('6543', $staff->fresh()->operational_pin));
            $this->actingAs($staff);
            $this->post('/quick-lock')->assertRedirect('/quick-lock');
            $this->post('/quick-lock/unlock', ['pin' => '6543'])->assertRedirect('/dashboard');
            $this->actingAs(User::where('email', 'admin@nightshift.test')->firstOrFail());
        }
    }

    public function test_operational_staff_reject_missing_or_invalid_pin_without_creating_user(): void
    {
        foreach ([null, '123', '12345', '12ab', 1234, '１２３４'] as $pin) {
            $this->postJson('/api/nightshift/commands/catalog-user', [...$this->payload(), 'pin' => $pin])->assertUnprocessable()->assertJsonValidationErrors('pin');
            $this->assertDatabaseMissing('users', ['email' => 'newstaff@test.example']);
        }
    }

    public function test_manager_requires_pin_and_uses_private_backend_password(): void
    {
        $this->postJson('/api/nightshift/commands/catalog-user', [...$this->payload('Manager'), 'password' => 'Secure!Password2026'])->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->postJson('/api/nightshift/commands/catalog-user', [...$this->payload('Manager'), 'pin' => '0987'])->assertOk();
        $staff = User::where('email', 'newstaff@test.example')->firstOrFail();
        $this->assertTrue(Hash::isHashed($staff->password));
        $this->assertFalse(Hash::check('Secure!Password2026', $staff->password));
        $this->assertTrue(Hash::check('0987', $staff->operational_pin));
    }
}
