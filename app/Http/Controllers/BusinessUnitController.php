<?php

namespace App\Http\Controllers;

use App\Domain\BusinessUnitContext;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\BusinessUnitService;
use App\Services\StockTransferService;
use App\Services\TransactionService;
use App\Services\UnitOperationalService;
use App\Support\CounterLocationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class BusinessUnitController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage-business-units');
        abort_unless($request->user()->company->active, 403);

        $locations = StockLocation::get();

        return response()->view('business-units', [
            'units' => BusinessUnit::orderBy('branch_id')->orderBy('name')->get(), 'branches' => Branch::get(),
            'locations' => $locations,
            'counterDisplays' => $locations->mapWithKeys(fn ($location) => [$location->id => app(CounterLocationPresenter::class)->present($location)]), 'users' => app(StaffVisibility::class)->unitUsers(User::query())->where('company_id', app(TenantContext::class)->companyId())->with('role')->get(),
            'products' => Product::with('sellingUnits.unit')->orderBy('name')->get(),
            'availability' => DB::table('business_unit_products')->where('company_id', app(TenantContext::class)->companyId())->get(),
            'prices' => DB::table('business_unit_prices')->where('company_id', app(TenantContext::class)->companyId())->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function assignmentOptions(Request $request): JsonResponse
    {
        Gate::authorize('manage-users');
        abort_unless($request->user()->active && $request->user()->company->active && ! $request->user()->isPosTerminal(), 403);
        // Staff administration uses all assigned scopes, independent of the current POS filter.
        app(BusinessUnitContext::class)->select(null);

        return response()->json(['units' => BusinessUnit::where('active', true)->get(['id', 'branch_id', 'name', 'advanced_locations_enabled', 'default_stock_location_id']),
            'counters' => StockLocation::where('type', 'COUNTER')->where('active', true)->get(['id', 'branch_id', 'business_unit_id', 'name'])])->header('Cache-Control', 'private, no-store');
    }

    public function product(int $id): Response
    {
        Gate::authorize('manage-products');
        abort_unless(app(TenantContext::class)->user()->company->active, 403);
        $product = Product::with('sellingUnits.unit')->findOrFail($id);
        $units = BusinessUnit::get();

        return response()->view('product-business-units', [
            'product' => $product, 'units' => $units, 'branches' => Branch::get(),
            'availability' => DB::table('business_unit_products')->where('company_id', $product->company_id)->where('product_id', $product->id)->whereIn('business_unit_id', $units->pluck('id'))->get(),
            'prices' => DB::table('business_unit_prices')->where('company_id', $product->company_id)->whereIn('business_unit_id', $units->pluck('id'))->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function command(Request $request, string $action, BusinessUnitService $service): JsonResponse
    {
        abort_unless($request->user()->active && $request->user()->company_id && $request->user()->company->active && ! $request->user()->isPosTerminal(), 403);
        $id = ['required', 'integer', 'min:1'];
        $ids = ['required', 'array', 'min:1', 'max:100'];
        $rules = match ($action) {
            'save' => ['max_active_cashiers' => ['sometimes', 'integer', 'min:1', 'max:65535'], 'advanced_locations_enabled' => ['sometimes', 'boolean'], 'id' => ['nullable', 'integer', 'min:1'], 'branch_id' => $id, 'name' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/D'], 'active' => ['required', 'boolean']],
            'adopt-default' => ['id' => $id, 'stock_location_id' => $id],
            'cutover' => ['source_location_id' => $id, 'destination_business_unit_id' => $id, 'notes' => ['required', 'string', 'min:8', 'max:2000'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.product_unit_id' => $id, 'items.*.quantity' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,6})?$/D']],
            'location' => ['id' => $id, 'is_shared' => ['required', 'boolean'], 'unit_ids' => $ids, 'unit_ids.*' => [...$id, 'distinct']],
            'staff' => ['branch_ids' => ['sometimes', 'array', 'min:1', 'max:100'], 'branch_ids.*' => [...$id, 'distinct'], 'active' => ['sometimes', 'boolean'], 'counter_ids' => ['sometimes', 'array', 'max:100'], 'counter_ids.*' => [...$id, 'distinct'], 'id' => $id, 'unit_ids' => ['present', 'array', 'max:100'], 'unit_ids.*' => [...$id, 'distinct']],
            'product' => ['product_id' => $id, 'business_unit_id' => $id, 'available' => ['required', 'boolean'], 'prices' => ['present', 'array', 'max:100'], 'prices.*.product_unit_id' => [...$id, 'distinct'], 'prices.*.selling_price' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/D']],
            default => abort(404),
        };
        $data = $request->validate(['idempotency_key' => ['required', 'uuid'], ...$rules]);
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);
        $result = app(TransactionService::class)->idempotent($key, 'business-unit-'.$action, $data, function () use ($action, $service, $data) {
            if ($action === 'cutover') {
                return ['id' => app(StockTransferService::class)->cutover($data)->id];
            }
            if ($action === 'adopt-default') {
                app(UnitOperationalService::class)->adoptDefault(BusinessUnit::whereKey($data['id'])->firstOrFail(), StockLocation::whereKey($data['stock_location_id'])->firstOrFail());

                return ['saved' => true];
            }
            if ($action === 'staff') {
                $user = User::where('company_id', app(TenantContext::class)->companyId())->lockForUpdate()->whereKey($data['id'])->firstOrFail();
                if (array_key_exists('branch_ids', $data)) {
                    $service->branches($user, $data['branch_ids']);
                }
                if (array_key_exists('active', $data)) {
                    $service->status($user, (bool) $data['active']);
                }
                $service->assign($user, $data['unit_ids']);
                if (array_key_exists('counter_ids', $data)) {
                    $service->counters($user, $data['counter_ids']);
                }
            } elseif ($action === 'product') {
                $service->product($data);
            } else {
                $unit = $action === 'save' ? $service->save($data) : $service->location($data);

                return ['id' => $unit->id];
            }

            return ['saved' => true];
        });

        return response()->json($result);
    }
}
