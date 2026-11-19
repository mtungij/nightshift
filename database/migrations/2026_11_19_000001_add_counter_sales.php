<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('waiter_id')->nullable()->change();
            $table->enum('order_source', ['WAITER', 'CASHIER_DIRECT'])->default('WAITER');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER orders_source_immutable BEFORE UPDATE OF order_source ON orders WHEN OLD.order_source <> NEW.order_source BEGIN SELECT RAISE(ABORT, 'An order source cannot be changed'); END");
        } elseif (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER orders_source_immutable BEFORE UPDATE ON orders FOR EACH ROW BEGIN IF OLD.order_source <> NEW.order_source THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An order source cannot be changed'; END IF; END");
        }

        DB::table('permissions')->insertOrIgnore(['name' => 'counter-sales']);
        $permission = DB::table('permissions')->where('name', 'counter-sales')->value('id');
        foreach (DB::table('roles')->whereIn('name', ['Company Admin / Owner', 'Super Admin'])->pluck('id') as $role) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $role]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Counter Sale order sources are forward-only.');
    }
};
