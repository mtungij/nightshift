<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('stock_location_id')->nullable();
            $table->foreign(['company_id', 'user_id'], 'notifications_staff_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'stock_location_id'], 'notifications_counter_fk')->references(['company_id', 'id'])->on('stock_locations')->restrictOnDelete();
        });
        DB::table('permissions')->insertOrIgnore(['name' => 'view-staff-operations']);
        $permission = DB::table('permissions')->where('name', 'view-staff-operations')->value('id');
        foreach (DB::table('roles')->whereIn('name', ['Company Admin / Owner', 'Super Admin', 'Manager', 'Supervisor'])->pluck('id') as $role) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
        // Replace Manager's inherited wildcard branch access with an explicit
        // opt-in. Other management permissions and staff assignments stay intact.
        DB::table('permission_role')->whereIn('role_id', DB::table('roles')->where('name', 'Manager')->select('id'))->whereIn('permission_id', DB::table('permissions')->where('name', 'all-branches')->select('id'))->delete();
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropForeign('notifications_staff_fk');
            $table->dropForeign('notifications_counter_fk');
            $table->dropColumn(['user_id', 'stock_location_id']);
        });
        DB::table('permissions')->where('name', 'view-staff-operations')->delete();
    }
};
