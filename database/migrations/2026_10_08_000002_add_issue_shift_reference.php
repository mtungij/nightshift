<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'issued_shift_id')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->unsignedBigInteger('issued_shift_id')->nullable();
                $t->foreign(['company_id', 'issued_shift_id'], 'order_issue_shift_fk')->references(['company_id', 'id'])->on('shifts')->restrictOnDelete();
            });
        }
    }

    public function down(): void {}
};
