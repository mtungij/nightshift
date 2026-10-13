<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property string $name
 * @property string $area
 * @property int $seats
 * @property array<string, mixed>|null $reservation
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ServiceTable extends TenantModel
{
    protected $table = 'service_tables';

    protected bool $branchScoped = true;

    protected function casts(): array
    {
        return [...parent::casts(), 'reservation' => 'array'];
    }
}
