<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $shift_id
 * @property int $revision
 * @property int $user_id
 * @property string $basis
 * @property string|null $reason
 * @property int|null $approved_by
 * @property CarbonInterface|null $approved_at
 * @property string|null $approval_note
 * @property array<string, mixed> $data
 */
class ShiftStockCount extends TenantModel
{
    protected $table = 'shift_stock_counts';

    protected function casts(): array
    {
        return [...parent::casts(), 'data' => 'array', 'approved_at' => 'datetime'];
    }
}
