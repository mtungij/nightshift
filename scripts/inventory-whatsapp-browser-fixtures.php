<?php

use App\Domain\TenantContext;
use App\Models\User;
use App\Services\WhatsApp\SettingsService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/inventory-whatsapp-browser-bootstrap.php';
(new NightshiftDemoSeeder)->run();
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$owner->forceFill(['username' => 'ux-browser-owner', 'operational_pin' => Hash::make('0123'), 'language_preference' => 'en'])->save();
app(TenantContext::class)->run($owner, function () {
    $settings = app(SettingsService::class)->current();
    $settings->update(['device_id' => 'browser-company-device', 'provisioned' => true, 'connection_status' => 'CONNECTED', 'options' => [...config('whatsapp.defaults'), 'enabled' => true, 'daily_report' => true, 'shift_report' => true]]);
});
echo "Isolated inventory / WhatsApp browser fixtures ready. No external requests.\n";
