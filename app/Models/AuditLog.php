<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $branch_id
 * @property int|null $user_id
 * @property string $user_name
 * @property string $action
 * @property string $module
 * @property string $reference_type
 * @property string $reference_id
 * @property array<string, mixed> $old_values
 * @property array<string, mixed> $new_values
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AuditLog extends ImmutableModel
{
    protected $table = 'audit_logs';

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'old_values' => 'array', 'new_values' => 'array'];
    }
}
