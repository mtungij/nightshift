<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['shift_stock_snapshots', 'shift_stock_counts'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->unsignedBigInteger('branch_id');
                $t->unsignedBigInteger('stock_location_id');
                $t->unsignedBigInteger('shift_id');
                $t->unsignedBigInteger('user_id');
                $t->json('data');
                if ($name === 'shift_stock_snapshots') {
                    $t->string('kind', 10);
                    $t->unique(['shift_id', 'kind']);
                } else {
                    $t->unsignedInteger('revision')->default(1);
                    $t->string('basis', 64);
                    $t->text('reason')->nullable();
                    $t->unsignedBigInteger('approved_by')->nullable();
                    $t->timestamp('approved_at')->nullable();
                    $t->text('approval_note')->nullable();
                    $t->unique('shift_id');
                    $t->foreign(['company_id', 'approved_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
                }
                $t->timestamps();
                foreach (['branch_id' => 'branches', 'stock_location_id' => 'stock_locations', 'shift_id' => 'shifts', 'user_id' => 'users'] as $column => $parent) {
                    $t->foreign(['company_id', $column])->references(['company_id', 'id'])->on($parent)->restrictOnDelete();
                }
            });
        }
        foreach (['UPDATE', 'DELETE'] as $action) {
            $trigger = 'shift_stock_snapshot_'.strtolower($action);
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$action} ON shift_stock_snapshots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable shift stock snapshot'");
            } elseif (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$action} ON shift_stock_snapshots BEGIN SELECT RAISE(ABORT, 'Immutable shift stock snapshot'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $action) {
            DB::unprepared('DROP TRIGGER IF EXISTS shift_stock_snapshot_'.$action);
        }
        Schema::dropIfExists('shift_stock_counts');
        Schema::dropIfExists('shift_stock_snapshots');
    }
};
