<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $handover_id
 * @property int $created_by
 * @property int|null $related_shift_id
 * @property int|null $ledger_entry_id
 * @property string $status
 * @property numeric-string|null $amount
 * @property numeric-string|null $difference
 * @property string|null $note
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CashHandoverEvent extends ImmutableModel
{
    protected $table = 'cash_handover_events';

    protected function casts(): array
    {
        return [...parent::casts(), 'difference' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
