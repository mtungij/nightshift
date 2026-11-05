<?php

namespace App\Models;

/**
 * @property int $id
 * @property int $user_id
 * @property int $shift_id
 * @property int $company_id
 * @property int $branch_id
 * @property int $stock_location_id
 * @property string $kind
 * @property array<string, mixed> $data
 */
class ShiftStockSnapshot extends ImmutableModel
{
    protected $table = 'shift_stock_snapshots';

    protected function casts(): array
    {
        return [...parent::casts(), 'data' => 'array'];
    }
}
