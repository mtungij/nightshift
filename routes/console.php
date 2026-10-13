<?php

use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\Scheduler;
use App\Services\WhatsApp\SettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(Scheduler::class)->tick())
    ->name('whatsapp-company-scheduler')->everyMinute()->withoutOverlapping(5);

Artisan::command('whatsapp:assign-device {company} {device}', function () {
    $companyId = (int) $this->argument('company');
    $device = $this->argument('device');
    if (! is_string($device)) {
        $this->error('Invalid device ID.');

        return 1;
    }
    if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D', $device)) {
        $this->error('Invalid device ID.');

        return 1;
    }
    if (WhatsAppSetting::withoutGlobalScopes()->where('device_id', $device)->where('company_id', '!=', $companyId)->exists()) {
        $this->error('Device is assigned to another company.');

        return 1;
    }
    app(CompanyContext::class)->run($companyId, function () use ($device) {
        app(SettingsService::class)->current()->update(['device_id' => $device, 'provisioned' => true, 'connection_status' => 'DISCONNECTED', 'jid' => null, 'last_checked_at' => null]);
    });
    $this->info('Existing provider device assigned. Run Test Connection in company settings.');

    return 0;
})->purpose('Assign an existing Gowa slot to one company without exposing other server devices.');
