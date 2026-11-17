<?php

namespace App\Models;

use App\Services\StockService;
use Carbon\CarbonInterface;

/**
 * @property int $max_active_cashiers
 * @property-read int $active_cashiers
 * @property bool $system_managed
 * @property bool $is_shared
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property string $name
 * @property string $type
 * @property CarbonInterface|null $archived_at
 * @property bool $active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class StockLocation extends TenantModel
{
    protected $table = 'stock_locations';

    protected $attributes = ['max_active_cashiers' => 1];

    protected $appends = ['active_cashiers'];

    public function getActiveCashiersAttribute(): int
    {
        return Shift::withoutGlobalScopes()->where('company_id', $this->company_id)->where('stock_location_id', $this->id)->where('type', 'CASHIER')->where('status', 'OPEN')->count();
    }

    protected bool $branchScoped = true;

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (StockLocation $location) {
            if ($location->isDirty('name')) {
                app(StockService::class)->require(strtoupper(trim($location->name)) !== 'GENERAL STOCK', __('ui.This stock location name is reserved.'));
            }
        });
    }

    protected function casts(): array
    {
        return [...parent::casts(), 'archived_at' => 'datetime', 'active' => 'boolean', 'system_managed' => 'boolean', 'max_active_cashiers' => 'integer'];
    }
}
