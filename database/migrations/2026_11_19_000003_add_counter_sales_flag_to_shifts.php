<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', fn (Blueprint $table) => $table->boolean('allow_counter_sales')->default(false));
    }

    public function down(): void
    {
        throw new LogicException('Per-shift Counter Sale settings must be retained.');
    }
};
