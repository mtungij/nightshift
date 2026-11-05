<?php

namespace Tests\Support;

use App\Models\Shift;
use App\Services\ShiftStockService;

trait CompletesStockCount
{
    protected function completeStockCount(Shift $shift): void
    {
        if ($shift->type !== 'CASHIER') {
            return;
        }
        $service = app(ShiftStockService::class);
        $report = $service->report($shift->id);
        $service->count($shift->id, ['basis' => $report['basis'], 'revision' => $report['revision'], 'counts' => array_map(fn ($row) => ['product_id' => $row['product_id'], 'quantity' => $row['expected']], $report['rows'])]);
    }
}
