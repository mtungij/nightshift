<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('terminal_stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
        });
        // Add the role without changing existing installations' customized grants.
        $role = Role::firstOrCreate(['name' => 'POS Terminal']);
        if ($role->wasRecentlyCreated) {
            $role->permissions()->sync(collect(['create-orders', 'view-orders'])->map(fn ($name) => Permission::firstOrCreate(['name' => $name])->id)->all());
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terminal_stock_location_id');
        });
    }
};
