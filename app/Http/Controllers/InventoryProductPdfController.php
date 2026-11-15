<?php

namespace App\Http\Controllers;

use App\Contracts\ReportPdfRenderer;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Product;
use App\Services\BusinessUnitService;
use App\Services\ReportPdfService;
use App\Support\QuantityFormatter;
use App\Support\UiText;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class InventoryProductPdfController extends Controller
{
    public function download(
        Request $request,
        ReportPdfService $pdf,
        ReportPdfRenderer $renderer
    ): Response {
        $actor = app(TenantContext::class)->user();

        abort_unless(
            $actor->hasPermission('manage-inventory')
            || $actor->hasPermission('manage-products'),
            403
        );

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'business_unit_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = ! empty($data['branch_id'])
            ? (int) $data['branch_id']
            : null;

        $unitId = ! empty($data['business_unit_id'])
            ? (int) $data['business_unit_id']
            : null;

        $categoryName = trim((string) ($data['category'] ?? ''));

        if ($branchId) {
            $branch = Branch::findOrFail($branchId);
            abort_unless($actor->canAccessBranch($branch->id), 403);
        }

        if ($unitId) {
            $selectedUnit = BusinessUnit::findOrFail($unitId);

            abort_unless(
                $actor->canAccessBusinessUnit($selectedUnit->id),
                403
            );

            if ($branchId) {
                abort_unless(
                    (int) $selectedUnit->branch_id === $branchId,
                    422
                );
            }
        }

        $units = BusinessUnit::query()
            ->where('active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($unitId, fn ($q) => $q->whereKey($unitId))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name']);

        $availability = DB::table('business_unit_products')
            ->where('company_id', $actor->company_id)
            ->where('available', true)
            ->whereIn('business_unit_id', $units->pluck('id'))
            ->get(['product_id', 'business_unit_id']);

        $productIds = $availability
            ->pluck('product_id')
            ->unique()
            ->values();

        $products = Product::query()
            ->with(['category', 'baseUnit', 'sellingUnits.unit'])
            ->whereIn('id', $productIds)
            ->when(
                $categoryName !== ''
                && $categoryName !== 'All categories'
                && $categoryName !== 'All products',
                fn ($q) => $q->whereHas(
                    'category',
                    fn ($c) => $c->where('name', $categoryName)
                )
            )
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $stocks = DB::table('stocks')
            ->join(
                'stock_locations',
                'stock_locations.id',
                '=',
                'stocks.stock_location_id'
            )
            ->where('stocks.company_id', $actor->company_id)
            ->whereIn(
                'stock_locations.business_unit_id',
                $units->pluck('id')
            )
            ->selectRaw(
                'stock_locations.business_unit_id,
                 stocks.product_id,
                 SUM(stocks.quantity) AS quantity'
            )
            ->groupBy(
                'stock_locations.business_unit_id',
                'stocks.product_id'
            )
            ->get()
            ->keyBy(
                fn ($row) =>
                    $row->business_unit_id.'-'.$row->product_id
            );

        $groups = [];

        foreach ($availability as $available) {
            $product = $products->get($available->product_id);

            $unit = $units->firstWhere(
                'id',
                (int) $available->business_unit_id
            );

            if (! $product || ! $unit) {
                continue;
            }

            $category = $product->category?->name
                ?? UiText::get('Uncategorized');

            $selling = $product->sellingUnits->first();

            $price = $selling
                ? app(BusinessUnitService::class)
                    ->price($selling, $unit->id)
                : '0';

            $stockKey = $unit->id.'-'.$product->id;

            $groups[$category][$unit->name][] = [
                'name' => $product->name,
                'base_unit' => $product->baseUnit?->name ?? '—',
                'price' => $price,
                'stock' => $stocks->get($stockKey)?->quantity ?? '0',
            ];
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($groups as &$businessUnits) {
            ksort($businessUnits, SORT_NATURAL | SORT_FLAG_CASE);

            foreach ($businessUnits as &$products) {
                usort(
                    $products,
                    fn ($a, $b) =>
                        strnatcasecmp($a['name'], $b['name'])
                );
            }

            unset($products);
        }

        unset($businessUnits);

        $branchName = $branchId
            ? Branch::findOrFail($branchId)->name
            : UiText::get('All branches');

        $unitName = $unitId
            ? $units->firstWhere('id', $unitId)?->name
            : null;

        $html = view('reports.product-list-pdf', [
            'company' => $actor->company->name,
            'branch' => $branchName,
            'groups' => $groups,
            'categoryFilter' => $categoryName,
            'unit' => $unitName,
            'generatedAt' => now()
                ->timezone($actor->company->timezone)
                ->format('d/m/Y H:i'),
            'currency' => $actor->company->currency === 'TZS'
                ? 'TSh'
                : $actor->company->currency,
        ])->render();

        return $pdf->response(
            $renderer->render($html, 'portrait'),
            'Product List',
            $branchId ? ['branch_id' => $branchId] : []
        );
    }
}
