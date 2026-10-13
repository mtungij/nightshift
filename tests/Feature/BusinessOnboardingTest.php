<?php

namespace Tests\Feature;

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\BusinessOnboardingService;
use App\Services\TenantDefaultDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BusinessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function details(string $suffix = 'one'): array
    {
        return ['business_name' => 'Bar '.$suffix, 'trading_name' => 'Lounge '.$suffix, 'business_type' => 'Lounge', 'business_phone' => '+255700000001', 'business_email' => $suffix.'@bar.test', 'address' => 'Masaki', 'city' => 'Dar es Salaam', 'first_name' => 'Amina', 'last_name' => 'Owner', 'owner_email' => $suffix.'@owner.test', 'owner_phone' => '+255700000002', 'username' => 'owner-'.$suffix, 'pin' => '0123'];
    }

    private function owner(string $suffix = 'one'): User
    {
        return app(BusinessOnboardingService::class)->register([...$this->details($suffix)]);
    }

    /** @param array<string, mixed> $payload */
    private function command(string $command, array $payload): TestResponse
    {
        return $this->postJson('/api/nightshift/commands/'.$command, ['idempotency_key' => (string) Str::uuid(), ...$payload]);
    }

    public function test_public_registration_creates_and_logs_in_unverified_owner_and_all_defaults(): void
    {
        $this->get('/register-business')->assertOk()->assertSee('Set up your bar account')->assertSee('Africa/Dar_es_Salaam');
        $this->post('/register-business', $this->details())->assertSessionHasNoErrors()->assertRedirect('/dashboard#setup');
        $owner = User::where('email', 'one@owner.test')->firstOrFail();
        $this->assertAuthenticatedAs($owner);
        foreach (['companies' => 1, 'users' => 1, 'branches' => 1, 'stock_locations' => 2, 'categories' => 20, 'units' => count(config('business.units'))] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame('Company Admin / Owner', $owner->role->name);
        $this->assertNull($owner->email_verified_at);
        $this->assertSame('Amina Owner', $owner->name);
        $this->assertSame('+255700000002', $owner->phone);
        $company = $owner->company;
        $this->assertSame($owner->id, $company->owner_id);
        $this->assertSame('TZS', $company->currency);
        $this->assertSame('Africa/Dar_es_Salaam', $company->timezone);
        $this->assertSame('Tanzania', $company->country);
        $this->assertSame('English', $company->default_language);
        $this->assertTrue($company->active);
        $this->assertSame('trial', $company->subscription_status);
        $this->assertDatabaseHas('branches', ['company_id' => $company->id, 'name' => 'Main Branch', 'code' => 'MAIN', 'active' => true]);
        $branch = Branch::firstOrFail();
        $this->assertDatabaseHas('branch_user', ['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $owner->id]);
        foreach (['Main Store' => 'STORE', 'Main Counter' => 'COUNTER'] as $name => $type) {
            $this->assertDatabaseHas('stock_locations', ['company_id' => $company->id, 'branch_id' => $branch->id, 'name' => $name, 'type' => $type]);
        }
        $this->assertSame(config('business.categories'), Category::orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(config('business.units'), Unit::orderBy('id')->pluck('name')->all());
        $this->assertSame(20, Category::where('active', true)->count());
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'user_id' => $owner->id, 'action' => 'BUSINESS_ONBOARDED']);
        $this->get('/dashboard')->assertOk();
        $this->get('/settings/business')->assertOk();
    }

    public function test_categories_can_be_deselected_and_retry_keeps_customizations_and_branch_identity(): void
    {
        $this->post('/register-business', [...$this->details(), 'categories_configured' => '1', 'categories' => ['Beer', 'Water']])->assertSessionHasNoErrors();
        $owner = User::firstOrFail();
        $this->assertSame(2, Category::where('active', true)->count());
        Category::where('name', 'Beer')->update(['name' => 'Craft beer', 'color' => '#123456', 'sort_order' => 80]);
        Branch::firstOrFail()->update(['name' => 'Garden', 'code' => 'GARDEN']);
        app(TenantDefaultDataService::class)->seed($owner);
        app(TenantDefaultDataService::class)->seed($owner);
        $this->assertSame(20, Category::count());
        $this->assertSame(count(config('business.units')), Unit::count());
        $this->assertSame(1, Branch::count());
        $this->assertSame(2, StockLocation::count());
        $this->assertSame(2, Category::where('active', true)->count());
        $this->assertSame('#123456', Category::where('name', 'Craft beer')->firstOrFail()->color);
        $this->assertFalse(Category::where('name', 'Beer')->exists());
        $this->assertSame('Garden', Branch::firstOrFail()->name);
        $this->command('catalog-category', ['name' => 'Craft beer'])->assertOk();
        $this->assertSame(20, Category::count());
    }

    public function test_duplicate_business_and_owner_email_are_rejected_without_partial_data(): void
    {
        $this->owner();
        $this->post('/register-business', [...$this->details(), 'owner_email' => 'other@owner.test', 'username' => 'other-owner'])->assertSessionHasErrors('business_name');
        $this->post('/register-business', [...$this->details('two'), 'owner_email' => 'ONE@OWNER.TEST'])->assertSessionHasErrors('owner_email');
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('categories', 20);
    }

    public function test_user_creation_failure_rolls_back_company_defaults_and_uploaded_logo(): void
    {
        Storage::fake('local');
        $events = User::getEventDispatcher();
        User::setEventDispatcher(clone $events);
        User::creating(function (): void {
            throw new \RuntimeException('Simulated owner failure');
        });
        try {
            try {
                app(BusinessOnboardingService::class)->register([...$this->details(), 'logo' => UploadedFile::fake()->image('logo.png')]);
                $this->fail('Expected user creation to fail.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Simulated owner failure', $error->getMessage());
            }
        } finally {
            User::setEventDispatcher($events);
        }
        foreach (['companies', 'users', 'branches', 'stock_locations', 'categories', 'units', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('business-logos'));
    }

    public function test_failure_during_defaults_rolls_back_the_complete_onboarding_operation(): void
    {
        Storage::fake('local');
        $events = Category::getEventDispatcher();
        Category::setEventDispatcher(clone $events);
        Category::creating(function (Category $category): void {
            if ($category->name === 'Wine') {
                $this->assertDatabaseCount('companies', 1);
                $this->assertDatabaseCount('users', 1);
                $this->assertDatabaseCount('branches', 1);
                $this->assertDatabaseCount('stock_locations', 2);
                throw new \RuntimeException('Simulated default failure');
            }
        });
        try {
            try {
                app(BusinessOnboardingService::class)->register([...$this->details(), 'logo' => UploadedFile::fake()->image('logo.png')]);
                $this->fail('Expected default creation to fail.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Simulated default failure', $error->getMessage());
            }
        } finally {
            Category::setEventDispatcher($events);
        }
        foreach (['companies', 'users', 'branches', 'stock_locations', 'categories', 'units', 'branch_user', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('business-logos'));
    }

    public function test_new_company_defaults_do_not_modify_existing_tenants_and_foreign_ids_are_blocked(): void
    {
        $a = $this->owner('a');
        $before = DB::table('companies')->where('id', $a->company_id)->first();
        $aCategory = app(TenantContext::class)->run($a, fn () => Category::firstOrFail());
        $aBranch = app(TenantContext::class)->run($a, fn () => Branch::firstOrFail());
        $b = $this->owner('b');
        $this->assertEquals($before, DB::table('companies')->where('id', $a->company_id)->first());
        foreach ([$a, $b] as $owner) {
            app(TenantContext::class)->run($owner, function () use ($owner): void {
                $this->assertSame(20, Category::count());
                $this->assertSame(count(config('business.units')), Unit::count());
                $this->assertSame([$owner->company_id], Category::distinct()->pluck('company_id')->all());
                $this->assertSame([$owner->company_id], StockLocation::distinct()->pluck('company_id')->all());
            });
        }
        $this->actingAs($b);
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonCount(1, 'team')
            ->assertJsonPath('team.0.id', $b->id)->assertJsonMissing(['email' => $a->email]);
        $this->get('/settings/business')->assertOk()->assertSee($b->company->name)->assertDontSee($a->company->name);
        $this->command('catalog-category', ['id' => $aCategory->id, 'name' => 'Foreign edit'])->assertNotFound();
        $this->command('catalog-branch', ['id' => $aBranch->id, 'name' => 'Foreign branch'])->assertNotFound();
        $this->command('catalog-category-order', ['ids' => [$aCategory->id]])->assertNotFound();
        $this->command('catalog-user', ['name' => 'Foreign staff', 'email' => 'foreign@staff.test', 'password' => 'Nightshift!2026', 'pin' => '0123', 'role' => 'Cashier', 'branch_id' => $aBranch->id])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'foreign@staff.test']);
        $this->assertDatabaseHas('categories', ['id' => $aCategory->id, 'name' => 'Beer']);
    }

    public function test_onboarding_provisions_missing_roles_without_changing_existing_grants(): void
    {
        $manager = Role::create(['name' => 'Manager']);
        $permission = Permission::create(['name' => 'view-reports']);
        $manager->permissions()->attach($permission);
        $existing = User::factory()->create(['role_id' => $manager->id]);
        $before = $existing->company->toArray();
        $owner = $this->owner();
        $this->assertSame([$permission->id], $manager->permissions()->pluck('permissions.id')->all());
        $this->assertSame($before, $existing->company->fresh()->toArray());
        $this->assertTrue($owner->hasPermission('manage-company'));
        $this->assertTrue(Role::where('name', 'Cashier')->exists());
        $this->assertTrue(Role::where('name', 'Waiter')->exists());
    }

    public function test_owner_can_manage_categories_and_progress_persists(): void
    {
        $this->actingAs($this->owner());
        $beer = Category::where('name', 'Beer')->firstOrFail();
        $this->command('catalog-category', ['id' => $beer->id, 'name' => 'Craft beer', 'active' => false, 'icon' => 'glass', 'color' => '#123456', 'sort_order' => 10])->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $beer->id, 'name' => 'Craft beer', 'active' => false, 'color' => '#123456']);
        $ids = Category::orderByDesc('id')->pluck('id')->all();
        $this->command('catalog-category-order', ['ids' => $ids])->assertOk();
        $this->assertSame($ids, Category::orderBy('sort_order')->pluck('id')->all());
        $this->getJson('/api/nightshift/bootstrap')->assertOk()->assertJsonCount(20, 'categoryRecords')->assertJsonCount(19, 'categories')->assertJsonPath('categoryRecords.0.products_count', 0);
        $this->command('catalog-setup', ['step' => 'finish', 'status' => 'complete'])->assertUnprocessable();
        $this->command('catalog-setup', ['step' => 'business', 'status' => 'skip'])->assertUnprocessable();
        foreach (['products', 'staff'] as $step) {
            $this->command('catalog-setup', ['step' => $step, 'status' => 'skip'])->assertOk();
        }
        $this->command('catalog-setup', ['step' => 'finish', 'status' => 'complete'])->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertJsonPath('onboarding.finish', 'complete')->assertJsonPath('onboarding.staff', 'skip');
        $this->command('catalog-setup', ['step' => 'staff', 'status' => 'resume'])->assertOk();
        $this->getJson('/api/nightshift/bootstrap')->assertJsonPath('onboarding.staff', 'resume')->assertJsonPath('onboarding.finish', 'resume');
    }

    public function test_staff_creation_preserves_roles_tenant_and_unverified_login_restrictions(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $branch = Branch::firstOrFail();
        foreach (['Cashier', 'Waiter', 'Manager', 'Supervisor', 'Store Keeper'] as $role) {
            $email = str_replace(' ', '', strtolower($role)).'@staff.test';
            $this->actingAs($owner);
            $this->command('catalog-user', ['name' => $role, 'email' => $email, 'phone' => '+255700000003', 'password' => 'Nightshift!2026', 'pin' => '0123', 'role' => $role, 'branch_id' => $branch->id, 'active' => true])->assertOk();
            $staff = User::where('email', $email)->firstOrFail();
            $this->assertSame($owner->company_id, $staff->company_id);
            $this->assertSame($role, $staff->role->name);
            $this->assertNull($staff->email_verified_at);
            $this->assertSame('+255700000003', $staff->phone);
            $this->assertFalse(Hash::check('Nightshift!2026', $staff->password));
            $this->post('/logout');
            $this->post('/login', ['username' => $staff->username, 'pin' => '0123'])->assertSessionHasNoErrors()->assertRedirect(in_array($role, ['Cashier', 'Waiter']) ? '/open-shift' : '/dashboard');
            if (in_array($role, ['Cashier', 'Waiter'])) {
                $counter = StockLocation::where('type', 'COUNTER')->firstOrFail();
                $payload = ['stock_location_id' => $counter->id];
                if ($role === 'Cashier') {
                    $payload['opening_cash'] = '0';
                }
                $this->post('/open-shift', $payload)->assertSessionHasNoErrors()->assertRedirect('/dashboard#'.($role === 'Waiter' ? 'pos' : 'waiters'));
            }
            $this->get('/dashboard')->assertOk();
            if (in_array($role, ['Cashier', 'Waiter'])) {
                $this->get('/settings/business')->assertForbidden();
                $this->command('catalog-category', ['name' => 'Forbidden'])->assertForbidden();
                $this->command('catalog-user', ['name' => 'Escalate', 'email' => 'bad@staff.test', 'pin' => '0123', 'role' => 'Manager', 'branch_id' => $branch->id])->assertForbidden();
            }
        }
        $this->actingAs($owner);
        foreach (['Super Admin', 'Company Admin / Owner'] as $role) {
            $this->command('catalog-user', ['name' => 'Escalate', 'email' => 'bad@staff.test', 'password' => 'Nightshift!2026', 'role' => $role, 'branch_id' => $branch->id])->assertUnprocessable();
        }
        $this->assertDatabaseMissing('users', ['email' => 'bad@staff.test']);
        $this->command('catalog-user', ['name' => 'Inactive', 'email' => 'inactive@staff.test', 'password' => 'Nightshift!2026', 'pin' => '0123', 'role' => 'Cashier', 'branch_id' => $branch->id, 'active' => false])->assertOk();
        $this->actingAs(User::where('email', 'inactive@staff.test')->firstOrFail())->get('/dashboard')->assertForbidden();
    }

    public function test_profile_logo_and_metadata_update_only_own_business_and_guests_are_blocked(): void
    {
        Storage::fake('local');
        $a = $this->owner('a');
        $b = $this->owner('b');
        $this->actingAs($a);
        $this->post('/settings/business', [...$this->details('a'), 'business_name' => 'Updated', 'currency' => 'USD', 'timezone' => 'UTC', 'country' => 'Tanzania', 'default_language' => 'English', 'tin' => '123', 'vrn' => '456', 'receipt_header' => 'Welcome', 'receipt_footer' => 'Come again', 'logo' => UploadedFile::fake()->image('logo.png'), 'company_id' => $b->company_id])->assertSessionHasNoErrors()->assertRedirect('/settings/business');
        $this->assertSame('Updated', $a->company->fresh()->name);
        $this->assertSame('Bar b', $b->company->fresh()->name);
        $this->assertSame('USD', $a->company->fresh()->currency);
        Storage::disk('local')->assertExists($a->company->fresh()->logo_path);
        $this->get('/business/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($b)->get('/business/logo')->assertNotFound();
        $this->post('/logout');
        $this->get('/settings/business')->assertRedirect('/login');
        $this->get('/business/logo')->assertRedirect('/login');
        $this->get('/setup')->assertRedirect('/login');
    }

    public function test_invalid_business_inputs_and_logo_are_rejected_without_data(): void
    {
        $this->post('/register-business', [...$this->details(), 'logo' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml'), 'timezone' => 'Invalid/Zone', 'currency' => '<x>', 'business_type' => 'Invalid', 'pin' => '123'])->assertSessionHasErrors(['logo', 'timezone', 'currency', 'business_type', 'pin']);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
