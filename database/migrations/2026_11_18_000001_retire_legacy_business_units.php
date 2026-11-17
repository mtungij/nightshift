<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $units = DB::table('business_units')
            ->whereRaw('UPPER(TRIM(code)) = ?', ['GENERAL'])
            ->orWhere(fn ($q) => $q->whereNull('created_by')->whereRaw('UPPER(TRIM(name)) = ?', ['GENERAL']))
            ->orderBy('id')->pluck('id');
        $namedLocations = DB::table('stock_locations')->whereRaw('UPPER(TRIM(name)) = ?', ['GENERAL STOCK']);
        $locations = DB::table('stock_locations')->whereIn('business_unit_id', $units)
            ->orWhere(fn ($q) => $q->whereNull('business_unit_id')->whereRaw('UPPER(TRIM(name)) = ?', ['GENERAL STOCK']))
            ->orderBy('id')->get();
        $locationIds = $locations->pluck('id');

        // Check before MySQL DDL, which commits independently of the cleanup transaction.
        $renames = (clone $namedLocations)->where(fn ($q) => $q
            ->where(fn ($q) => $q->whereNotNull('business_unit_id')->whereNotIn('business_unit_id', $units))
            ->orWhereIn('id', DB::table('business_unit_location')->whereNotIn('business_unit_id', $units)->select('stock_location_id'))
            ->orWhereIn('id', DB::table('business_units')->whereNotIn('id', $units)->whereNotNull('default_stock_location_id')->select('default_stock_location_id')))
            ->pluck('id');
        $this->assertReconciled($units->all(), $locationIds->all(), $renames->all());

        foreach (['business_units', 'stock_locations'] as $table) {
            if (! Schema::hasColumn($table, 'archived_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->timestamp('archived_at')->nullable());
            }
        }

        if ($units->isEmpty() && $locations->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($units, $locations) {
            $now = now();
            // These are mutable grants and catalogue settings. Nothing is copied to another unit.
            foreach (['business_unit_user', 'business_unit_products', 'business_unit_prices', 'business_unit_location'] as $pivot) {
                DB::table($pivot)->whereIn('business_unit_id', $units)->delete();
            }
            foreach (['business_unit_location', 'counter_user'] as $pivot) {
                DB::table($pivot)->whereIn('stock_location_id', $locations->pluck('id'))->delete();
            }

            foreach ($locations as $location) {
                if ($location->system_managed && strtoupper(trim($location->name)) === 'GENERAL STOCK' && ! $this->locationReferenced($location->id, $units->all())) {
                    DB::table('business_units')->whereIn('id', $units)->where('default_stock_location_id', $location->id)->update(['default_stock_location_id' => null]);
                    DB::table('stock_locations')->where('id', $location->id)->delete();
                } else {
                    DB::table('stock_locations')->where('id', $location->id)->whereNull('archived_at')->update(['active' => false, 'archived_at' => $now]);
                }
            }

            foreach ($units as $id) {
                if ($this->unitReferenced($id)) {
                    DB::table('business_units')->where('id', $id)->whereNull('archived_at')->update(['active' => false, 'archived_at' => $now]);
                } else {
                    DB::table('business_units')->where('id', $id)->delete();
                }
            }
        });
    }

    /** @param list<int> $units
     * @param  list<int>  $locations
     * @param  list<int>  $renames
     */
    private function assertReconciled(array $units, array $locations, array $renames): void
    {
        $explicitUnitsToRename = DB::table('business_units')->whereNotIn('id', $units)
            ->whereRaw('UPPER(TRIM(name)) = ?', ['GENERAL'])->pluck('id')->all();
        if ($units === [] && $locations === [] && $renames === [] && $explicitUnitsToRename === []) {
            return;
        }

        $orders = $this->atLegacyLocation('orders', $units, $locations);
        $debt = DB::table('waiter_ledger_entries')->whereIn('order_id', (clone $orders)->select('id'))
            ->select('waiter_id')->groupBy('waiter_id')->havingRaw('SUM(debit) - SUM(credit) > 0');
        $handovers = $this->atLegacyLocation('cash_handovers', $units, $locations)->whereIn('cash_handovers.id',
            DB::table('cash_handover_events as e')->whereIn('e.status', ['PENDING', 'DISPUTED', 'RECEIVED', 'APPROVED'])
                ->where(fn ($q) => $q->whereIn('e.status', ['PENDING', 'DISPUTED'])->orWhere('cash_handovers.destination', 'NEXT_CASHIER'))
                ->whereRaw('e.id = (SELECT MAX(e2.id) FROM cash_handover_events e2 WHERE e2.handover_id = e.handover_id)')->select('e.handover_id'));
        $blockers = [
            'stock (location/product)' => DB::table('stocks')->whereIn('stock_location_id', $locations)->where('quantity', '<>', 0)
                ->get(['stock_location_id', 'product_id'])->map(fn ($row) => $row->stock_location_id.'/'.$row->product_id)->all(),
            'open shifts' => $this->atLegacyLocation('shifts', $units, $locations)->where('status', 'OPEN')->pluck('id')->all(),
            'unfinished orders' => (clone $orders)->whereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE', 'ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED'])->pluck('id')->all(),
            'waiters with ledger debt' => $debt->pluck('waiter_id')->all(),
            'pending ticket collections' => $this->atLegacyLocation('ticket_collections', $units, $locations)
                ->where('status', 'PENDING')->pluck('id')->all(),
            'pending stock adjustments' => $this->atLegacyLocation('stock_adjustments', $units, $locations)->where('status', 'PENDING')->pluck('id')->all(),
            'unfinished stock transfers' => DB::table('stock_transfers')->whereIn('status', ['DRAFT', 'IN_TRANSIT'])
                ->where(fn ($q) => $q->whereIn('source_location_id', $locations)->orWhereIn('destination_location_id', $locations)
                    ->orWhereIn('id', DB::table('operational_unit_contexts')->whereIn('record_type', ['stock_transfers', 'stock_transfer_destinations'])->whereIn('business_unit_id', $units)->select('record_id')))->pluck('id')->all(),
            'unresolved cash handovers' => $handovers->pluck('id')->all(),
            'active terminal users' => DB::table('users')->where('active', true)
                ->where(fn ($q) => $q->whereIn('terminal_business_unit_id', $units)->orWhereIn('terminal_stock_location_id', $locations))->pluck('id')->all(),
            'locations to rename in explicit units' => $renames,
            'explicit Business Units to rename' => $explicitUnitsToRename,
        ];
        $messages = [];
        foreach (array_filter($blockers) as $kind => $ids) {
            $messages[] = $kind.': '.implode(', ', $ids);
        }
        if ($messages !== []) {
            throw new RuntimeException('Reconcile legacy Business Units before migration. '.implode('; ', $messages));
        }
    }

    /** @param list<int> $units
     * @param  list<int>  $locations
     */
    private function atLegacyLocation(string $table, array $units, array $locations): Builder
    {
        return DB::table($table)->where(function ($q) use ($table, $units, $locations) {
            $q->whereIn('stock_location_id', $locations)
                ->orWhereIn('id', DB::table('operational_unit_contexts')->where('record_type', $table)->whereIn('business_unit_id', $units)->select('record_id'));
            if ($table === 'ticket_collections') {
                $q->orWhereIn('business_unit_id', $units);
            }
        });
    }

    /** @param list<int> $units */
    private function locationReferenced(int $id, array $units): bool
    {
        // A default belonging to an explicit unit must be preserved even if empty.
        if (DB::table('business_units')->where('default_stock_location_id', $id)->whereNotIn('id', $units)->exists()) {
            return true;
        }

        return $this->referenced($id, [
            'stocks' => ['stock_location_id'],
            'shifts' => ['stock_location_id'],
            'orders' => ['stock_location_id'],
            'goods_receipts' => ['stock_location_id'],
            'stock_transfers' => ['source_location_id', 'destination_location_id'],
            'stock_adjustments' => ['stock_location_id'],
            'stock_movements' => ['stock_location_id'],
            'notifications' => ['stock_location_id'],
            'shift_stock_snapshots' => ['stock_location_id'],
            'shift_stock_counts' => ['stock_location_id'],
            'cash_handovers' => ['stock_location_id'],
            'operational_unit_contexts' => ['stock_location_id'],
            'ticket_collections' => ['stock_location_id'],
            'users' => ['terminal_stock_location_id'],
            'whatsapp_report_schedules' => ['stock_location_id'],
        ]);
    }

    private function unitReferenced(int $id): bool
    {
        return $this->referenced($id, [
            'stock_locations' => ['business_unit_id'],
            'operational_unit_contexts' => ['business_unit_id'],
            'ticket_collections' => ['business_unit_id'],
            'users' => ['terminal_business_unit_id'],
            'whatsapp_report_schedules' => ['business_unit_id'],
        ]) || DB::table('whatsapp_recipients')->whereJsonContains('business_unit_ids', $id)->exists()
            || DB::table('whatsapp_delivery_logs')->whereJsonContains('scope_business_unit_ids', $id)->exists();
    }

    /** @param array<string, list<string>> $references */
    private function referenced(int $id, array $references): bool
    {
        foreach ($references as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->where($column, $id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function down(): void
    {
        throw new LogicException('Legacy Business Unit retirement is forward-only. Restore a verified backup.');
    }
};
