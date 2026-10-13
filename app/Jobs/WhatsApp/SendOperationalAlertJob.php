<?php

namespace App\Jobs\WhatsApp;

use App\Models\AuditLog;
use App\Services\TransactionService;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\OperationalAlerts;
use App\Services\WhatsApp\SettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendOperationalAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $companyId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(CompanyContext $context): void
    {
        $context->run($this->companyId, function () {
            // Serialize consumers per company and advance only after durable outbox writes.
            app(TransactionService::class)->run(function () {
                $settings = app(SettingsService::class)->current();
                $options = $settings->preferences();
                if (! $options['enabled']) {
                    return;
                }
                foreach (AuditLog::where('id', '>', $settings->audit_cursor)->orderBy('id')->limit(100)->get() as $audit) {
                    if (in_array($audit->action, config('whatsapp.audit_actions'), true)) {
                        app(OperationalAlerts::class)->inspect($audit, $options);
                    }
                    $settings->update(['audit_cursor' => $audit->id]);
                }
            });
        });
    }
}
