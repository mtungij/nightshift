<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int|null $cashier_shift_id
 * @property int|null $opened_by
 * @property-read string $operating_date
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $user_id
 * @property int $stock_location_id
 * @property string $number
 * @property string $type
 * @property string $status
 * @property CarbonInterface $opened_at
 * @property CarbonInterface|null $closed_at
 * @property numeric-string $opening_cash
 * @property numeric-string $expected_cash
 * @property numeric-string $actual_cash
 * @property numeric-string $cash_difference
 * @property string|null $closing_notes
 * @property string|null $override_reason
 * @property int|null $authorized_by
 * @property CarbonInterface|null $override_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Shift extends TenantModel
{
    protected $table = 'shifts';

    protected bool $branchScoped = true;

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (Shift $shift): void {
            if ($shift->getRawOriginal('status') === 'CLOSED') {
                throw new \LogicException('Finalized shifts cannot be edited. Use an audited correction.');
            }
        });
        static::deleting(function (Shift $shift): void {
            throw new \LogicException('Shift history cannot be deleted.');
        });
    }

    protected $appends = ['operating_date'];

    public function getOperatingDateAttribute(): string
    {
        return $this->opened_at->copy()->timezone(config('app.timezone'))->toDateString();
    }

    protected function casts(): array
    {
        return [...parent::casts(), 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'override_at' => 'datetime'];
    }
}
