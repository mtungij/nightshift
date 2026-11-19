<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\BusinessOnboardingService;
use App\Services\CompanyAdminService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $key): User
    {
        return app(BusinessOnboardingService::class)->register([
            'business_name' => "Hotel $key", 'business_type' => 'Hotel', 'business_phone' => '+255700000001',
            'business_email' => "$key@hotel.test", 'address' => 'Masaki', 'city' => 'Dar es Salaam',
            'first_name' => 'Founding', 'last_name' => 'Admin', 'owner_email' => "owner-$key@hotel.test",
            'owner_phone' => '+255700000002', 'username' => "owner-$key", 'pin' => '0123',
        ]);
    }

    private function command(string $name, array $data): TestResponse
    {
        return $this->postJson("/api/nightshift/commands/$name", ['idempotency_key' => (string) Str::uuid(), ...$data]);
    }

    private function editRole(User $staff, string $role, array $scope = []): TestResponse
    {
        return $this->command('staff-edit', [
            'id' => $staff->id, 'role_id' => Role::where('name', $role)->firstOrFail()->id,
            'name' => $staff->name, 'email' => $staff->email, 'username' => $staff->username,
            'branch_ids' => $staff->branches()->withoutGlobalScopes()->pluck('branches.id')->all(),
            'business_unit_ids' => $staff->businessUnits()->withoutGlobalScopes()->pluck('business_units.id')->all(),
            ...$scope,
        ]);
    }

    private function payload(string $username, int $branch): array
    {
        return ['name' => $username, 'email' => "$username@hotel.test", 'username' => $username, 'pin' => '2468',
            'branch_id' => $branch, 'business_unit_ids' => [], 'role' => CompanyAdminService::ROLE];
    }

    private function create(string $username, int $branch): User
    {
        $id = $this->command('catalog-user', $this->payload($username, $branch))->assertOk()->json('id');

        return User::findOrFail($id);
    }

    public function test_each_admin_logs_in_independently_and_can_add_another_admin(): void
    {
        $founder = $this->company('first');
        $this->actingAs($founder);
        $branch = Branch::firstOrFail();
        $second = $this->create('admin-two', $branch->id);
        $this->assertSame($founder->company_id, $second->company_id);
        $this->assertTrue(Hash::check('2468', $second->operational_pin));
        $this->assertFalse(Hash::check('0123', $second->operational_pin));
        $this->assertNotSame($founder->password, $second->password);
        $this->assertSame($founder->id, $second->company->owner_id);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('business_units', 0);
        $this->assertDatabaseCount('business_unit_user', 0);
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonPath('can_manage_company_admins', true)
            ->assertJsonFragment(['id' => $second->id, 'username' => 'admin-two']);

        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['username' => $second->username, 'pin' => '0123'])->assertSessionHasErrors('pin');
        $this->post('/login', ['username' => $second->username, 'pin' => '2468'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($second);
        $this->get('/business-units')->assertOk();
        $this->get('/settings/business')->assertOk();
        $third = $this->create('admin-three', $branch->id);

        $this->assertSame(3, User::where('company_id', $founder->company_id)->where('role_id', $founder->role_id)->where('active', true)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'COMPANY_ADMIN_CREATED', 'user_id' => $founder->id, 'reference_id' => $second->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'COMPANY_ADMIN_CREATED', 'user_id' => $second->id, 'reference_id' => $third->id]);
        $this->assertSame(1, Role::where('name', CompanyAdminService::ROLE)->count());
    }

    public function test_foreign_branches_units_admins_and_unauthorized_manager_are_blocked(): void
    {
        $this->seed(NightshiftDemoSeeder::class);
        $owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $foreign = $this->company('foreign');
        $this->actingAs($foreign);
        $foreignBranch = Branch::firstOrFail();
        $foreignUnit = BusinessUnit::create(['branch_id' => $foreignBranch->id, 'name' => 'Private Bar', 'code' => 'PRIVATE']);
        $this->actingAs($owner);
        $branch = Branch::firstOrFail();
        $this->command('catalog-user', $this->payload('other-branch', $foreignBranch->id))->assertNotFound();
        $this->command('catalog-user', [...$this->payload('other-company', $branch->id), 'company_id' => $foreign->company_id])->assertUnprocessable();
        $this->command('catalog-user', [...$this->payload('other-unit', $branch->id), 'business_unit_ids' => [$foreignUnit->id]])->assertUnprocessable();
        $this->command('staff-admin-status', ['id' => $foreign->id, 'active' => false])->assertNotFound();
        $this->editRole($foreign, 'Manager')->assertNotFound();

        $manager = User::where('email', 'manager@nightshift.test')->firstOrFail();
        $this->actingAs($manager);
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonPath('can_manage_company_admins', false);
        $this->command('catalog-user', $this->payload('manager-created', $branch->id))->assertForbidden();
        $this->editRole($manager, CompanyAdminService::ROLE)->assertNotFound();
        $this->command('staff-admin-status', ['id' => $owner->id, 'active' => false])->assertForbidden();
        $this->assertTrue((bool) $foreign->fresh()->active);
    }

    public function test_last_active_admin_is_protected_from_status_role_and_confirmed_deletion(): void
    {
        $owner = $this->company('only');
        $this->actingAs($owner);
        $message = 'At least one active Company Admin / Owner must remain.';
        $this->command('staff-admin-status', ['id' => $owner->id, 'active' => false])->assertUnprocessable()->assertJsonPath('errors.transaction.0', $message);
        $this->editRole($owner, 'Manager')->assertUnprocessable()->assertJsonPath('errors.transaction.0', $message);
        $this->postJson('/api/business-units/staff', ['id' => $owner->id, 'active' => false, 'unit_ids' => [], 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonPath('errors.transaction.0', $message);
        $owner->forceFill(['password' => 'known-test-password'])->save();
        Livewire::test('pages::settings.delete-user-modal')->set('password', 'known-test-password')->call('deleteUser')->assertHasErrors('transaction');
        $this->assertAuthenticatedAs($owner);
        $this->assertTrue((bool) $owner->fresh()->active);
        $this->assertSame(CompanyAdminService::ROLE, $owner->fresh()->role->name);
    }

    public function test_admin_can_deactivate_and_reactivate_a_peer_without_changing_history(): void
    {
        $this->seed(NightshiftDemoSeeder::class);
        $owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->actingAs($owner);
        $other = $this->create('operator-admin', Branch::firstOrFail()->id);
        $this->actingAs($other);
        $order = Order::where('balance', '>', 0)->where('status', 'PARTIALLY_PAID')->firstOrFail();
        $otherOrder = $this->command('order-create', ['waiter_id' => $order->waiter_id, 'stock_location_id' => $order->stock_location_id,
            'discount' => '0', 'status' => 'DRAFT', 'items' => [['product_unit_id' => $order->items()->firstOrFail()->product_unit_id, 'quantity' => '1']]])->assertOk()->json('id');
        $this->command('payment', ['id' => $order->id, 'lines' => [['method' => 'Cash', 'amount' => '1']]])->assertOk();
        $payment = Payment::where('received_by', $other->id)->firstOrFail();
        $this->actingAs($owner);
        $this->command('staff-admin-status', ['id' => $other->id, 'active' => false])->assertOk();
        $this->assertFalse((bool) $other->fresh()->active);
        $this->assertDatabaseHas('orders', ['id' => $otherOrder, 'created_by' => $other->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'received_by' => $other->id]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $other->id, 'action' => 'ORDER_CREATED']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $owner->id, 'reference_id' => $other->id, 'action' => 'COMPANY_ADMIN_DEACTIVATED']);

        $this->actingAs($other->fresh());
        $this->get('/dashboard')->assertForbidden();
        $this->get('/settings/profile')->assertForbidden();
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['username' => $other->username, 'pin' => '2468'])->assertSessionHasErrors('username');
        $this->withSession(['login.id' => $other->id])->post('/two-factor-challenge')->assertForbidden();
        $this->assertGuest();
        $passkey = new Passkey;
        $passkey->setRelation('user', $other->fresh());
        $this->assertFalse(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey));
        $this->actingAs($owner);
        $this->command('staff-admin-status', ['id' => $other->id, 'active' => true])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $owner->id, 'reference_id' => $other->id, 'action' => 'COMPANY_ADMIN_REACTIVATED']);
        $passkey->setRelation('user', $other->fresh());
        $this->assertTrue(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey));
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['username' => $other->username, 'pin' => '2468'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($other);
        $this->get('/business-units')->assertOk();
    }

    public function test_promotion_then_self_demotion_uses_explicit_operational_scopes(): void
    {
        $this->seed(NightshiftDemoSeeder::class);
        $owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->actingAs($owner);
        $branch = Branch::firstOrFail();
        $bar = BusinessUnit::where('code', 'BAR')->firstOrFail();
        $restaurant = BusinessUnit::where('code', 'RESTAURANT')->firstOrFail();
        $counter = StockLocation::where('business_unit_id', $restaurant->id)->firstOrFail();
        $staff = User::where('email', 'manager@nightshift.test')->firstOrFail();

        $this->editRole($staff, CompanyAdminService::ROLE)->assertUnprocessable()
            ->assertJsonPath('errors.transaction.0', 'Set a login PIN for this employee before changing their role.');
        $this->command('staff-pin', ['id' => $staff->id, 'pin' => '1357'])->assertOk();
        $this->editRole($staff, CompanyAdminService::ROLE)->assertOk();
        $this->assertTrue($staff->fresh()->hasPermission('all-business-units'));
        $this->assertTrue(Hash::check('1357', $staff->fresh()->operational_pin));
        $this->actingAs($staff->fresh());
        $this->editRole($staff, 'Manager', ['branch_ids' => [$branch->id], 'business_unit_ids' => [$bar->id]])->assertOk();
        $staff->refresh();
        $this->assertSame('Manager', $staff->role->name);
        $this->assertTrue($staff->canAccessBusinessUnit($bar->id));
        $this->assertFalse($staff->canAccessBusinessUnit($restaurant->id));
        $this->assertFalse($staff->canAccessCounter($counter->id));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $owner->id, 'reference_id' => $staff->id, 'action' => 'STAFF_ROLE_CHANGED']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $staff->id, 'reference_id' => $staff->id, 'action' => 'STAFF_ROLE_CHANGED']);

        $this->actingAs($owner);
        $this->editRole($staff, CompanyAdminService::ROLE)->assertOk();
        $cashier = ['branch_ids' => [$branch->id], 'business_unit_ids' => [$bar->id]];
        $main = StockLocation::where('name', 'Main Counter')->firstOrFail();
        $vip = StockLocation::where('name', 'VIP Counter')->firstOrFail();
        $this->editRole($staff, 'Cashier', $cashier)->assertUnprocessable();
        $this->editRole($staff, 'Cashier', $cashier + ['counter_ids' => [$counter->id]])->assertNotFound();
        $this->editRole($staff, 'Cashier', $cashier + ['counter_ids' => [$main->id]])->assertOk();
        $this->assertTrue($staff->fresh()->canAccessCounter($main->id));
        $this->assertFalse($staff->fresh()->canAccessCounter($vip->id));
        $this->editRole($staff, CompanyAdminService::ROLE)->assertOk();
        $this->assertTrue($staff->fresh()->canAccessCounter($vip->id));
    }
}
