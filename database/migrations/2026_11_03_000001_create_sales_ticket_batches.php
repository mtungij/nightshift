<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_ticket_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id');
            $table->foreign(['company_id', 'branch_id'], 'ticket_batch_branch_fk')->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreignId('order_id');
            $table->foreign(['company_id', 'order_id'], 'ticket_batch_order_fk')->references(['company_id', 'id'])->on('orders')->restrictOnDelete();
            $table->foreignId('user_id');
            $table->foreign(['company_id', 'user_id'], 'ticket_batch_user_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->char('token', 36)->unique();
            $table->string('request_key', 80);
            $table->boolean('reprint');
            $table->json('ticket');
            $table->json('copies');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_ticket_batches');
    }
};
