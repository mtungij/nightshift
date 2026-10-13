<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\ServiceTable;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class CatalogService
{
    public function __construct(private TransactionService $tx, private StockService $stock, private AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function product(array $data): Product
    {
        Gate::authorize('manage-products');

        $imagePath = null;
        try {
            if (($data['image'] ?? null) instanceof UploadedFile) {
                $imagePath = $data['image']->store('product-images/'.app(TenantContext::class)->companyId(), 'local');
                if ($imagePath === false) {
                    throw new \RuntimeException('Product image could not be stored.');
                }
            }

            return $this->tx->run(function () use ($data, $imagePath) {
                $category = Category::whereKey($data['category_id'])->firstOrFail();
                $base = Unit::whereKey($data['base_unit_id'])->firstOrFail();
                $p = isset($data['id']) ? Product::whereKey($data['id'])->firstOrFail() : new Product;
                $old = $p->exists ? $p->only(['name', 'sku', 'purchase_cost', 'minimum_stock']) : [];
                $sku = trim((string) ($data['sku'] ?? ''));
                if ($sku === '') {
                    $sku = $p->exists ? $p->sku : 'NS-'.strtoupper((string) Str::ulid());
                    while (! $p->exists && Product::where('sku', $sku)->exists()) {
                        $sku = 'NS-'.strtoupper((string) Str::ulid());
                    }
                }
                $this->stock->require(! Product::where('sku', $sku)->when($p->exists, fn ($q) => $q->where('id', '!=', $p->id))->exists(), 'SKU already exists.');
                $p->fill(['name' => $data['name'], 'sku' => $sku, 'barcode' => $data['barcode'] ?? null, 'brand' => array_key_exists('brand', $data) ? $data['brand'] : $p->brand, 'category_id' => $category->id, 'base_unit_id' => $base->id, 'purchase_cost' => D::money($data['purchase_cost']), 'minimum_stock' => D::quantity($data['minimum_stock']), 'active' => $data['active'] ?? true, 'image_url' => array_key_exists('image_url', $data) ? $data['image_url'] : $p->image_url]);
                $p->save();
                $oldPrices = [];
                foreach ($data['units'] as $line) {
                    $unit = Unit::whereKey($line['unit_id'])->firstOrFail();
                    $conversion = D::quantity($line['conversion_quantity']);
                    $price = D::money($line['selling_price']);
                    $this->stock->require(D::cmp($conversion, '0') > 0 && D::cmp($price, '0') >= 0, 'Invalid unit conversion or price.');
                    $u = ProductUnit::firstOrNew(['product_id' => $p->id, 'unit_id' => $unit->id]);
                    $oldPrices[$unit->name] = $u->selling_price;
                    $u->fill(['conversion_quantity' => $conversion, 'selling_price' => $price]);
                    $u->save();
                }
                if ($imagePath) {
                    $p->update(['image_path' => $imagePath]);
                }
                $this->audit->record('PRODUCT_EDITED', 'Inventory', 'Product', $p->id, $old, $p->only(['name', 'sku', 'purchase_cost', 'minimum_stock']));
                $this->audit->record('PRICE_CHANGED', 'Inventory', 'Product', $p->id, $oldPrices, ['units' => $data['units']]);

                return $p;
            });
        } catch (Throwable $error) {
            if ($imagePath) {
                Storage::disk('local')->delete($imagePath);
            }
            throw $error;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function save(string $type, #[\SensitiveParameter] array $data): array
    {
        $permission = match ($type) {
            'branch' => 'manage-branches','location' => 'manage-counters','user' => 'manage-users','company', 'setup' => 'manage-company', 'reservation' => 'create-orders',default => 'manage-inventory'
        };
        Gate::authorize($permission);

        return $this->tx->run(function () use ($type, $data) {
            $actor = app(TenantContext::class)->user();
            if ($type === 'category-order') {
                return $this->categoryOrder($data);
            }
            if ($type === 'setup') {
                return $this->setup($data);
            }
            $record = match ($type) {
                'branch' => $this->branch($data),'location' => $this->location($data),'category' => $this->category($data),'unit' => Unit::firstOrCreate(['name' => $data['name']]),'supplier' => Supplier::create(['name' => $data['name'], 'contact' => $data['contact'], 'category' => $data['category'] ?? null]),'reservation' => $this->reservation($data),'user' => $this->user($data),'company' => $this->company($data),default => throw new \InvalidArgumentException('Unknown catalog type')
            };
            $this->audit->record(strtoupper($type).'_SAVED', 'Management', class_basename($record), $record->id, [], ['name' => $record->name ?? $type]);

            return ['id' => $record->id];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function location(array $data): StockLocation
    {
        $b = Branch::whereKey($data['branch_id'])->firstOrFail();

        return StockLocation::create(['branch_id' => $b->id, 'name' => $data['name'], 'type' => $data['type']]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reservation(array $data): ServiceTable
    {
        $t = ServiceTable::whereKey($data['service_table_id'])->firstOrFail();
        $t->update(['reservation' => ['guest' => $data['guest'], 'phone' => $data['phone'], 'size' => $data['size'], 'date' => $data['date'], 'arrival' => $data['arrival']]]);

        return $t;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function user(#[\SensitiveParameter] array $data): User
    {
        $actor = app(TenantContext::class)->user();
        $role = Role::where('name', $data['role'])->whereIn('name', config('business.staff_roles'))->firstOrFail();
        $branch = Branch::whereKey($data['branch_id'])->firstOrFail();
        $data['email'] = mb_strtolower(trim($data['email']));
        $this->stock->require(! User::where('email', $data['email'])->exists(), 'This email address is already registered.');
        $u = User::create(['name' => $data['name'], 'username' => isset($data['username']) ? mb_strtolower(trim($data['username'])) : null, 'email' => $data['email'], 'password' => Str::password(64)]);
        $u->forceFill(['operational_pin' => Hash::make($data['pin'])])->save();
        $u->forceFill(['company_id' => $actor->company_id, 'role_id' => $role->id, 'phone' => $data['phone'] ?? null, 'active' => $data['active'] ?? true])->save();
        $u->branches()->attach($branch->id, ['company_id' => $actor->company_id]);

        return $u;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function company(array $data): Company
    {
        return app(BusinessProfileService::class)->update(['business_name' => $data['name'], 'business_email' => $data['email'], 'business_phone' => $data['phone']]);
    }

    /** @param array<string, mixed> $data */
    private function branch(array $data): Branch
    {
        $branch = isset($data['id']) ? Branch::whereKey($data['id'])->firstOrFail() : new Branch;
        foreach (['name', 'code'] as $field) {
            if (! empty($data[$field])) {
                $this->stock->require(! Branch::where($field, $data[$field])->when($branch->exists, fn ($query) => $query->where('id', '!=', $branch->id))->exists(), 'A branch with this '.$field.' already exists.');
            }
        }
        $branch->fill(array_intersect_key($data, array_flip(['name', 'address', 'code', 'active'])))->save();

        return $branch;
    }

    /** @param array<string, mixed> $data */
    private function category(array $data): Category
    {
        $category = isset($data['id']) ? Category::whereKey($data['id'])->firstOrFail() : Category::firstOrNew(['name' => $data['name']]);
        $this->stock->require(! Category::where('name', $data['name'])->when($category->exists, fn ($query) => $query->where('id', '!=', $category->id))->exists(), 'A category with this name already exists.');
        if (! $category->exists && ! isset($data['sort_order'])) {
            $category->sort_order = (int) Category::max('sort_order') + 1;
        }
        $category->fill(array_intersect_key($data, array_flip(['name', 'active', 'sort_order', 'icon', 'color'])))->save();

        return $category;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function categoryOrder(array $data): array
    {
        $categories = Category::whereIn('id', $data['ids'])->get()->keyBy('id');
        abort_unless($categories->count() === count($data['ids']) && Category::count() === count($data['ids']), 404);
        foreach ($data['ids'] as $order => $id) {
            $categories[$id]->update(['sort_order' => $order]);
        }
        $this->audit->record('CATEGORIES_REORDERED', 'Management', 'Company', app(TenantContext::class)->companyId(), [], ['ids' => $data['ids']]);

        return ['id' => app(TenantContext::class)->companyId()];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function setup(array $data): array
    {
        $company = app(TenantContext::class)->user()->company->fresh();
        $settings = $company->settings ?? [];
        $steps = $settings['onboarding'] ?? [];
        $this->stock->require($data['status'] !== 'skip' || in_array($data['step'], ['locations', 'categories', 'products', 'staff'], true), 'This setup step cannot be skipped.');
        if ($data['step'] === 'finish' && $data['status'] === 'complete') {
            foreach (array_diff(config('business.setup_steps'), ['finish']) as $step) {
                $this->stock->require(in_array($steps[$step] ?? '', ['complete', 'skip'], true), 'Complete or skip the remaining setup steps first.');
            }
        }
        $steps[$data['step']] = $data['status'];
        if ($data['step'] !== 'finish' && $data['status'] === 'resume' && ($steps['finish'] ?? null) === 'complete') {
            $steps['finish'] = 'resume';
        }
        $settings['onboarding'] = $steps;
        $company->update(['settings' => $settings]);
        $this->audit->record('SETUP_PROGRESS_UPDATED', 'Onboarding', 'Company', $company->id, [], ['step' => $data['step'], 'status' => $data['status']]);

        return ['id' => $company->id];
    }
}
