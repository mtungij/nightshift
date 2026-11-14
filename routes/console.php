<?php

use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\Scheduler;
use App\Services\WhatsApp\SettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

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
    try {
        app(CompanyContext::class)->run($companyId, fn () => app(SettingsService::class)->assignDevice($device));
    } catch (ValidationException) {
        $this->error('Invalid device ID or device assigned to another company.');

        return 1;
    }
    $this->info('Existing provider device assigned. Run Test Connection in company settings.');

    return 0;
})->purpose('Assign an existing Gowa slot to one company without exposing other server devices.');
