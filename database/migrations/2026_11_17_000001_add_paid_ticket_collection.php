<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_units', function (Blueprint $table) {
            $table->enum('order_collection_mode', ['WAITER_ACCOUNT', 'PAID_TICKET'])->default('WAITER_ACCOUNT');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('collection_mode', ['WAITER_ACCOUNT', 'PAID_TICKET'])->default('WAITER_ACCOUNT');
        });

        Schema::create('ticket_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('business_unit_id')->constrained();
            $table->foreignId('stock_location_id')->constrained();
            $table->foreignId('order_id')->unique()->constrained();
            $table->foreignId('cashier_shift_id')->constrained('shifts');
            $table->foreignId('collected_by')->nullable()->constrained('users');
            $table->decimal('amount', 16, 2);
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'cashier_shift_id', 'status'], 'ticket_collections_shift_status_idx');
            $table->index(['company_id', 'business_unit_id', 'stock_location_id', 'status'], 'ticket_collections_counter_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_collections');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('collection_mode'));
        Schema::table('business_units', fn (Blueprint $table) => $table->dropColumn('order_collection_mode'));
    }
};
