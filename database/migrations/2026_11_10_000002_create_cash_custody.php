<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            foreach (['branch_id', 'stock_location_id', 'source_shift_id', 'from_user_id', 'created_by'] as $column) {
                $t->unsignedBigInteger($column);
            }
            $t->unsignedBigInteger('to_user_id')->nullable();
            $t->unsignedBigInteger('replaces_id')->nullable()->unique();
            $t->string('number', 40);
            $t->string('destination', 20);
            $t->decimal('amount', 16, 2);
            $t->string('basis', 64)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'number']);
            $t->index(['company_id', 'source_shift_id']);
            $t->index(['company_id', 'to_user_id', 'stock_location_id'], 'cash_handovers_recipient_idx');
            foreach (['branch_id' => 'branches', 'stock_location_id' => 'stock_locations', 'source_shift_id' => 'shifts', 'from_user_id' => 'users', 'to_user_id' => 'users', 'created_by' => 'users', 'replaces_id' => 'cash_handovers'] as $column => $table) {
                $t->foreign(['company_id', $column])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('treasury_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            foreach (['branch_id', 'custodian_id', 'created_by'] as $column) {
                $t->unsignedBigInteger($column);
            }
            foreach (['handover_id', 'goods_receipt_id', 'employee_id', 'reversal_of'] as $column) {
                $t->unsignedBigInteger($column)->nullable();
            }
            $t->string('number', 40);
            $t->enum('direction', ['IN', 'OUT']);
            $t->string('transaction_type', 30);
            $t->decimal('amount', 16, 2);
            $t->string('reference_type', 40);
            $t->string('reference_id', 100)->nullable();
            $t->string('external_reference', 100);
            $t->string('category', 100)->nullable();
            $t->string('bank', 100)->nullable();
            $t->string('pay_period', 100)->nullable();
            $t->text('description');
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'number']);
            $t->unique(['company_id', 'transaction_type', 'external_reference'], 'treasury_business_ref_uq');
            $t->unique('handover_id');
            $t->unique('reversal_of');
            $t->index(['company_id', 'custodian_id', 'branch_id'], 'treasury_custody_idx');
            foreach (['branch_id' => 'branches', 'custodian_id' => 'users', 'created_by' => 'users', 'employee_id' => 'users', 'goods_receipt_id' => 'goods_receipts', 'handover_id' => 'cash_handovers', 'reversal_of' => 'treasury_entries'] as $column => $table) {
                $t->foreign(['company_id', $column])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        Schema::create('cash_handover_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            foreach (['branch_id', 'handover_id', 'created_by'] as $column) {
                $t->unsignedBigInteger($column);
            }
            $t->unsignedBigInteger('related_shift_id')->nullable();
            $t->unsignedBigInteger('ledger_entry_id')->nullable();
            $t->string('status', 20);
            $t->decimal('amount', 16, 2)->nullable();
            $t->decimal('difference', 16, 2)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'handover_id', 'id'], 'cash_events_history_idx');
            foreach (['branch_id' => 'branches', 'handover_id' => 'cash_handovers', 'created_by' => 'users', 'related_shift_id' => 'shifts', 'ledger_entry_id' => 'treasury_entries'] as $column => $table) {
                $t->foreign(['company_id', $column])->references(['company_id', 'id'])->on($table)->restrictOnDelete();
            }
        });
        foreach (['cash_handovers', 'cash_handover_events', 'treasury_entries'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                $trigger = $table.'_immutable_'.strtolower($action);
                if (DB::getDriverName() === 'mysql') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$action} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable cash custody history'");
                } elseif (DB::getDriverName() === 'sqlite') {
                    DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$action} ON {$table} BEGIN SELECT RAISE(ABORT, 'Immutable cash custody history'); END");
                }
            }
        }
        // Add only new grants; preserve every existing role permission and historical shift.
        foreach (config('treasury.permissions') as $permission) {
            DB::table('permissions')->insertOrIgnore(['name' => $permission]);
        }
        DB::table('roles')->insertOrIgnore(['name' => 'Accountant', 'created_at' => now(), 'updated_at' => now()]);
        foreach (config('treasury.grants') as $role => $grants) {
            $roleId = DB::table('roles')->where('name', $role)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($grants as $permission) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('name', $permission)->value('id')]);
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Cash custody migrations are forward-only. Restore a verified backup to roll back.');
    }
};
