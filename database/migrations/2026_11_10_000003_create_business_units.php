<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_units', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->string('name', 100);
            $t->string('code', 30);
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'branch_id', 'id'], 'bu_branch_identity');
            $t->unique(['company_id', 'branch_id', 'code'], 'bu_branch_code');
            $t->unique(['company_id', 'branch_id', 'name'], 'bu_branch_name');
            $t->foreign(['company_id', 'branch_id'])->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $t->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('business_unit_user', function (Blueprint $t) {
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('business_unit_id');
            $t->unsignedBigInteger('user_id');
            $t->primary(['business_unit_id', 'user_id']);
            $t->foreign(['company_id', 'business_unit_id'])->references(['company_id', 'id'])->on('business_units')->restrictOnDelete();
            $t->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::table('users', fn (Blueprint $t) => $t->boolean('business_units_configured')->default(false));
        Schema::table('stock_locations', function (Blueprint $t) {
            $t->unsignedBigInteger('business_unit_id')->nullable();
            $t->boolean('is_shared')->default(false);
            $t->foreign(['company_id', 'branch_id', 'business_unit_id'], 'location_bu_fk')->references(['company_id', 'branch_id', 'id'])->on('business_units')->restrictOnDelete();
        });
        Schema::table('users', fn (Blueprint $t) => $t->boolean('counters_configured')->default(false));
        Schema::create('counter_user', function (Blueprint $t) {
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('stock_location_id');
            $t->unsignedBigInteger('user_id');
            $t->primary(['stock_location_id', 'user_id']);
            $t->foreign(['company_id', 'stock_location_id'])->references(['company_id', 'id'])->on('stock_locations')->restrictOnDelete();
            $t->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('business_unit_location', function (Blueprint $t) {
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('business_unit_id');
            $t->unsignedBigInteger('stock_location_id');
            $t->primary(['business_unit_id', 'stock_location_id'], 'shared_location_pk');
            $t->foreign(['company_id', 'branch_id', 'business_unit_id'], 'shared_bu_fk')->references(['company_id', 'branch_id', 'id'])->on('business_units')->restrictOnDelete();
            $t->foreign(['company_id', 'stock_location_id'], 'shared_location_fk')->references(['company_id', 'id'])->on('stock_locations')->restrictOnDelete();
        });
        Schema::create('business_unit_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('business_unit_id');
            $t->unsignedBigInteger('product_id');
            $t->boolean('available')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'business_unit_id', 'product_id'], 'bu_product_unique');
            $t->foreign(['company_id', 'business_unit_id'])->references(['company_id', 'id'])->on('business_units')->restrictOnDelete();
            $t->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
        Schema::create('business_unit_prices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('business_unit_id');
            $t->unsignedBigInteger('product_unit_id');
            $t->decimal('selling_price', 16, 2);
            $t->timestamps();
            $t->unique(['company_id', 'business_unit_id', 'product_unit_id'], 'bu_price_unique');
            $t->foreign(['company_id', 'business_unit_id'])->references(['company_id', 'id'])->on('business_units')->restrictOnDelete();
            $t->foreign(['company_id', 'product_unit_id'])->references(['company_id', 'id'])->on('product_units')->restrictOnDelete();
        });
        Schema::create('operational_unit_contexts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('record_type', 40);
            $t->unsignedBigInteger('record_id');
            $t->unsignedBigInteger('business_unit_id')->nullable();
            $t->unsignedBigInteger('stock_location_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['company_id', 'record_type', 'record_id'], 'operational_context_identity');
            $t->index(['company_id', 'business_unit_id', 'record_type'], 'operational_unit_filter');
            $t->foreign(['company_id', 'branch_id', 'business_unit_id'], 'context_bu_fk')->references(['company_id', 'branch_id', 'id'])->on('business_units')->restrictOnDelete();
            $t->foreign(['company_id', 'stock_location_id'], 'context_location_fk')->references(['company_id', 'id'])->on('stock_locations')->restrictOnDelete();
        });
        // No financial rows or immutable triggers are rewritten during cutover.
        foreach (DB::table('branches')->get() as $branch) {
            $unit = DB::table('business_units')->insertGetId(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'name' => 'General', 'code' => 'GENERAL', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('stock_locations')->where('company_id', $branch->company_id)->where('branch_id', $branch->id)->update(['business_unit_id' => $unit]);
            foreach (DB::table('branch_user')->where('company_id', $branch->company_id)->where('branch_id', $branch->id)->pluck('user_id') as $user) {
                DB::table('business_unit_user')->insertOrIgnore(['company_id' => $branch->company_id, 'business_unit_id' => $unit, 'user_id' => $user]);
            }
            foreach (config('business_units.records') as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach (DB::table($table)->where('company_id', $branch->company_id)->where('branch_id', $branch->id)->orderBy('id')->lazyById(500) as $row) {
                    DB::table('operational_unit_contexts')->insert(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'record_type' => $table, 'record_id' => $row->id, 'business_unit_id' => $unit, 'stock_location_id' => $row->stock_location_id ?? null, 'created_at' => $row->created_at ?? now()]);
                }
            }
        }
        foreach (['UPDATE', 'DELETE'] as $action) {
            $name = 'operational_context_immutable_'.strtolower($action);
            DB::unprepared(DB::getDriverName() === 'mysql'
                ? "CREATE TRIGGER {$name} BEFORE {$action} ON operational_unit_contexts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable operational unit context'"
                : "CREATE TRIGGER {$name} BEFORE {$action} ON operational_unit_contexts BEGIN SELECT RAISE(ABORT, 'Immutable operational unit context'); END");
        }
        foreach (['manage-business-units', 'all-business-units'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name]);
            foreach (DB::table('roles')->whereIn('name', ['Super Admin', 'Company Admin / Owner'])->pluck('id') as $role) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('name', $name)->value('id')]);
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Business unit migration is forward-only. Restore a verified backup.');
    }
};
