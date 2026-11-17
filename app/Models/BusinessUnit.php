<?php

namespace App\Models;

use App\Domain\OrderCollectionMode;
use App\Services\StockService;
use App\Services\UnitOperationalService;
use Illuminate\Support\Facades\Schema;

/**
 * @property int|null $created_by
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property string $name
 * @property string $code
 * @property int|null $default_stock_location_id
 * @property int $max_active_cashiers
 * @property bool $advanced_locations_enabled
 * @property string|null $location_cutover_note
 * @property OrderCollectionMode $order_collection_mode
 * @property \Carbon\CarbonInterface|null $archived_at
 * @property bool $active
 */
class BusinessUnit extends TenantModel
{
    protected $attributes = ['max_active_cashiers' => 1, 'advanced_locations_enabled' => false, 'order_collection_mode' => OrderCollectionMode::WaiterAccount->value];

    protected static function booted(): void
    {
        parent::booted();
        static::saving(function (BusinessUnit $unit) {
            // Reserve the retired identity for migration and retained historical references.
            if ($unit->isDirty(['name', 'code'])) {
                app(StockService::class)->require(strtoupper(trim($unit->code)) !== 'GENERAL' && strtoupper(trim($unit->name)) !== 'GENERAL', __('ui.This Business Unit name or code is reserved.'));
            }
        });
        static::created(function (BusinessUnit $unit) {
            if ($unit->active && Schema::hasColumn('business_units', 'default_stock_location_id')) {
                app(UnitOperationalService::class)->initializeCreatedUnit($unit);
                $unit->refresh();
            }
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'order_collection_mode' => OrderCollectionMode::class, 'active' => 'boolean', 'max_active_cashiers' => 'integer', 'advanced_locations_enabled' => 'boolean'];
    }
}
