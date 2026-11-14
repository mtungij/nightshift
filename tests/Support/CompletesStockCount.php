<?php

namespace Tests\Support;

use App\Models\Shift;
use App\Services\ShiftService;
use App\Services\ShiftStockService;

trait CompletesStockCount
{
    use CompletesCashDisposition;

    protected function completeStockCount(Shift $shift, ?string $actualCash = null, bool $disposition = true): void
    {
        if ($shift->type !== 'CASHIER') {
            return;
        }
        $service = app(ShiftStockService::class);
        $report = $service->report($shift->id);
        $service->count($shift->id, ['actual_cash' => $actualCash ?? app(ShiftService::class)->expected($shift), 'basis' => $report['basis'], 'revision' => $report['revision'], 'counts' => array_map(fn ($row) => ['product_id' => $row['product_id'], 'quantity' => $row['expected']], $report['rows'])]);
        if ($disposition) {
            $this->prepareCashDisposition($shift);
        }
    }
}
