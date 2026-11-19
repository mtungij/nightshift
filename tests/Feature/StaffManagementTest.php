<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\Order;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private StockLocation $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->counter = StockLocation::where('name', 'Main Counter')->firstOrFail();
    }

    private function command(string $command, array $payload): TestResponse
    {
        return $this->postJson("/api/nightshift/commands/$command", [...$payload, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function person(string $username, string $role = 'Cashier'): User
    {
        $id = $this->command('catalog-user', [
            'name' => ucfirst($username), 'username' => $username, 'email' => "$username@staff.test", 'pin' => '4816', 'role' => $role,
            'branch_id' => $this->counter->branch_id, 'business_unit_ids' => [$this->counter->business_unit_id],
            ...($role === 'Cashier' ? ['counter_ids' => [$this->counter->id]] : []),
        ])->assertOk()->json('id');

        return User::findOrFail($id);
    }

    private function edit(User $staff, string $role, array $fields = []): TestResponse
    {
        return $this->command('staff-edit', [
            'id' => $staff->id, 'name' => $staff->name, 'email' => $staff->email,
            'role_id' => Role::where('name', $role)->firstOrFail()->id, ...$fields,
        ]);
    }

    private function scope(array $counters = []): array
    {
        return ['branch_ids' => [$this->counter->branch_id], 'business_unit_ids' => [$this->counter->business_unit_id], 'counter_ids' => $counters];
    }

    public function test_edit_updates_profile_role_assignments_and_audit_with_separate_pin_reset(): void
    {
        $staff = $this->person('counter-editor');
        $url = "/api/nightshift/staff/$staff->id";
        $this->getJson($url)->assertOk()->assertJsonPath('counter_ids.0', $this->counter->id)->assertJsonMissingPath('operational_pin');
        $pin = $staff->operational_pin;
        $this->edit($staff, 'Manager', $this->scope() + [
            'first_name' => 'Nia', 'last_name' => 'Musa', 'name' => 'Nia Musa', 'username' => 'NIA-STAFF',
            'email' => 'NIA@STAFF.TEST', 'phone' => '+255711000000',
        ])->assertOk()->assertJsonPath('role_changed', true);
        $staff->refresh();
        $this->assertSame(['Nia', 'Musa', 'Nia Musa', 'nia-staff', 'nia@staff.test', '+255711000000'], [
            $staff->first_name, $staff->last_name, $staff->name, $staff->username, $staff->email, $staff->phone,
        ]);
        $this->assertSame('Manager', $staff->role->name);
        $this->assertSame($pin, $staff->operational_pin);
        $this->assertDatabaseMissing('counter_user', ['user_id' => $staff->id]);
        $audit = AuditLog::where('action', 'STAFF_ROLE_CHANGED')->where('reference_id', $staff->id)->sole();
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame($this->counter->branch_id, $audit->branch_id);
        $this->assertSame('Cashier', $audit->old_values['role']);
        $this->assertSame('Manager', $audit->new_values['role']);
        $this->assertContains('phone', $audit->new_values['changed_fields']);
        $this->assertArrayNotHasKey('operational_pin', $audit->new_values);
        $this->edit($staff, 'Cashier', $this->scope())->assertUnprocessable();
        $this->edit($staff, 'Cashier', $this->scope([$this->counter->id]))->assertOk();
        $this->assertDatabaseHas('counter_user', ['user_id' => $staff->id, 'stock_location_id' => $this->counter->id]);
        $this->command('staff-pin', ['id' => $staff->id, 'pin' => '3274'])->assertOk();
        $this->assertTrue(Hash::check('3274', $staff->fresh()->operational_pin));
    }

    public function test_open_cashier_and_waiter_shifts_block_incompatible_edits_but_allow_profile_updates(): void
    {
        foreach (['cashier' => 'Cashier', 'asha' => 'Waiter'] as $username => $role) {
            $staff = User::where('email', "$username@nightshift.test")->firstOrFail();
            $this->edit($staff, 'Store Keeper', $this->scope())->assertUnprocessable()
                ->assertJsonPath('errors.transaction.0', 'Resolve open shifts, handovers and treasury cash before changing the staff role.');
            $this->edit($staff, $role, ['phone' => '+255711000099'])->assertOk()->assertJsonPath('role_changed', false);
            $this->assertDatabaseHas('shifts', ['user_id' => $staff->id, 'status' => 'OPEN']);
        }
    }

    public function test_edit_scopes_roles_and_unique_profile_fields_to_authorized_targets(): void
    {
        $staff = $this->person('scope-editor');
        $foreign = User::factory()->create();
        $this->getJson("/api/nightshift/staff/$foreign->id")->assertNotFound();
        $this->edit($foreign, 'Cashier')->assertNotFound();
        $this->edit($staff, 'Super Admin')->assertNotFound();
        $this->edit($staff, 'Manager', ['branch_ids' => [999999], 'business_unit_ids' => []])->assertNotFound();
        $this->edit($staff, 'Manager', ['branch_ids' => [$this->counter->branch_id], 'business_unit_ids' => [999999]])->assertNotFound();
        $this->edit($staff, 'Cashier', $this->scope([999999]))->assertNotFound();
        $this->edit($staff, 'Cashier', ['email' => mb_strtoupper($this->owner->email)])->assertJsonValidationErrors('email');
        $this->edit($staff, 'Cashier', ['username' => $this->owner->username])->assertJsonValidationErrors('username');
        $this->edit($this->owner, 'Company Admin / Owner', ['username' => null])->assertJsonValidationErrors('username');
        $this->assertSame('Cashier', $staff->fresh()->role->name);
        $manager = User::where('email', 'manager@nightshift.test')->firstOrFail();
        $this->actingAs($manager);
        $this->edit($this->owner, 'Manager')->assertForbidden();
        $this->edit($staff, 'Company Admin / Owner', $this->scope())->assertNotFound();
    }

    public function test_terminal_assignment_is_explicit_and_cleared_on_role_change(): void
    {
        $staff = $this->person('terminal-editor', 'Store Keeper');
        $this->edit($staff, 'POS Terminal', $this->scope())->assertUnprocessable();
        $this->edit($staff, 'POS Terminal', $this->scope() + ['terminal_stock_location_id' => $this->counter->id])->assertOk();
        $this->assertSame($this->counter->id, $staff->fresh()->terminal_stock_location_id);
        $this->edit($staff->fresh(), 'Waiter', $this->scope())->assertOk();
        $staff->refresh();
        $this->assertNull($staff->terminal_stock_location_id);
        $this->assertNull($staff->terminal_business_unit_id);
    }

    public function test_unused_staff_is_deleted_and_inactive_staff_can_be_activated(): void
    {
        $staff = $this->person('unused-editor');
        $this->command('staff-status', ['id' => $staff->id, 'active' => false])->assertOk();
        $this->assertFalse((bool) $staff->fresh()->active);
        $this->command('staff-status', ['id' => $staff->id, 'active' => true])->assertOk();
        $this->assertTrue((bool) $staff->fresh()->active);
        $this->getJson("/api/nightshift/staff/$staff->id?deletion=1")->assertJsonPath('archive', false);
        $this->command('staff-delete', ['id' => $staff->id])->assertOk()->assertJsonPath('archived', false);
        foreach (['branch_user', 'business_unit_user', 'counter_user'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $staff->id]);
        }
        $this->assertModelMissing($staff);
        foreach (['STAFF_DEACTIVATED', 'STAFF_ACTIVATED', 'STAFF_DELETED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['reference_id' => $staff->id, 'action' => $action]);
        }
    }

    public function test_historical_waiter_is_archived_and_retained_in_orders_but_cannot_log_in(): void
    {
        $staff = $this->person('historical-editor', 'Waiter');
        $order = Order::whereNotNull('issued_at')->firstOrFail();
        DB::table('orders')->where('id', $order->id)->update(['waiter_id' => $staff->id, 'status' => 'PAID', 'balance' => 0, 'amount_paid' => $order->total]);
        $this->getJson("/api/nightshift/staff/$staff->id?deletion=1")->assertJsonPath('archive', true);
        $this->command('staff-delete', ['id' => $staff->id])->assertOk()->assertJsonPath('archived', true);
        $this->assertNotNull($staff->fresh()->archived_at);
        $this->assertFalse((bool) $staff->fresh()->active);
        $this->assertSame($staff->name, $order->fresh()->waiter->name);
        $this->assertDatabaseHas('audit_logs', ['reference_id' => $staff->id, 'action' => 'STAFF_ARCHIVED']);
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonFragment(['id' => $staff->id, 'archived' => true]);
        $this->command('staff-status', ['id' => $staff->id, 'active' => true])->assertUnprocessable();
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['username' => $staff->username, 'pin' => '4816'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_last_admin_is_protected_from_delete_and_deactivate(): void
    {
        foreach (['staff-delete' => [], 'staff-status' => ['active' => false]] as $action => $data) {
            $this->command($action, ['id' => $this->owner->id, ...$data])->assertUnprocessable()
                ->assertJsonPath('errors.transaction.0', 'At least one active Company Admin / Owner must remain.');
        }
        $this->assertTrue((bool) $this->owner->fresh()->active);
    }
}
