<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $custodian_id
 * @property int $created_by
 * @property int|null $handover_id
 * @property int|null $goods_receipt_id
 * @property int|null $employee_id
 * @property int|null $reversal_of
 * @property string $number
 * @property string $direction
 * @property string $transaction_type
 * @property numeric-string $amount
 * @property string $reference_type
 * @property string|null $reference_id
 * @property string $external_reference
 * @property string|null $category
 * @property string|null $bank
 * @property string|null $pay_period
 * @property string $description
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class TreasuryEntry extends ImmutableModel
{
    protected $table = 'treasury_entries';

    protected function casts(): array
    {
        return [...parent::casts(), 'difference' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
