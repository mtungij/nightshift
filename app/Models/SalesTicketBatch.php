<?php

namespace App\Models;

use Carbon\CarbonInterface;

/**
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $order_id
 * @property int $user_id
 * @property string $token
 * @property CarbonInterface|null $dispatched_at
 * @property bool $reprint
 * @property array<string, mixed> $ticket
 * @property list<string> $copies
 */
class SalesTicketBatch extends TenantModel
{
    protected function casts(): array
    {
        return [...parent::casts(), 'ticket' => 'array', 'copies' => 'array', 'reprint' => 'boolean', 'dispatched_at' => 'datetime'];
    }
}
