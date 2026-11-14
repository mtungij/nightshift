<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Nullable deliberately: existing history is never inferred or rewritten.
            $table->unsignedBigInteger('cashier_shift_id')->nullable();
            $table->foreign(['company_id', 'cashier_shift_id'])->references(['company_id', 'id'])->on('shifts')->restrictOnDelete();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->foreign(['company_id', 'opened_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['company_id', 'stock_location_id', 'type', 'status'], 'shifts_counter_operation');
        });
    }

    public function down(): void
    {
        // Forward-only operational history; restore a backup to roll back a release.
    }
};
