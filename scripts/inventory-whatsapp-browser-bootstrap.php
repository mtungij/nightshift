<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Set the test cache before Fortify constructs its shared rate limiter.
$_ENV['CACHE_STORE'] = 'array';
$_SERVER['CACHE_STORE'] = 'array';
putenv('CACHE_STORE=array');

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== storage_path('framework/testing/nightshift-ux-browser.sqlite')) {
    throw new RuntimeException('Browser fixtures require the dedicated nightshift-ux-browser.sqlite testing database.');
}
// No real credentials and no external requests in browser validation.
config(['cache.default' => 'array', 'gowa.password' => 'browser-fake-password', 'gowa.username' => 'browser-fake-user']);
Http::preventStrayRequests();
Http::fake([
    'notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['state' => 'logged_in', 'is_logged_in' => true, 'is_connected' => true, 'jid' => '255629123456@s.whatsapp.net']]),
    'notify.buildcore.site/devices/*/login/code*' => Http::response(['code' => 'SUCCESS', 'results' => ['pair_code' => 'ABCD-1234']]),
    'notify.buildcore.site/devices/*/login' => Http::response(['code' => 'SUCCESS', 'results' => ['qr_link' => 'https://notify.buildcore.site/statics/images/qrcode/browser.png', 'qr_duration' => 30]]),
    'notify.buildcore.site/statics/images/qrcode/browser.png' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='), 200, ['Content-Type' => 'image/png']),
    'notify.buildcore.site/devices/*/reconnect' => Http::response(['code' => 'SUCCESS', 'results' => []]),
    'notify.buildcore.site/devices' => Http::response(['code' => 'SUCCESS', 'results' => []]),
    'notify.buildcore.site/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'BROWSER-FAKE-MESSAGE']]),
]);
