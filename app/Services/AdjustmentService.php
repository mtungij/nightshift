<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\Gate;

final class AdjustmentService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(array $data): StockAdjustment
    {
        Gate::authorize('adjust-stock');

        return $this->tx->run(function () use ($data) {
            $location = $this->stock->location($data['stock_location_id']);
            $a = StockAdjustment::create(['branch_id' => $location->branch_id, 'stock_location_id' => $location->id, 'number' => $this->numbers->next('ADJ'), 'reason' => $data['reason'], 'notes' => $data['notes'], 'requested_by' => app(TenantContext::class)->user()->id]);
            foreach ($data['items'] as $line) {
                $u = $this->stock->unit($line['product_unit_id']);
                $qty = D::quantity($line['quantity']);
                $this->stock->require(D::cmp($qty, '0') > 0, 'Adjustment quantity must be positive.');
                $base = D::mul($qty, $u->conversion_quantity, 6);
                $a->items()->create(['branch_id' => $a->branch_id, 'product_id' => $u->product_id, 'product_unit_id' => $u->id, 'quantity' => $qty, 'base_quantity' => $base, 'cost' => D::mul($base, $u->product->purchase_cost)]);
            }
            $this->audit->record('ADJUSTMENT_REQUESTED', 'Inventory', 'StockAdjustment', $a->id, [], ['reason' => $a->reason, 'status' => 'PENDING'], $a->branch_id);
            $this->audit->notify($a->branch_id, 'ADJUSTMENT_REQUESTED', 'Stock adjustment request', $a->number.' · '.$a->reason, 'approvals', $a->requested_by, $a->stock_location_id);

            return $a;
        });
    }

    public function decide(int $id, bool $approved, string $reason = ''): StockAdjustment
    {
        Gate::authorize('approve-adjustments');

        return $this->tx->run(function () use ($id, $approved, $reason) {
            $a = StockAdjustment::lockForUpdate()->findOrFail($id);
            if ($a->status === ($approved ? 'APPROVED' : 'REJECTED')) {
                return $a;
            }
            $this->stock->require($a->status === 'PENDING', 'Request has already been decided.');
            if ($approved) {
                foreach ($a->items()->orderBy('product_id')->get() as $line) {
                    $this->stock->change($a->stock_location_id, $line->product_id, D::sub('0', $line->base_quantity, 6), $a->reason, 'StockAdjustment', $a->id);
                }
            } else {
                $this->stock->require(strlen(trim($reason)) >= 8, 'Record a rejection reason.');
            }
            $a->update(['status' => $approved ? 'APPROVED' : 'REJECTED', 'approved_by' => app(TenantContext::class)->user()->id, 'decided_at' => now(), 'decision_reason' => $reason]);
            $this->audit->record($approved ? 'STOCK_ADJUSTED' : 'ADJUSTMENT_REJECTED', 'Inventory', 'StockAdjustment', $a->id, ['status' => 'PENDING'], ['status' => $a->status, 'reason' => $reason], $a->branch_id);

            return $a;
        });
    }
}
