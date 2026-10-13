<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $waiter_id
 * @property int $shift_id
 * @property int $order_id
 * @property int|null $payment_id
 * @property int|null $return_id
 * @property string $entry_type
 * @property string $reference_type
 * @property int $reference_id
 * @property numeric-string $debit
 * @property numeric-string $credit
 * @property string $description
 * @property int $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class WaiterLedgerEntry extends ImmutableModel
{
    protected $table = 'waiter_ledger_entries';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
