<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_keeps_username_pin_fields_and_uses_nightshift_identity(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Log in to Nightshift')
            ->assertSee('Enter your username and 4-digit PIN')
            ->assertSee('Ask your company admin to reset a forgotten PIN.')
            ->assertSee('Set up your business')
            ->assertSee('/nightshift.svg', false)
            ->assertSee('name="username"', false)
            ->assertSee('name="pin"', false)
            ->assertSee('pattern="[0-9]{4}"', false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="remember"', false)
            ->assertDontSee('M17.2 5.633', false)
            ->assertDontSee('Laravel');
    }

    public function test_guest_language_switch_renders_requested_kiswahili_labels(): void
    {
        $this->post('/settings/language', ['locale' => 'sw'])->assertRedirect();
        $this->get('/login')->assertOk()->assertSee('Ingia Nightshift')
            ->assertSee('Weka jina lako la mtumiaji na PIN ya tarakimu 4')
            ->assertSee('Jina la Mtumiaji')->assertSee('PIN ya tarakimu 4')
            ->assertSee('Umesahau PIN? Wasiliana na msimamizi wa biashara yako.')
            ->assertSee('Huna akaunti?')->assertSee('Sajili biashara yako')
            ->assertSee('lang="sw"', false);
        $this->post('/settings/language', ['locale' => 'en'])->assertRedirect();
        $this->get('/login')->assertSee('Log in to Nightshift');
    }

    public function test_guest_auth_and_business_pages_share_the_canonical_mark(): void
    {
        foreach (['/login', '/register', '/register-business', '/forgot-password', '/reset-password/branding-token?email=owner%40example.test'] as $url) {
            $this->get($url)->assertOk()->assertSee('/nightshift.svg', false)
                ->assertSee('Nightshift')->assertSee('/manifest.webmanifest', false)
                ->assertDontSee('M17.2 5.633', false)->assertDontSee('Laravel');
        }
    }

    public function test_auth_layout_variants_and_dormant_welcome_page_have_no_starter_branding(): void
    {
        foreach (['simple', 'card', 'split'] as $variant) {
            $html = Blade::render('<x-layouts::auth.'.$variant.'><p>Brand check</p></x-layouts::auth.'.$variant.'>');
            $this->assertStringContainsString('/nightshift.svg', $html);
            $this->assertStringNotContainsString('Laravel', $html);
            $this->assertStringNotContainsString('M17.2 5.633', $html);
        }
        $html = view('welcome')->render();
        $this->assertStringContainsString('Nightshift', $html);
        $this->assertStringNotContainsString('laravel.com', $html);
    }

    public function test_authenticated_settings_keep_the_same_brand(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['/settings/profile', '/settings/appearance', '/user/confirm-password'] as $url) {
            $this->get($url)->assertOk()->assertSee('/nightshift.svg', false)
                ->assertDontSee('laravel.com')->assertDontSee('livewire-starter-kit');
        }
    }

    public function test_normal_error_pages_are_nightshift_branded_and_keep_status_codes(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test-branding-error/{code}', fn (int $code) => abort($code));
        foreach ([401, 402, 403, 404, 419, 429, 500, 503] as $code) {
            $this->get('/_test-branding-error/'.$code)->assertStatus($code)
                ->assertSee('/nightshift.svg', false)->assertSee('Nightshift')
                ->assertDontSee('Laravel');
        }
    }

    public function test_browser_icon_assets_and_manifest_reference_existing_nightshift_icons(): void
    {
        $this->assertSame(file_get_contents(public_path('nightshift.svg')), file_get_contents(public_path('favicon.svg')));
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Nightshift', $manifest['name']);
        foreach ($manifest['icons'] as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($path);
            [$width, $height] = getimagesize($path);
            $this->assertSame($width.'x'.$height, $icon['sizes']);
        }
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('apple-touch-icon.png')), 0, 2));
        $this->assertFileExists(public_path('favicon.ico'));
    }
}
