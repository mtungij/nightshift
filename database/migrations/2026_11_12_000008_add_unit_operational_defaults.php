<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_locations', function (Blueprint $t) {
            $t->boolean('system_managed')->default(false);
        });
        Schema::table('business_units', function (Blueprint $t) {
            $t->unsignedBigInteger('default_stock_location_id')->nullable()->unique();
            $t->unsignedSmallInteger('max_active_cashiers')->default(1);
            $t->boolean('advanced_locations_enabled')->default(false);
            $t->text('location_cutover_note')->nullable();
            $t->foreign(['company_id', 'default_stock_location_id'], 'bu_default_location_company_fk')->references(['company_id', 'id'])->on('stock_locations')->restrictOnDelete();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('terminal_business_unit_id')->nullable();
            $t->foreign(['company_id', 'terminal_business_unit_id'], 'terminal_unit_company_fk')->references(['company_id', 'id'])->on('business_units')->restrictOnDelete();
        });
        // Configuration only. No stocks, movements, shifts, orders or financial rows
        // are changed; ambiguous physical balances require an explicit transfer.
        foreach (DB::table('business_units')->orderBy('id')->get() as $unit) {
            $counters = DB::table('stock_locations')->where('company_id', $unit->company_id)->where('branch_id', $unit->branch_id)->where('business_unit_id', $unit->id)->where('type', 'COUNTER')->where('active', true)->where('is_shared', false)->orderBy('id')->get();
            $active = DB::table('shifts as s')->join('operational_unit_contexts as c', fn ($q) => $q->on('c.company_id', '=', 's.company_id')->on('c.record_id', '=', 's.id')->where('c.record_type', 'shifts'))->where('s.company_id', $unit->company_id)->where('c.business_unit_id', $unit->id)->where('s.type', 'CASHIER')->where('s.status', 'OPEN')->count();
            $legacyCapacity = (int) $counters->sum('max_active_cashiers');
            $capacity = min(65535, max(1, $active, $legacyCapacity));
            $note = null;
            $location = null;
            $advanced = $counters->count() > 1 || $active > $legacyCapacity;
            if (! $unit->active) {
                $note = 'Inactive Business Unit: retained locations/history require supervised cutover; no stock was moved.';
            } elseif ($counters->count() === 1) {
                $location = $counters->first()->id;
            } else {
                $location = DB::table('stock_locations')->insertGetId(['company_id' => $unit->company_id, 'branch_id' => $unit->branch_id, 'business_unit_id' => $unit->id, 'name' => $unit->name.' Stock', 'type' => 'COUNTER', 'active' => true, 'is_shared' => false, 'system_managed' => true, 'max_active_cashiers' => $capacity, 'created_at' => now(), 'updated_at' => now()]);
                if ($advanced) {
                    $note = 'Multiple legacy counters: advanced mode retained; new default is empty. Review and transfer actual stock before switching to simple mode.';
                }
            }
            if ($active > $legacyCapacity) {
                $note = trim(($note ?? '').' Active cashier count exceeds legacy limits. Advanced location limits remain enforced pending supervised review.');
            }
            $legacyStock = DB::table('stocks')->where('company_id', $unit->company_id)->where('quantity', '!=', 0)->whereIn('stock_location_id', DB::table('stock_locations')->where('company_id', $unit->company_id)->where('business_unit_id', $unit->id)->when($location, fn ($q) => $q->where('id', '!=', $location))->select('id'))->exists();
            if ($legacyStock && ! $note) {
                $note = 'Stock remains outside the default location. Allocate it through audited transfers; quantities were not copied.';
            }
            DB::table('business_units')->where('id', $unit->id)->update(['default_stock_location_id' => $location, 'max_active_cashiers' => $capacity, 'advanced_locations_enabled' => $advanced, 'location_cutover_note' => $note]);
            DB::table('audit_logs')->insert(['company_id' => $unit->company_id, 'branch_id' => $unit->branch_id, 'user_id' => null, 'user_name' => 'System migration', 'action' => 'UNIT_OPERATIONAL_CUTOVER', 'module' => 'Business Units', 'reference_type' => 'BusinessUnit', 'reference_id' => (string) $unit->id, 'old_values' => json_encode([]), 'new_values' => json_encode(['source' => '2026_11_12_operational_defaults', 'default_stock_location_id' => $location, 'max_active_cashiers' => $capacity, 'advanced_locations_enabled' => $advanced, 'review_note' => $note]), 'created_at' => now(), 'updated_at' => now()]);
        }
        // Terminal assignment is mutable configuration, not historical attribution.
        foreach (DB::table('users')->whereNotNull('terminal_stock_location_id')->get(['id', 'company_id', 'terminal_stock_location_id']) as $user) {
            $unit = DB::table('stock_locations')->where('company_id', $user->company_id)->where('id', $user->terminal_stock_location_id)->value('business_unit_id');
            DB::table('users')->where('id', $user->id)->update(['terminal_business_unit_id' => $unit]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Operational defaults and legacy cutover are forward-only.');
    }
};
