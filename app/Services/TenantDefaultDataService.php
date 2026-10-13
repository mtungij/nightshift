<?php

namespace App\Services;

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\StockLocation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TenantDefaultDataService
{
    /** @param list<string>|null $selectedCategories */
    public function seed(User $owner, ?array $selectedCategories = null): void
    {
        DB::transaction(function () use ($owner, $selectedCategories): void {
            $company = Company::whereKey($owner->company_id)->lockForUpdate()->firstOrFail();
            app(TenantContext::class)->run($owner, function () use ($owner, $selectedCategories, $company): void {
                $branch = Branch::whereKey($company->settings['default_branch_id'] ?? 0)->first() ?? Branch::firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Branch', 'active' => true]);
                $settings = $company->settings ?? [];
                $settings['default_branch_id'] = $branch->id;
                $owner->branches()->syncWithoutDetaching([$branch->id => ['company_id' => $owner->company_id]]);
                foreach (['Main Store' => 'STORE', 'Main Counter' => 'COUNTER'] as $name => $type) {
                    StockLocation::firstOrCreate(['branch_id' => $branch->id, 'name' => $name], ['type' => $type, 'active' => true]);
                }
                foreach (config('business.categories') as $order => $name) {
                    $category = Category::whereKey($settings['default_category_ids'][$name] ?? 0)->first()
                        ?? Category::firstOrCreate(['name' => $name], ['sort_order' => $order, 'active' => $selectedCategories === null || in_array($name, $selectedCategories, true), 'icon' => 'box', 'color' => '#956015']);
                    $settings['default_category_ids'][$name] = $category->id;
                }
                foreach (config('business.units') as $name) {
                    Unit::firstOrCreate(['name' => $name]);
                }
                $company->update(['settings' => $settings]);
            });
        });
    }
}
