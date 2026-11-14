<?php

namespace App\Http\Controllers;

use App\Domain\BusinessUnitContext;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Product;
use App\Models\Stock;
use App\Services\BusinessUnitService;
use App\Services\UnitOperationalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ProductConfigurationController extends Controller
{
    public function show(?int $id = null): JsonResponse
    {
        Gate::authorize('manage-products');
        $actor = app(TenantContext::class)->user();
        abort_unless($actor->active && $actor->company->active && ! $actor->isPosTerminal(), 403);
        app(BusinessUnitContext::class)->select(null);
        $product = $id ? Product::with(['sellingUnits.unit', 'category', 'baseUnit'])->findOrFail($id) : null;
        $units = BusinessUnit::where('active', true)->orderBy('name')->get();
        $branches = Branch::pluck('name', 'id');
        $availability = $product ? DB::table('business_unit_products')->where('company_id', $actor->company_id)->where('product_id', $product->id)->pluck('available', 'business_unit_id') : collect();
        $prices = $product ? DB::table('business_unit_prices')->where('company_id', $actor->company_id)->whereIn('product_unit_id', $product->sellingUnits->pluck('id'))->get() : collect();

        return response()->json(['product' => $product ? ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'barcode' => $product->barcode, 'category' => $product->category->name, 'baseUnit' => $product->baseUnit->name, 'cost' => $product->purchase_cost, 'min' => $product->minimum_stock, 'active' => $product->active, 'image' => $product->image_path ? route('nightshift.product-image', ['id' => $product->id], false) : $product->image_url, 'price' => $product->sellingUnits->first()?->selling_price, 'units' => $product->sellingUnits->map(fn ($u) => [$u->unit->name, $u->conversion_quantity, $u->selling_price, $u->id, $u->unit_id])->all()] : null,
            'units' => $units->map(fn ($unit) => ['id' => $unit->id, 'branch_id' => $unit->branch_id, 'name' => $unit->name, 'branch' => $branches[$unit->branch_id] ?? '', 'active' => $unit->active, 'available' => (bool) ($availability[$unit->id] ?? false), 'prices' => $product ? $product->sellingUnits->map(fn ($u) => ['unit_id' => $u->unit_id, 'selling_price' => $prices->where('business_unit_id', $unit->id)->firstWhere('product_unit_id', $u->id)?->selling_price])->all() : []])->all(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function locationProducts(Request $request, BusinessUnitService $units): JsonResponse
    {
        $data = $request->validate(['business_unit_id' => ['required_without:stock_location_id', 'integer', 'min:1'], 'stock_location_id' => ['required_without:business_unit_id', 'integer', 'min:1'], 'purpose' => ['required', 'in:receive,transfer,adjustment']]);
        Gate::authorize(['receive' => 'receive-stock', 'transfer' => 'transfer-stock', 'adjustment' => 'adjust-stock'][$data['purpose']]);
        $actor = app(TenantContext::class)->user();
        abort_unless($actor->active && $actor->company->active && ! $actor->isPosTerminal(), 403);
        app(BusinessUnitContext::class)->select(null);
        $location = app(UnitOperationalService::class)->resolve($data);
        $query = Product::where('active', true)->whereHas('sellingUnits');
        $query->where(function ($q) use ($units, $location, $data) {
            $q->whereIn('products.id', DB::table('business_unit_products')->where('company_id', $location->company_id)->whereIn('business_unit_id', $units->locationUnits($location))->where('available', true)->select('product_id'));
            if ($data['purpose'] !== 'receive') {
                $q->orWhereIn('products.id', Stock::where('stock_location_id', $location->id)->where('quantity', '>', '0')->select('product_id'));
            }
        });

        return response()->json(['location' => ['id' => $location->id, 'name' => $location->name, 'is_shared' => (bool) $location->is_shared, 'business_unit' => $location->business_unit_id ? BusinessUnit::find($location->business_unit_id)?->name : null],
            'products' => $query->with('sellingUnits.unit')->orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'cost' => $p->purchase_cost, 'units' => $p->sellingUnits->map(fn ($u) => [$u->unit->name, $u->conversion_quantity, $u->selling_price, $u->id])->all()])->all(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
