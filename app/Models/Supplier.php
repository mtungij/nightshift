<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $contact
 * @property string|null $category
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Supplier extends TenantModel
{
    protected $table = 'suppliers';

    protected bool $branchScoped = false;

    protected function casts(): array
    {
        return [...parent::casts(), 'active' => 'boolean'];
    }
}
