<?php

namespace App\Jobs\WhatsApp;

use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\GowaException;
use App\Services\WhatsApp\SettingsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CheckGowaDeviceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $companyId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(CompanyContext $context): void
    {
        try {
            $context->run($this->companyId, fn () => app(SettingsService::class)->check(app(SettingsService::class)->current()));
        } catch (GowaException) {
            // SettingsService persists the disconnected state with sanitized errors.
        }
    }
}
