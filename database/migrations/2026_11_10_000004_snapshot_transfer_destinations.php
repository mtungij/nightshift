<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot the second endpoint too. A shared source must never reveal
        // another unit's private destination to a user of that shared store.
        foreach (DB::table('stock_transfers')->orderBy('id')->lazyById(500) as $transfer) {
            $exists = DB::table('operational_unit_contexts')->where('company_id', $transfer->company_id)->where('record_type', 'stock_transfer_destinations')->where('record_id', $transfer->id)->exists();
            if ($exists) {
                continue;
            }
            $location = DB::table('stock_locations')->where('company_id', $transfer->company_id)->where('id', $transfer->destination_location_id)->first();
            DB::table('operational_unit_contexts')->insert(['company_id' => $transfer->company_id, 'branch_id' => $transfer->branch_id, 'record_type' => 'stock_transfer_destinations', 'record_id' => $transfer->id, 'business_unit_id' => $location->business_unit_id, 'stock_location_id' => $location->id, 'created_at' => $transfer->created_at]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Business unit context is forward-only. Restore a verified backup.');
    }
};
