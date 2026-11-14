<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_locations', 'max_active_cashiers')) {
            Schema::table('stock_locations', fn (Blueprint $table) => $table->unsignedSmallInteger('max_active_cashiers')->default(1));
        }
        DB::table('stock_locations')->where('type', 'COUNTER')->where(fn ($q) => $q->whereNull('max_active_cashiers')->orWhere('max_active_cashiers', '<', 1))->update(['max_active_cashiers' => 1]);
    }

    public function down(): void
    {
        throw new LogicException('Cashier limit configuration is forward-only.');
    }
};
