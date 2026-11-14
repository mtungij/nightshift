<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\BusinessUnit;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class StockService
{
    private ?int $cutoverSource = null;

    public function withSupervisedCutover(int $source, callable $callback): mixed
    {
        Gate::authorize('manage-business-units');
        Gate::authorize('transfer-stock');
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Cutover requires the company transaction lock.');
        }
        $location = StockLocation::lockForUpdate()->findOrFail($source);
        $unit = BusinessUnit::whereKey($location->business_unit_id)->firstOrFail();
        $this->require(! $unit->active, __('ui.Supervised legacy cutover requires an inactive Business Unit.'));
        app(BusinessUnitService::class)->idle($unit->id);
        $previous = $this->cutoverSource;
        $this->cutoverSource = $source;
        try {
            return $callback();
        } finally {
            $this->cutoverSource = $previous;
        }
    }

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
        $this->require($l->active || $this->cutoverSource === $id, 'Location is inactive.');
        if ($l->business_unit_id) {
            $this->require($this->cutoverSource === $id || DB::table('business_units')->where('company_id', $l->company_id)->where('id', $l->business_unit_id)->where('active', true)->exists(), __('ui.Business Unit is inactive.'));
        }

        return $l;
    }

    public function change(int $locationId, int $productId, string $quantity, string $type, string $refType, int $refId, ?int $shift = null, ?int $reversal = null): StockMovement
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock mutations require a transaction.');
        }
        // Share the existing company transaction lock with opening/count/final close.
        Company::whereKey(app(TenantContext::class)->companyId())->lockForUpdate()->firstOrFail();
        $location = $this->location($locationId);
        if ($shift === null && $location->type === 'COUNTER') {
            $operations = Shift::where('stock_location_id', $location->id)->where('type', 'CASHIER')->where('status', 'OPEN')->orderBy('id')->lockForUpdate()->pluck('id');
            $this->require($operations->count() <= app(UnitOperationalService::class)->physicalLimit($location), __('ui.This counter has overlapping legacy cashier shifts. Ask a manager to complete supervised cutover.'));
            $shift = $operations->first();
        }
        if ($location->type === 'COUNTER') {
            app(CounterCashierService::class)->assertOperational($location->id);
        }
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
