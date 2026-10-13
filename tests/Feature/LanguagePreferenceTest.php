<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\UiText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LanguagePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_default_to_english_and_can_switch_to_kiswahili(): void
    {
        $this->get('/login')->assertOk()->assertSee('lang="en"', false)->assertSee('Log in to your account');
        $this->postJson('/settings/language', ['locale' => 'sw'])->assertOk()->assertJsonPath('locale', 'sw');
        $this->get('/login')->assertOk()->assertSee('lang="sw"', false)->assertSee('Ingia kwenye akaunti yako');
        $this->get('/register-business')->assertOk()->assertSee('Sajili akaunti ya baa yako');
        $this->postJson('/settings/language', ['locale' => 'en'])->assertOk();
        $this->get('/login')->assertSee('Log in to your account');
    }

    public function test_user_language_persists_and_is_rendered_before_assets(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/settings/language', ['locale' => 'sw'])->assertOk()->assertJsonPath('messages.Send to cashier', 'Tuma kwa Keshia');
        $this->assertSame('sw', $user->fresh()->language_preference);
        $html = $this->get('/dashboard')->assertOk()->assertSee('lang="sw"', false)->getContent();
        $this->assertLessThan(strpos($html, '/build/'), strpos($html, 'window.NightshiftI18n'));
        $this->assertSame('Tuma kwa Keshia', UiText::get('Send to cashier'));
        $this->assertSame('Hesabu za Wahudumu', UiText::get('Waiter accounts'));
        $this->assertSame('PIN si sahihi.', UiText::get('The PIN could not be confirmed.'));
    }

    public function test_saved_account_language_wins_after_login(): void
    {
        $user = User::factory()->create(['language_preference' => 'sw']);
        $this->postJson('/settings/language', ['locale' => 'en'])->assertOk();
        $this->post('/login', ['username' => $user->username, 'pin' => '1234'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('lang="sw"', false);
        $this->assertSame('sw', $user->fresh()->language_preference);
    }

    public function test_first_login_can_keep_guest_selected_language(): void
    {
        $user = User::factory()->create();
        $this->postJson('/settings/language', ['locale' => 'sw'])->assertOk();
        $this->post('/login', ['username' => $user->username, 'pin' => '1234'])->assertRedirect('/dashboard');
        $this->assertSame('sw', $user->fresh()->language_preference);
        $this->get('/dashboard')->assertSee('lang="sw"', false);
    }

    public function test_a_company_cannot_change_another_user_language(): void
    {
        $firstCompany = Company::create(['name' => 'A', 'active' => true]);
        $secondCompany = Company::create(['name' => 'B', 'active' => true]);
        $first = User::factory()->create(['company_id' => $firstCompany->id]);
        $second = User::factory()->create(['company_id' => $secondCompany->id, 'language_preference' => 'en']);
        $this->actingAs($first)->postJson('/settings/language', ['locale' => 'sw', 'user_id' => $second->id])->assertUnprocessable();
        $this->assertNull($first->fresh()->language_preference);
        $this->assertSame('en', $second->fresh()->language_preference);
        $this->postJson('/settings/language', ['locale' => 'sw'])->assertOk();
        $this->assertSame('sw', $first->fresh()->language_preference);
        $this->assertSame('en', $second->fresh()->language_preference);
    }

    public function test_invalid_preferences_are_rejected_without_mutation(): void
    {
        $user = User::factory()->create(['language_preference' => 'sw']);
        $this->actingAs($user);
        foreach (['fr', '', null, '<script>'] as $value) {
            $response = $this->postJson('/settings/language', ['locale' => $value])->assertUnprocessable()->assertJsonValidationErrors('locale');
            $this->assertStringNotContainsString('validation.', $response->json('errors.locale.0'));
            $this->assertSame('sw', $user->fresh()->language_preference);
        }
    }

    public function test_unconfigured_accounts_use_company_default_without_inheriting_other_accounts(): void
    {
        $company = Company::create(['name' => 'Swahili Bar', 'default_language' => 'Swahili', 'active' => true]);
        $sw = User::factory()->create(['company_id' => $company->id]);
        $en = User::factory()->create();
        $this->withSession(['locale' => 'sw'])->actingAs($en)->get('/dashboard')->assertSee('lang="en"', false);
        $this->actingAs($sw)->get('/dashboard')->assertSee('lang="sw"', false);
    }

    public function test_catalogs_have_matching_keys_and_no_markup(): void
    {
        $en = require lang_path('en/ui.php');
        $sw = require lang_path('sw/ui.php');
        $this->assertSame(array_keys($en), array_keys($sw));
        $this->assertNotContains('', array_values($sw));
        $this->assertStringNotContainsString('<script', implode('', $sw));
        app()->setLocale('sw');
        $this->assertSame('Custom business name', UiText::get('Custom business name'));
    }
}
