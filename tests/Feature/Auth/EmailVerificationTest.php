<?php

namespace Tests\Feature\Auth;

use App\Domain\OperationalShift;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\ShiftService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function staffRoles(): array
    {
        return [
            'owner' => ['Company Admin / Owner', 'view-dashboard'],
            'super admin' => ['Super Admin', 'view-dashboard'],
            'manager' => ['Manager', 'view-dashboard'],
            'cashier' => ['Cashier', 'create-orders'],
            'waiter' => ['Waiter', 'create-orders'],
            'store keeper' => ['Store Keeper', 'manage-inventory'],
            'supervisor' => ['Supervisor', 'view-dashboard'],
        ];
    }

    #[DataProvider('staffRoles')]
    public function test_unverified_staff_can_log_in_and_access_their_workspace(string $role, string $permission): void
    {
        $user = $this->staff($role);
        $this->post(route('login.store'), ['username' => $user->username, 'pin' => '1234', 'remember' => true])
            ->assertSessionHasNoErrors()->assertRedirect(app(OperationalShift::class)->destination($user))
            ->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertOk()
            ->assertJsonPath('user.id', $user->id)->assertJsonPath('role', $role)
            ->assertJsonFragment(['name' => 'Assigned branch']);
        $this->assertTrue($user->hasPermission($permission));
        $this->get('/settings/profile')->assertOk()->assertDontSee('re-send the verification email');
        $this->get('/settings/appearance')->assertOk();
        $this->get('/settings/security')->assertRedirect(route('password.confirm'));
        $this->assertNull($user->fresh()->email_verified_at);
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_verification_routes_and_contract_are_disabled(): void
    {
        $this->assertFalse(Features::enabled(Features::emailVerification()));
        $this->assertNotInstanceOf(MustVerifyEmail::class, new User);
        foreach (['verification.notice', 'verification.verify', 'verification.send'] as $route) {
            $this->assertFalse(Route::has($route));
        }
        $this->actingAs($this->staff('Cashier'));
        $this->get('/verify-email')->assertNotFound();
        $this->post('/email/verification-notification')->assertNotFound();
    }

    public function test_guests_still_cannot_access_staff_pages_or_apis(): void
    {
        foreach (['/dashboard', '/settings/profile', '/settings/appearance', '/settings/security'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
        $this->getJson('/api/nightshift/bootstrap')->assertUnauthorized();
        $this->getJson('/api/nightshift/reports/Sales')->assertUnauthorized();
        $this->getJson('/api/nightshift/receipts/1')->assertUnauthorized();
        $this->postJson('/api/nightshift/commands/order-create', [])->assertUnauthorized();
    }

    public function test_unverified_cashiers_and_waiters_still_have_role_and_tenant_restrictions(): void
    {
        $foreignUser = $this->staff('Company Admin / Owner');
        $foreignBranch = app(TenantContext::class)->run($foreignUser, fn () => $foreignUser->branches()->firstOrFail());
        foreach (['Cashier', 'Waiter'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user);
            $this->getJson('/api/nightshift/bootstrap')->assertOk()
                ->assertJsonPath('company', $user->company->name)
                ->assertJsonPath('team', [])->assertJsonPath('suppliers', []);
            $this->assertFalse($user->hasPermission('manage-users'));
            $this->assertFalse($user->hasPermission('manage-inventory'));
            $this->getJson('/api/nightshift/reports/Sales')->assertForbidden();
            $this->getJson('/api/nightshift/bootstrap?branch_id='.$foreignBranch->id)->assertNotFound();
            $this->assertNull($user->fresh()->email_verified_at);
        }
    }

    public function test_unverified_inactive_staff_still_cannot_access_the_workspace(): void
    {
        $user = $this->staff('Cashier');
        $user->active = false;
        $user->save();
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->getJson('/api/nightshift/bootstrap')->assertForbidden();
    }

    private function staff(string $role): User
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->unverified()->create(['role_id' => Role::where('name', $role)->firstOrFail()->id]);
        app(TenantContext::class)->run($user, function () use ($user): void {
            $branch = Branch::create(['name' => 'Assigned branch']);
            $user->branches()->attach($branch->id, ['company_id' => $user->company_id]);
            $type = app(OperationalShift::class)->type($user);
            if ($type) {
                $counter = StockLocation::create(['branch_id' => $branch->id, 'name' => 'Assigned counter', 'type' => 'COUNTER']);
                app(ShiftService::class)->open(['user_id' => $user->id, 'stock_location_id' => $counter->id, 'type' => $type]);
            }
        });

        return $user;
    }
}
