<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockService
{
    public function unit(int $id): ProductUnit
    {
        $u = ProductUnit::with(['product', 'unit'])->findOrFail($id);
        $this->require($u->product->active, 'Product is inactive.');
        $this->require(D::cmp($u->conversion_quantity, '0') > 0, 'Invalid selling unit conversion.');

        return $u;
    }

    public function location(int $id): StockLocation
    {
        $l = StockLocation::whereKey($id)->firstOrFail();
        $this->require($l->active, 'Location is inactive.');

        return $l;
    }

    public function change(int $locationId, int $productId, string $quantity, string $type, string $refType, int $refId, ?int $shift = null, ?int $reversal = null): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock mutations require a transaction.');
        }
        $location = $this->location($locationId);
        $product = Product::whereKey($productId)->firstOrFail();
        Stock::firstOrCreate(['stock_location_id' => $location->id, 'product_id' => $product->id], ['branch_id' => $location->branch_id, 'quantity' => '0']);
        $stock = Stock::where('stock_location_id', $location->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
        $next = D::add($stock->quantity, $quantity, 6);
        $this->require(D::cmp($next, '0') >= 0, "Insufficient stock for {$product->name}. Available: {$stock->quantity}.");
        $stock->update(['quantity' => $next]);

        return StockMovement::create(['branch_id' => $location->branch_id, 'stock_location_id' => $location->id, 'product_id' => $product->id, 'shift_id' => $shift, 'movement_type' => $type, 'quantity' => $quantity, 'balance_after' => $next, 'unit_cost' => $product->purchase_cost, 'reference_type' => $refType, 'reference_id' => $refId, 'reversal_of' => $reversal, 'created_by' => app(TenantContext::class)->user()->id]);
    }

    public function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['transaction' => $message]);
        }
    }
}
