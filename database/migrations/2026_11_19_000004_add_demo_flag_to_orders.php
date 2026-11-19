<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some installations already tag demo orders. Preserve their existing flags.
        if (! Schema::hasColumn('orders', 'is_demo')) {
            Schema::table('orders', fn (Blueprint $table) => $table->boolean('is_demo')->default(false));
        }
    }

    public function down(): void
    {
        // The column can predate this migration and contains historical data.
    }
};
