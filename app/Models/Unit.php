<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Unit extends TenantModel
{
    protected $table = 'units';

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
