<?php

namespace App\Services;

use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\BusinessNotification;

final class AuditService
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(string $action, string $module, string $type, string|int $id, array $old = [], array $new = [], ?int $branch = null): AuditLog
    {
        $actor = app(TenantContext::class)->user();

        return AuditLog::create(['branch_id' => $branch, 'user_id' => $actor->id, 'user_name' => $actor->name, 'action' => $action, 'module' => $module, 'reference_type' => $type, 'reference_id' => (string) $id, 'old_values' => $old, 'new_values' => $new, 'ip' => request()->ip(), 'user_agent' => request()->userAgent()]);
    }

    public function notify(int $branch, string $type, string $title, string $message, string $screen, ?int $user = null, ?int $counter = null): void
    {
        BusinessNotification::create(['branch_id' => $branch, 'user_id' => $user, 'stock_location_id' => $counter, 'type' => $type, 'title' => $title, 'message' => $message, 'screen' => $screen, 'read_by' => []]);
    }
}
