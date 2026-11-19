<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());
    }

    public function down(): void
    {
        throw new LogicException('Staff archival records must be retained.');
    }
};
