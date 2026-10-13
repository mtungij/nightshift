<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use Illuminate\Support\Facades\Gate;

final class GoodsReceiptService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private NumberingService $numbers, private AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(array $data): GoodsReceipt
    {
        Gate::authorize('receive-stock');

        return $this->tx->run(function () use ($data) {
            $location = $this->stock->location($data['stock_location_id']);
            $supplier = Supplier::whereKey($data['supplier_id'])->firstOrFail();
            $invoice = trim((string) ($data['supplier_invoice'] ?? ''));
            if ($invoice !== '') {
                $this->stock->require(! GoodsReceipt::where('supplier_id', $supplier->id)->where('supplier_invoice', $invoice)->exists(), 'This supplier invoice has already been received.');
            }
            $lines = [];
            $cost = '0.00';
            foreach ($data['items'] as $line) {
                $unit = $this->stock->unit($line['product_unit_id']);
                $qty = D::quantity($line['quantity']);
                $unitCost = D::money($line['unit_cost']);
                $this->stock->require(D::cmp($qty, '0') > 0 && D::cmp($unitCost, '0') >= 0, 'Invalid receiving quantity or cost.');
                $total = D::mul($qty, $unitCost);
                $cost = D::add($cost, $total);
                $lines[] = ['product_id' => $unit->product_id, 'product_unit_id' => $unit->id, 'quantity' => $qty, 'base_quantity' => D::mul($qty, $unit->conversion_quantity, 6), 'unit_cost' => $unitCost, 'total_cost' => $total];
            }
            $grn = GoodsReceipt::create(['branch_id' => $location->branch_id, 'supplier_id' => $supplier->id, 'stock_location_id' => $location->id, 'number' => $this->numbers->next('GRN'), 'supplier_invoice' => $invoice === '' ? null : $invoice, 'received_on' => $data['received_on'], 'total_cost' => $cost, 'created_by' => app(TenantContext::class)->user()->id]);
            foreach ($lines as $line) {
                $grn->items()->create(['branch_id' => $grn->branch_id, ...$line]);
                $this->stock->change($location->id, $line['product_id'], $line['base_quantity'], 'GRN', 'GoodsReceipt', $grn->id);
            }
            $this->audit->record('GRN_POSTED', 'Inventory', 'GoodsReceipt', $grn->id, [], ['number' => $grn->number, 'cost' => $cost], $grn->branch_id);

            return $grn;
        });
    }
}
