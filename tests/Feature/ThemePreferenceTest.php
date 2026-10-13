<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_and_existing_users_without_a_preference_render_light(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->theme_preference);
        $this->assertSame('light', $user->preferredTheme());
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('data-theme="light"', false)->assertDontSee('class="dark"', false);
    }

    public function test_dark_preference_is_saved_and_rendered_before_assets(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/settings/theme', ['theme' => 'dark'])->assertOk()->assertJsonPath('theme', 'dark');
        $this->assertSame('dark', $user->fresh()->theme_preference);
        $html = $this->get('/dashboard')->assertOk()->assertSee('class="dark" data-theme="dark"', false)->getContent();
        $this->assertLessThan(strpos($html, '/build/'), strpos($html, 'const initial ='));
        $this->putJson('/settings/theme', ['theme' => 'light'])->assertOk();
        $this->get('/dashboard')->assertOk()->assertSee('data-theme="light"', false);
    }

    public function test_theme_preference_is_scoped_to_the_authenticated_user(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first)->putJson('/settings/theme', ['theme' => 'dark', 'user_id' => $second->id])->assertOk();
        $this->assertSame('dark', $first->fresh()->preferredTheme());
        $this->assertNull($second->fresh()->theme_preference);
        $this->actingAs($second)->get('/dashboard')->assertOk()->assertSee('data-theme="light"', false);
        $this->actingAs($first)->get('/settings/appearance')->assertOk()->assertSee('data-theme-choice="dark"', false)->assertDontSee('value="system"', false);
    }

    public function test_invalid_themes_do_not_change_a_saved_preference(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/settings/theme', ['theme' => 'dark'])->assertOk();
        foreach (['system', 'invalid', '', null] as $theme) {
            $this->putJson('/settings/theme', ['theme' => $theme])->assertUnprocessable()->assertJsonValidationErrors('theme');
            $this->assertSame('dark', $user->fresh()->theme_preference);
        }
    }

    public function test_guests_get_light_and_cannot_save_account_preferences(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-theme="light"', false)->assertDontSee('class="dark"', false);
        $this->putJson('/settings/theme', ['theme' => 'dark'])->assertUnauthorized();
    }

    public function test_fresh_logins_use_account_preferences_and_new_users_start_light(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $login = function (User $user): void {
            $this->post(route('login.store'), ['username' => $user->username, 'pin' => '1234'])
                ->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        };

        $login($first);
        $this->get('/dashboard')->assertOk()->assertSee('data-theme="light"', false);
        $this->putJson('/settings/theme', ['theme' => 'dark'])->assertOk();
        $this->post(route('logout'))->assertRedirect();
        $this->get('/login')->assertOk()->assertSee('data-theme="light"', false);

        $login($second);
        $this->get('/dashboard')->assertOk()->assertSee('data-theme="light"', false);
        $this->assertNull($second->fresh()->theme_preference);
        $this->post(route('logout'))->assertRedirect();

        $login($first);
        $this->get('/dashboard')->assertOk()->assertSee('class="dark" data-theme="dark"', false);
    }
}
