<?php

namespace Tests\Feature;

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NightshiftDemoSeeder::class);
        $this->actingAs(User::where('email', 'admin@nightshift.test')->firstOrFail());
    }

    private function createStaff(string $role, int $branch, bool $active = true): int
    {
        $response = $this->postJson('/api/nightshift/commands/catalog-user', [
            'idempotency_key' => (string) Str::uuid(), 'name' => 'New '.$role,
            'email' => Str::uuid().'@staff.test', 'role' => $role, 'branch_id' => $branch,
            'active' => $active, 'pin' => '0123',
        ])->assertOk();

        return $response->json('id');
    }

    public function test_every_role_is_available_in_the_next_scoped_snapshot_and_inactive_is_marked(): void
    {
        $branch = Branch::firstOrFail()->id;
        foreach (config('business.staff_roles') as $role) {
            $id = $this->createStaff($role, $branch);
            $team = $this->getJson('/api/nightshift/bootstrap?branch_id='.$branch)->assertOk()->json('team');
            $staff = collect($team)->firstWhere('id', $id);
            $this->assertSame($role, $staff['role']);
            $this->assertTrue($staff['active']);
        }
        $id = $this->createStaff('Waiter', $branch, false);
        $snapshot = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertFalse(collect($snapshot['team'])->firstWhere('id', $id)['active']);
        $this->assertNotContains($id, array_column($snapshot['servingWaiters'], 'id'));
    }

    public function test_waiter_account_is_immediate_and_pos_eligibility_follows_open_shift(): void
    {
        $branch = Branch::firstOrFail()->id;
        $id = $this->createStaff('Waiter', $branch);
        $snapshot = $this->getJson('/api/nightshift/bootstrap?branch_id='.$branch)->assertOk()->json();
        $this->assertContains($id, array_column($snapshot['team'], 'id'));
        $account = collect($snapshot['waiters'])->firstWhere('id', $id);
        $this->assertNotNull($account);
        $this->assertFalse($account['active']);
        $this->assertSame('0.00', $account['outstanding']);
        $this->assertNotContains($id, array_column($snapshot['servingWaiters'], 'id'));
        $counter = StockLocation::where('type', 'COUNTER')->firstOrFail();
        $this->postJson('/api/nightshift/commands/shift-open', ['idempotency_key' => (string) Str::uuid(), 'user_id' => $id, 'stock_location_id' => $counter->id, 'type' => 'WAITER', 'opening_cash' => '0'])->assertOk();
        $snapshot = $this->getJson('/api/nightshift/bootstrap?branch_id='.$branch)->assertOk()->json();
        $this->assertTrue(collect($snapshot['waiters'])->firstWhere('id', $id)['active']);
        $this->assertTrue(collect($snapshot['servingWaiters'])->firstWhere('id', $id)['pin_configured']);
    }

    public function test_other_branches_and_companies_are_excluded_and_foreign_creation_is_denied(): void
    {
        $branch = Branch::firstOrFail()->id;
        $otherBranch = Branch::create(['name' => 'Other branch']);
        $id = $this->createStaff('Waiter', $otherBranch->id);
        $snapshot = $this->getJson('/api/nightshift/bootstrap?branch_id='.$branch)->assertOk()->json();
        $this->assertNotContains($id, array_column($snapshot['team'], 'id'));
        $this->assertNotContains($id, array_column($snapshot['waiters'], 'id'));
        $company = Company::create(['name' => 'Other company']);
        $foreign = User::factory()->create();
        $foreign->forceFill(['company_id' => $company->id, 'role_id' => Role::where('name', 'Company Admin / Owner')->firstOrFail()->id])->save();
        $foreignBranch = app(TenantContext::class)->run($foreign, fn () => Branch::create(['name' => 'Foreign branch']));
        $foreign->branches()->attach($foreignBranch->id, ['company_id' => $company->id]);
        $snapshot = $this->getJson('/api/nightshift/bootstrap?all_branches=1')->assertOk()->json();
        $this->assertNotContains($foreign->id, array_column($snapshot['team'], 'id'));
        $this->getJson('/api/nightshift/bootstrap?branch_id='.$foreignBranch->id)->assertNotFound();
        $this->postJson('/api/nightshift/commands/catalog-user', ['idempotency_key' => (string) Str::uuid(), 'name' => 'Foreign attempt', 'email' => 'attempt@staff.test', 'role' => 'Waiter', 'pin' => '0123', 'branch_id' => $foreignBranch->id])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'attempt@staff.test']);
        $this->actingAs(User::where('email', 'asha@nightshift.test')->firstOrFail());
        $snapshot = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertSame([], $snapshot['team']);
        $this->assertNotContains($id, array_column($snapshot['waiters'], 'id'));
    }

    public function test_branch_restricted_staff_manager_cannot_list_or_create_outside_assigned_branches(): void
    {
        $branch = Branch::firstOrFail();
        $otherBranch = Branch::create(['name' => 'Restricted branch']);
        $id = $this->createStaff('Waiter', $otherBranch->id);
        $role = Role::create(['name' => 'Scoped staff manager']);
        $role->permissions()->attach(Permission::whereIn('name', ['manage-users', 'view-waiter-balances'])->pluck('id'));
        $manager = User::factory()->create();
        $manager->forceFill(['company_id' => auth()->user()->company_id, 'role_id' => $role->id])->save();
        $manager->branches()->attach($branch->id, ['company_id' => $manager->company_id]);
        $this->actingAs($manager);
        $snapshot = $this->getJson('/api/nightshift/bootstrap?all_branches=1')->assertOk()->json();
        $this->assertNotContains($id, array_column($snapshot['team'], 'id'));
        $this->assertNotContains($id, array_column($snapshot['waiters'], 'id'));
        $this->getJson('/api/nightshift/bootstrap?branch_id='.$otherBranch->id)->assertNotFound();
        $this->postJson('/api/nightshift/commands/catalog-user', ['idempotency_key' => (string) Str::uuid(), 'name' => 'Outside branch', 'email' => 'outside@staff.test', 'role' => 'Waiter', 'pin' => '0123', 'branch_id' => $otherBranch->id])->assertNotFound();
    }
}
