<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $refs = [['shifts', 'authorized_by', 'users'], ['orders', 'issued_by', 'users'], ['void_requests', 'approved_by', 'users'], ['stock_adjustments', 'approved_by', 'users'], ['stock_transfers', 'received_by', 'users'], ['stock_movements', 'reversal_of', 'stock_movements'], ['audit_logs', 'branch_id', 'branches'], ['audit_logs', 'user_id', 'users']];
        foreach ($refs as [$table,$column,$parent]) {
            $already = collect(Schema::getForeignKeys($table))->contains(fn ($key) => in_array($column, $key['columns'], true));
            if (! $already) {
                Schema::table($table, function (Blueprint $t) use ($table, $column, $parent) {
                    $t->foreign(['company_id', $column], 'integrity_'.substr(md5($table.$column), 0, 12))->references(['company_id', 'id'])->on($parent)->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void {}
};
