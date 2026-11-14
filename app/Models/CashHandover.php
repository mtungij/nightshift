<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $stock_location_id
 * @property int $source_shift_id
 * @property int $from_user_id
 * @property int|null $to_user_id
 * @property int $created_by
 * @property int|null $replaces_id
 * @property string $number
 * @property string $destination
 * @property numeric-string $amount
 * @property string|null $basis
 * @property string|null $note
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CashHandover extends ImmutableModel
{
    protected $table = 'cash_handovers';

    /** @return HasOne<CashHandoverEvent, $this> */
    public function latestEvent(): HasOne
    {
        return $this->hasOne(CashHandoverEvent::class, 'handover_id')->latestOfMany();
    }

    protected function casts(): array
    {
        return [...parent::casts(), 'difference' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
