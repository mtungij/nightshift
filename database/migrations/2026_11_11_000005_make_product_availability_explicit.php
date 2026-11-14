<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve existing explicit choices. Only untouched legacy masters get General.
        DB::table('products')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('business_unit_products')->whereColumn('business_unit_products.company_id', 'products.company_id')->whereColumn('business_unit_products.product_id', 'products.id'))->orderBy('id')->chunkById(200, function ($products) {
            $units = DB::table('business_units')->where('code', 'GENERAL')->whereIn('company_id', $products->pluck('company_id'))->get()->groupBy('company_id');
            $rows = [];
            foreach ($products as $product) {
                foreach ($units->get($product->company_id, collect()) as $unit) {
                    $rows[] = ['company_id' => $product->company_id, 'product_id' => $product->id, 'business_unit_id' => $unit->id, 'available' => true, 'created_at' => now(), 'updated_at' => now()];
                }
            }
            foreach (array_chunk($rows, 200) as $batch) {
                DB::table('business_unit_products')->insertOrIgnore($batch);
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Product availability cutover is forward-only.');
    }
};
