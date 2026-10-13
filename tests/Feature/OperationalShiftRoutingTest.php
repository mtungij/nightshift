<?php

namespace Tests\Feature;

use App\Domain\OperationalShift;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\ShiftService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalShiftRoutingTest extends TestCase
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
        $this->counter = StockLocation::where('name', 'VIP Counter')->firstOrFail();
    }

    public static function staffRoles(): array
    {
        return [['Waiter', 'WAITER', 'pos'], ['Cashier', 'CASHIER', 'waiters']];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['company_id' => $this->owner->company_id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'operational_pin' => Hash::make('0123')]);
        $user->branches()->attach($this->counter->branch_id, ['company_id' => $user->company_id]);

        return $user;
    }

    #[DataProvider('staffRoles')]
    public function test_login_without_shift_ignores_intended_workspace_and_requires_shift(string $role, string $type, string $screen): void
    {
        $user = $this->staff($role);
        auth()->forgetUser();
        $this->withSession(['url.intended' => '/dashboard#'.$screen])->post('/login', ['username' => $user->username, 'pin' => '0123'])->assertRedirect('/open-shift');
        $this->get('/open-shift')->assertOk()->assertSee('Open your shift to start working.')->assertSee($this->counter->name);
        $this->get('/dashboard#'.$screen)->assertRedirect('/open-shift');
        $this->getJson('/api/nightshift/bootstrap')->assertStatus(409)->assertJsonPath('shift_required', true)->assertJsonMissingPath('orders');
        $this->get('/api/nightshift/receipts/1')->assertStatus(409);
        $this->postJson('/api/nightshift/commands/order-create', ['idempotency_key' => (string) Str::uuid()])->assertStatus(409);
    }

    #[DataProvider('staffRoles')]
    public function test_existing_open_shift_goes_to_work_area_and_cannot_overlap(string $role, string $type, string $screen): void
    {
        $user = $this->staff($role);
        $shift = app(ShiftService::class)->open(['user_id' => $user->id, 'stock_location_id' => $this->counter->id, 'type' => $type, 'opening_cash' => '75000']);
        auth()->forgetUser();
        $this->post('/login', ['username' => $user->username, 'pin' => '0123'])->assertRedirect('/dashboard#'.$screen);
        $this->get('/open-shift')->assertRedirect('/dashboard#'.$screen);
        $this->post('/open-shift', ['stock_location_id' => $this->counter->id, 'opening_cash' => '999'])->assertRedirect('/dashboard#'.$screen);
        $this->assertSame(1, Shift::where('user_id', $user->id)->where('status', 'OPEN')->count());
        $this->postJson('/api/nightshift/commands/shift-open', ['idempotency_key' => (string) Str::uuid(), 'user_id' => $user->id, 'stock_location_id' => $this->counter->id, 'type' => $type, 'opening_cash' => '0'])->assertUnprocessable();
        $data = $this->getJson('/api/nightshift/bootstrap')->assertOk()->json();
        $this->assertSame($shift->id, $data['shift']['id']);
        $this->assertSame($this->counter->id, $data['location_id']);
        if ($role === 'Cashier') {
            $this->assertSame([$this->counter->id], array_column($data['locations'], 'id'));
        }
    }

    #[DataProvider('staffRoles')]
    public function test_open_and_close_flow_changes_backend_access(string $role, string $type, string $screen): void
    {
        $user = $this->staff($role);
        $this->actingAs($user);
        $payload = ['stock_location_id' => $this->counter->id];
        if ($role === 'Cashier') {
            $this->post('/open-shift', $payload)->assertSessionHasErrors('opening_cash');
            $payload['opening_cash'] = '25000.50';
        }
        $this->post('/open-shift', $payload)->assertSessionHasNoErrors()->assertRedirect('/dashboard#'.$screen);
        $this->get('/dashboard')->assertOk();
        $shift = Shift::where('user_id', $user->id)->where('status', 'OPEN')->firstOrFail();
        $this->assertSame($type, $shift->type);
        $this->postJson('/api/nightshift/commands/shift-close', ['idempotency_key' => (string) Str::uuid(), 'id' => $shift->id, 'actual_cash' => $shift->opening_cash])->assertOk();
        $this->get('/dashboard#'.$screen)->assertRedirect('/open-shift');
        $this->getJson('/api/nightshift/bootstrap')->assertStatus(409);
        $this->get('/open-shift')->assertOk();
    }

    public function test_counter_branch_company_role_and_cash_permissions_are_enforced(): void
    {
        $branch = Branch::create(['name' => 'Unassigned branch']);
        $outside = StockLocation::create(['branch_id' => $branch->id, 'name' => 'Unassigned counter', 'type' => 'COUNTER']);
        $user = $this->staff('Cashier');
        $this->actingAs($user);
        $this->get('/open-shift')->assertOk()->assertDontSee('Unassigned counter');
        $this->post('/open-shift', ['stock_location_id' => $outside->id, 'opening_cash' => '0'])->assertNotFound();
        $this->post('/open-shift', ['stock_location_id' => $this->counter->id, 'opening_cash' => '-1'])->assertSessionHasErrors('opening_cash');
        $this->post('/open-shift', ['stock_location_id' => $this->counter->id, 'opening_cash' => '0', 'user_id' => $this->owner->id])->assertSessionHasErrors('user_id');
        $this->postJson('/api/nightshift/commands/shift-open', ['idempotency_key' => (string) Str::uuid(), 'stock_location_id' => $this->counter->id, 'type' => 'WAITER', 'opening_cash' => '0'])->assertUnprocessable();
        $this->assertSame(0, Shift::where('user_id', $user->id)->count());
    }

    public function test_stale_branch_assignment_cannot_hide_an_overlapping_shift(): void
    {
        $user = $this->staff('Cashier');
        app(ShiftService::class)->open(['user_id' => $user->id, 'stock_location_id' => $this->counter->id, 'type' => 'CASHIER']);
        $branch = Branch::create(['name' => 'Reassigned branch']);
        $counter = StockLocation::create(['branch_id' => $branch->id, 'name' => 'Reassigned counter', 'type' => 'COUNTER']);
        $user->branches()->sync([$branch->id => ['company_id' => $user->company_id]]);
        $this->actingAs($user);
        $this->assertNull(app(OperationalShift::class)->active($user));
        $this->post('/open-shift', ['stock_location_id' => $counter->id, 'opening_cash' => '0'])->assertSessionHasErrors('transaction');
        $this->assertSame(1, Shift::withoutGlobalScopes()->where('user_id', $user->id)->where('status', 'OPEN')->count());
    }

    public function test_management_routing_is_unchanged_and_inactive_counter_is_not_a_work_shift(): void
    {
        foreach (['Company Admin / Owner', 'Manager', 'Supervisor', 'Store Keeper'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get('/dashboard')->assertOk();
            $this->get('/open-shift')->assertRedirect('/dashboard');
        }
        $this->actingAs($this->owner);
        $user = $this->staff('Cashier');
        app(ShiftService::class)->open(['user_id' => $user->id, 'stock_location_id' => $this->counter->id, 'type' => 'CASHIER']);
        $this->counter->update(['active' => false]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/open-shift');
        $this->get('/open-shift')->assertDontSee('VIP Counter');
    }
}
