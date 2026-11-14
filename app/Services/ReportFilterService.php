<?php

namespace App\Services;

use App\Domain\BusinessUnitContext;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ReportFilterService
{
    /** @return array<string,mixed> */
    public function validate(Request $request, string $report = ''): array
    {
        if ($request->filled('company_id')) {
            abort_unless((int) $request->input('company_id') === app(TenantContext::class)->companyId(), 403);
        }
        $ids = array_fill_keys(['branch_id', 'business_unit_id', 'stock_location_id', 'waiter_id', 'issued_by', 'category_id', 'product_id', 'shift_id', 'custodian_id'], ['nullable', 'integer', 'min:1']);
        $filters = $request->validate([...$ids, 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'search' => ['nullable', 'string', 'max:100'], 'all_branches' => ['nullable', 'boolean'], 'period' => ['nullable', 'in:Today,Yesterday,This week']]);
        foreach (['status', 'method', 'payment_method', 'transaction_type', 'action', 'module', 'search'] as $unsupported) {
            if ($request->filled($unsupported)) {
                throw ValidationException::withMessages([$unsupported => __('ui.This filter is not available for this report.')]);
            }
        }
        if ($report !== '') {
            $supported = config('report_pdf.filters.'.$report, config('report_pdf.default_filters'));
            foreach (array_keys($ids) as $field) {
                if (! empty($filters[$field]) && ! in_array($field, $supported, true)) {
                    throw ValidationException::withMessages([$field => __('ui.This filter is not available for this report.')]);
                }
            }
        }
        app(BusinessUnitContext::class)->select(! empty($filters['business_unit_id']) ? (int) $filters['business_unit_id'] : null);
        app(StaffVisibility::class)->assertFilters($filters);
        if (! empty($filters['branch_id'])) {
            Branch::findOrFail((int) $filters['branch_id']);
        }
        if (! empty($filters['business_unit_id'])) {
            $unit = BusinessUnit::findOrFail((int) $filters['business_unit_id']);
            abort_if(! empty($filters['branch_id']) && $unit->branch_id !== (int) $filters['branch_id'], 422, __('ui.Business Unit and branch must match.'));
        }
        if (! empty($filters['stock_location_id'])) {
            $location = StockLocation::findOrFail((int) $filters['stock_location_id']);
            abort_if(! empty($filters['branch_id']) && $location->branch_id !== (int) $filters['branch_id'], 422, __('ui.Business Unit and branch must match.'));
            abort_if(! empty($filters['business_unit_id']) && ! $location->is_shared && $location->business_unit_id !== (int) $filters['business_unit_id'], 422, __('ui.Business Unit and branch must match.'));
        }
        foreach (['product_id' => Product::class, 'category_id' => Category::class, 'shift_id' => Shift::class] as $key => $model) {
            if (! empty($filters[$key])) {
                $model::findOrFail((int) $filters[$key]);
            }
        }
        foreach (['waiter_id', 'issued_by', 'custodian_id'] as $key) {
            if (! empty($filters[$key])) {
                $users = User::where('company_id', app(TenantContext::class)->companyId());
                if ((int) $filters[$key] !== app(TenantContext::class)->user()->id) {
                    $users = app(StaffVisibility::class)->unitUsers($users);
                }
                $staff = $users->findOrFail((int) $filters[$key]);
                abort_if(! empty($filters['branch_id']) && ! $staff->canAccessBranch((int) $filters['branch_id']), 403);
                abort_if(! empty($filters['business_unit_id']) && ! $staff->canAccessBusinessUnit((int) $filters['business_unit_id']), 403);
            }
        }

        app(ReportShiftService::class)->resolve($filters, $report);

        if (in_array($report, ['Cash Custody Balance', 'Waiter Outstanding'], true)) {
            unset($filters['from'],$filters['to']);
        }

        return $filters;
    }
}
