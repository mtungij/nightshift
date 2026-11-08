<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Support\UiText;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class FrontendDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_references_real_correctly_sized_nightshift_png_icons(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Nightshift', $manifest['name']);
        $this->assertSame('Nightshift', $manifest['short_name']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        foreach ([192, 512] as $size) {
            $icon = collect($manifest['icons'])->firstWhere('sizes', $size.'x'.$size);
            $this->assertSame('/icons/icon-'.$size.'.png', $icon['src']);
            $this->assertSame('image/png', $icon['type']);
            $image = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame([$size, $size], array_slice($image, 0, 2));
            $this->assertSame('image/png', $image['mime']);
            $this->assertSame("\x89PNG\r\n\x1a\n", substr(file_get_contents(public_path(ltrim($icon['src'], '/'))), 0, 8));
        }
        $this->assertFileExists(public_path('sw.js'));
    }

    public function test_configured_traefik_proxy_generates_https_assets_forms_and_endpoints(): void
    {
        config(['proxy.trusted' => ['172.20.0.0/16'], 'app.url' => 'https://barledger.site', 'app.asset_url' => 'https://barledger.site']);
        Route::get('/_test-frontend-proxy', fn (Request $request) => response()->json(['secure' => $request->isSecure(), 'ip' => $request->ip(), 'logo' => asset('nightshift.svg'), 'manifest' => asset('manifest.webmanifest'), 'login' => route('login'), 'logout' => route('logout'), 'orders' => url('/api/nightshift/my-orders'), 'payments' => url('/api/nightshift/commands/waiter-payment')]));
        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.2', 'HTTP_HOST' => 'barledger.site', 'SERVER_PORT' => 80, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10', 'HTTP_X_FORWARDED_HOST' => 'untrusted.example'])->getJson('http://barledger.site/_test-frontend-proxy')->assertOk()->assertJsonPath('secure', true)->assertJsonPath('ip', '203.0.113.10');
        foreach (['logo', 'manifest', 'login', 'logout', 'orders', 'payments'] as $key) {
            $this->assertStringStartsWith('https://barledger.site/', $response->json($key));
        }
    }

    public function test_untrusted_clients_cannot_spoof_forwarded_ip_or_scheme(): void
    {
        config(['proxy.trusted' => ['172.20.0.0/16'], 'app.url' => 'http://localhost']);
        Route::get('/_test-untrusted-proxy', fn (Request $request) => response()->json(['secure' => $request->isSecure(), 'ip' => $request->ip()]));
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10'])->getJson('/_test-untrusted-proxy')->assertOk()->assertJsonPath('secure', false)->assertJsonPath('ip', '198.51.100.7');
    }

    public function test_canonical_https_cannot_be_downgraded_by_http_backend(): void
    {
        config(['proxy' => [], 'app.url' => 'https://barledger.site']);
        Route::get('/_test-canonical-https', fn () => response()->json(['url' => url('/api/nightshift/my-orders')]));
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8', 'HTTP_HOST' => 'barledger.site', 'SERVER_PORT' => 80])->getJson('http://barledger.site/_test-canonical-https')->assertOk()->assertJsonPath('url', 'https://barledger.site/api/nightshift/my-orders');
    }

    public function test_install_action_translations_and_manifest_links_render_over_https(): void
    {
        config(['app.url' => 'https://barledger.site', 'app.asset_url' => 'https://barledger.site']);
        $this->withServerVariables(['HTTP_HOST' => 'barledger.site'])->get('/login')->assertOk()->assertSee('https://barledger.site/manifest.webmanifest', false)->assertSee('name="theme-color"', false)->assertDontSee('http://barledger.site', false);
        app()->setLocale('en');
        $this->assertSame('Install App', UiText::get('Install App'));
        app()->setLocale('sw');
        $this->assertSame('Sakinisha App', UiText::get('Install App'));
    }

    public function test_invalid_waiter_settlement_leaves_payment_ledger_and_stock_unchanged(): void
    {
        $this->seed(NightshiftDemoSeeder::class);
        $owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
        $this->actingAs($owner);
        $counter = StockLocation::where('name', 'Main Counter')->firstOrFail();
        $waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
        $cashier = User::where('email', 'cashier@nightshift.test')->firstOrFail();
        $this->actingAs($cashier);
        $effects = fn () => [Payment::count(), PaymentAllocation::count(), WaiterLedgerEntry::count(), StockMovement::count(), Order::orderBy('id')->get(['id', 'status', 'amount_paid', 'balance'])->toArray()];
        $before = $effects();
        foreach (['0', '-1', '999999999'] as $amount) {
            $this->postJson('/api/nightshift/commands/waiter-payment', ['idempotency_key' => (string) Str::uuid(), 'waiter_id' => $waiter->id, 'stock_location_id' => $counter->id, 'method' => 'Cash', 'amount' => $amount])->assertUnprocessable();
        }
        $this->assertSame($before, $effects());
    }
}
