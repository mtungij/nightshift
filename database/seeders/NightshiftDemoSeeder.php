<?php

namespace Database\Seeders;

use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\ServiceTable;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use Illuminate\Database\Seeder;

final class NightshiftDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \LogicException('Demo seeding is development-only.');
        }
        $this->call(PermissionSeeder::class);
        $company = Company::firstOrCreate(['name' => 'Nightshift Demo Lounge'], ['email' => 'hello@nightshift.test', 'phone' => '+255 754 820 000']);
        $users = [];
        foreach (['admin' => ['Alex Morgan', 'Company Admin / Owner'], 'manager' => ['Fatma Ali', 'Manager'], 'cashier' => ['Peter Macha', 'Cashier'], 'asha' => ['Asha Mwakalinga', 'Waiter'], 'james' => ['James Mushi', 'Waiter'], 'storekeeper' => ['John Mwita', 'Store Keeper']] as $slug => [$name,$role]) {
            $u = User::firstOrNew(['email' => $slug.'@nightshift.test']);
            if (! $u->exists) {
                $u->fill(['name' => $name, 'password' => config('nightshift.demo_password')]);
                $u->forceFill(['active' => true, 'company_id' => $company->id, 'role_id' => Role::where('name', $role)->firstOrFail()->id, 'email_verified_at' => now()])->save();
            }if ($u->company_id !== $company->id) {
                throw new \LogicException('Demo email belongs to another company.');
            }$users[$slug] = $u;
        }
        app(TenantContext::class)->run($this->user($users, 'admin'), function () use ($company, $users) {
            $branch = Branch::firstOrCreate(['name' => 'Masaki Branch'], ['address' => 'Masaki, Dar es Salaam']);
            foreach ($users as $u) {
                $u->branches()->syncWithoutDetaching([$branch->id => ['company_id' => $company->id]]);
            }
            $locations = [];
            foreach (['Main Store' => 'STORE', 'Main Counter' => 'COUNTER', 'VIP Counter' => 'COUNTER', 'Garden Counter' => 'COUNTER'] as $name => $type) {
                $locations[$name] = StockLocation::firstOrCreate(['branch_id' => $branch->id, 'name' => $name], ['type' => $type]);
            }
            $cats = [];
            foreach (['Beer', 'Wine', 'Whisky', 'Vodka', 'Gin', 'Spirits', 'Champagne', 'Energy Drinks', 'Soft Drinks', 'Water', 'Cocktails', 'Food'] as $name) {
                $cats[$name] = Category::firstOrCreate(['name' => $name]);
            }$units = [];
            foreach (['Bottle', 'Piece', 'Bucket', 'Crate', 'Tot', 'Can', 'Glass', 'Portion'] as $name) {
                $units[$name] = Unit::firstOrCreate(['name' => $name]);
            }
            $products = [['Serengeti Lite', 'BR-001', 'Beer', '2000', '3000', '48', ''], ['Heineken', 'BR-002', 'Beer', '3200', '5000', '48', ''], ['Savanna Dry', 'BR-003', 'Beer', '3300', '5000', '48', 'amber'], ['Johnnie Walker Black', 'WH-001', 'Whisky', '78000', '120000', '10', 'dark'], ['Absolut Vodka', 'VD-001', 'Vodka', '54000', '85000', '12', 'blue'], ['Kilimanjaro Lager', 'BR-004', 'Beer', '2200', '3500', '48', 'amber'], ['Hendricks Gin', 'GN-001', 'Gin', '100000', '150000', '6', 'dark'], ['Red Bull', 'EN-001', 'Energy Drinks', '3500', '6000', '24', 'blue'], ['Coca-Cola', 'SD-001', 'Soft Drinks', '1000', '2000', '24', 'dark'], ['Kilimanjaro Water', 'WT-001', 'Water', '700', '1500', '36', 'blue'], ['Classic Mojito', 'CK-001', 'Cocktails', '6000', '15000', '10', 'cocktail'], ['Chicken Wings', 'FD-001', 'Food', '8000', '18000', '5', 'food'], ['Four Cousins Red', 'WN-001', 'Wine', '29000', '45000', '8', 'dark'], ['Moët & Chandon', 'CH-001', 'Champagne', '155000', '220000', '4', 'amber'], ['Captain Morgan', 'SP-001', 'Spirits', '48000', '75000', '6', 'amber']];
            $selling = [];
            foreach ($products as [$name,$sku,$category,$cost,$price,$minimum,$visual]) {
                $base = match ($category) {
                    'Food' => 'Portion','Cocktails' => 'Glass','Energy Drinks' => 'Can',default => 'Bottle'
                };
                $p = Product::firstOrCreate(['sku' => $sku], ['name' => $name, 'category_id' => $cats[$category]->id, 'base_unit_id' => $units[$base]->id, 'brand' => explode(' ', $name)[0], 'purchase_cost' => $cost, 'minimum_stock' => $minimum, 'visual_style' => $visual]);
                $unitName = $category === 'Beer' ? 'Piece' : $base;
                $selling[$sku] = ProductUnit::firstOrCreate(['product_id' => $p->id, 'unit_id' => $units[$unitName]->id], ['conversion_quantity' => '1', 'selling_price' => $price]);
                if ($category === 'Beer') {
                    ProductUnit::firstOrCreate(['product_id' => $p->id, 'unit_id' => $units['Bucket']->id], ['conversion_quantity' => '6', 'selling_price' => config('nightshift.demo_bulk_prices.'.$sku.'.bucket', bcmul($price, '5.6', 2))]);
                    ProductUnit::firstOrCreate(['product_id' => $p->id, 'unit_id' => $units['Crate']->id], ['conversion_quantity' => '24', 'selling_price' => config('nightshift.demo_bulk_prices.'.$sku.'.crate', bcmul($price, '21.6', 2))]);
                }
            }
            $supplier = Supplier::firstOrCreate(['name' => 'Serengeti Breweries'], ['category' => 'Beverages', 'contact' => '+255 754 800 100']);
            foreach ($locations as $name => $location) {
                if (Stock::where('stock_location_id', $location->id)->exists()) {
                    continue;
                }$lines = [];
                foreach ($selling as $sku => $u) {
                    $lines[] = ['product_unit_id' => $u->id, 'quantity' => $name === 'Main Store' ? '480' : (in_array($sku, ['WH-001', 'VD-001']) ? '4' : '96'), 'unit_cost' => $u->product->purchase_cost];
                }app(GoodsReceiptService::class)->post(['stock_location_id' => $location->id, 'supplier_id' => $supplier->id, 'supplier_invoice' => 'OPENING-'.$location->id, 'received_on' => now()->toDateString(), 'items' => $lines]);
            }
            foreach (['Main Hall' => 'Main', 'VIP' => 'VIP', 'Garden' => 'Garden', 'Pool Area' => 'Pool', 'Rooftop' => 'Rooftop'] as $area => $prefix) {
                for ($i = 1; $i <= 12; $i++) {
                    ServiceTable::firstOrCreate(['branch_id' => $branch->id, 'name' => $prefix.' '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)], ['area' => $area, 'seats' => 4]);
                }
            }
            foreach (['admin' => 'CASHIER', 'cashier' => 'CASHIER', 'asha' => 'WAITER', 'james' => 'WAITER'] as $slug => $type) {
                if (! Shift::where('user_id', $this->user($users, $slug)->id)->exists()) {
                    app(ShiftService::class)->open(['user_id' => $this->user($users, $slug)->id, 'stock_location_id' => $locations['Main Counter']->id, 'type' => $type, 'opening_cash' => $type === 'CASHIER' ? '300000' : '0']);
                }
            }
            if (! Order::exists()) {
                foreach (['asha', 'james'] as $index => $slug) {
                    $o = app(OrderService::class)->create(['waiter_id' => $this->user($users, $slug)->id, 'stock_location_id' => $locations['Main Counter']->id, 'service_table_id' => ServiceTable::where('name', 'VIP 0'.($index + 1))->firstOrFail()->id, 'items' => [['product_unit_id' => $this->selling($selling, 'BR-001')->id, 'quantity' => '6'], ['product_unit_id' => $this->selling($selling, 'BR-002')->id, 'quantity' => '3']]]);
                    app(OrderService::class)->issue($o->id);
                    if ($index === 0) {
                        app(PaymentService::class)->receive($o->id, [['method' => 'Cash', 'amount' => '20000']]);
                    }
                }app(OrderService::class)->create(['waiter_id' => $this->user($users, 'asha')->id, 'stock_location_id' => $locations['Main Counter']->id, 'items' => [['product_unit_id' => $this->selling($selling, 'BR-003')->id, 'quantity' => '4']]]);
            }
        });
    }

    /** @param array<string, User> $users */
    private function user(array $users, string $key): User
    {
        return $users[$key] ?? throw new \LogicException('Missing demo user: '.$key);
    }

    /** @param array<string, ProductUnit> $selling */
    private function selling(array $selling, string $key): ProductUnit
    {
        return $selling[$key] ?? throw new \LogicException('Missing demo selling unit: '.$key);
    }
}
