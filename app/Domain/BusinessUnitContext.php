<?php

namespace App\Domain;

use App\Models\StockTransfer;
use App\Models\TenantModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Unit access never grants company, branch, role or counter access. */
final class BusinessUnitContext
{
    private ?int $selected = null;

    private ?int $selectedCompany = null;

    private ?int $selectedUser = null;

    /** @return list<int>|null */
    public function ids(?User $user = null): ?array
    {
        $user ??= app(TenantContext::class)->user();
        if (! $user) {
            return [];
        }
        if ($user->hasPermission('all-business-units')) {
            return null;
        }
        $units = DB::table('business_units')->where('company_id', $user->company_id);
        if (! $user->hasPermission('all-branches') && ! $user->isPosTerminal()) {
            $units->whereIn('branch_id', DB::table('branch_user')->where('company_id', $user->company_id)->where('user_id', $user->id)->select('branch_id'));
        }

        if ($user->isPosTerminal()) {
            return array_values($units->whereIn('id', DB::table('stock_locations')->where('company_id', $user->company_id)->where('id', $user->operationalTerminalLocationId())->where('active', true)->select('business_unit_id'))->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
        }

        return array_values($units->where(function ($q) use ($user) {
            $q->whereIn('id', DB::table('business_unit_user')->where('company_id', $user->company_id)->where('user_id', $user->id)->select('business_unit_id'));
            // Compatibility for pre-unit staff creation integrations: General only.
            if (! $user->business_units_configured) {
                $q->orWhere('code', 'GENERAL');
            }
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
    }

    public function can(User $user, ?int $id): bool
    {
        if (! $id) {
            return false;
        }
        $unit = DB::table('business_units')->where('company_id', $user->company_id)->where('id', $id)->first();

        return $unit && $user->canAccessBranch($unit->branch_id) && (($ids = $this->ids($user)) === null || in_array($id, $ids, true));
    }

    public function select(?int $id): void
    {
        if ($id) {
            abort_unless($this->can(app(TenantContext::class)->user(), $id), 403);
        }
        $this->selected = $id;
        $this->selectedCompany = app(TenantContext::class)->companyId();
        $this->selectedUser = app(TenantContext::class)->user()?->id;
    }

    public function withoutSelection(callable $callback): mixed
    {
        $previous = [$this->selected, $this->selectedCompany, $this->selectedUser];
        $this->select(null);
        try {
            return $callback();
        } finally {
            [$this->selected, $this->selectedCompany, $this->selectedUser] = $previous;
        }
    }

    public function selected(): ?int
    {
        return $this->selectedCompany === app(TenantContext::class)->companyId() && $this->selectedUser === app(TenantContext::class)->user()?->id ? $this->selected : null;
    }

    /**
     * @param  Builder<covariant TenantModel>  $query
     * @return Builder<covariant TenantModel>
     */
    public function apply(Builder $query, TenantModel $model): Builder
    {
        $table = $model->getTable();
        if ($table === 'treasury_entries' || (! in_array($table, ['business_units', 'stock_locations', 'stocks'], true) && ! in_array($table, config('business_units.records'), true))) {
            return $query;
        }
        $ids = $this->ids();
        $selection = $this->selected();
        if ($selection && $table !== 'business_units') {
            $ids = $ids === null || in_array($selection, $ids, true) ? [$selection] : [];
        }
        if ($ids === null) {
            return $query;
        }
        if ($table === 'business_units') {
            return $query->whereIn($model->qualifyColumn('id'), $ids);
        }
        $locations = DB::table('stock_locations')->where('company_id', app(TenantContext::class)->companyId())->where(function ($q) use ($ids) {
            $q->whereIn('business_unit_id', $ids)->orWhere(fn ($q) => $q->where('is_shared', true)->whereIn('id', DB::table('business_unit_location')->whereIn('business_unit_id', $ids)->select('stock_location_id')));
        })->select('id');
        if ($table === 'stock_locations') {
            $actor = app(TenantContext::class)->user();
            if (! $actor->isPosTerminal() && $actor->counters_configured) {
                $query->where(fn ($q) => $q->where('type', '!=', 'COUNTER')
                    ->orWhereIn('id', DB::table('counter_user')->where('company_id', $actor->company_id)->where('user_id', $actor->id)->select('stock_location_id'))
                    ->orWhereIn('id', DB::table('business_units')->where('company_id', $actor->company_id)->whereIn('id', $ids)->where('advanced_locations_enabled', false)->whereNotNull('default_stock_location_id')->select('default_stock_location_id')));
            }

            return $query->whereIn($model->qualifyColumn('id'), $locations);
        }
        if ($table === 'stocks') {
            return $query->whereIn($model->qualifyColumn('stock_location_id'), $locations);
        }
        if (! in_array($table, config('business_units.records'), true)) {
            return $query;
        }
        // Treasury cash remains one existing custodian account. Scope its source context in reports,
        // never hide debits when computing an authorized custodian's available physical cash.
        if ($table === 'treasury_entries') {
            return $query;
        }
        if ($table === 'audit_logs') {
            $query->where(fn ($q) => $q->where('reference_type', '!=', 'StockTransfer')->orWhereIn('reference_id', StockTransfer::select('id')));
        }
        if ($table === 'stock_transfers') {
            $destinations = DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', 'stock_transfer_destinations')->where(function ($q) use ($ids, $locations) {
                $q->whereIn('business_unit_id', $ids)->orWhere(fn ($q) => $q->whereNull('business_unit_id')->whereIn('stock_location_id', $locations));
            })->select('record_id');
            $query->whereIn($model->qualifyColumn('id'), $destinations);
        }
        if ($table === 'stock_transfer_items') {
            $query->whereIn($model->qualifyColumn('stock_transfer_id'), StockTransfer::select('id'));
        }
        $contexts = DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', $table)->where(function ($q) use ($ids, $locations) {
            $q->whereIn('business_unit_id', $ids)->orWhere(fn ($q) => $q->whereNull('business_unit_id')->whereIn('stock_location_id', $locations));
        })->select('record_id');

        return $query->whereIn($model->qualifyColumn('id'), $contexts);
    }

    public function capture(TenantModel $model): void
    {
        $table = $model->getTable();
        if (! in_array($table, config('business_units.records'), true)) {
            return;
        }
        $attributes = $model->getAttributes();
        $parent = null;
        $parents = ['order_id' => 'orders', 'source_shift_id' => 'shifts', 'handover_id' => 'cash_handovers', 'shift_id' => 'shifts', 'goods_receipt_id' => 'goods_receipts', 'stock_transfer_id' => 'stock_transfers', 'stock_adjustment_id' => 'stock_adjustments', 'return_id' => 'returns'];
        if ($table === 'payments') {
            $parents = ['shift_id' => 'shifts', 'order_id' => 'orders'];
        }
        foreach ($parents as $field => $type) {
            if (! empty($attributes[$field])) {
                $parent = DB::table('operational_unit_contexts')->where('company_id', $model->company_id)->where('record_type', $type)->where('record_id', $attributes[$field])->first();
                if ($parent) {
                    break;
                }
            }
        }
        if (! $parent && ! empty($attributes['reference_type']) && ! empty($attributes['reference_id'])) {
            $type = ['Order' => 'orders', 'Shift' => 'shifts', 'CashHandover' => 'cash_handovers', 'TreasuryEntry' => 'treasury_entries', 'GoodsReceipt' => 'goods_receipts', 'StockTransfer' => 'stock_transfers', 'StockAdjustment' => 'stock_adjustments', 'OrderReturn' => 'returns', 'VoidRequest' => 'void_requests'][$attributes['reference_type']] ?? null;
            if ($type) {
                $parent = DB::table('operational_unit_contexts')->where('company_id', $model->company_id)->where('record_type', $type)->where('record_id', $attributes['reference_id'])->first();
            }
            if ($attributes['reference_type'] === 'StockLocation') {
                $attributes['stock_location_id'] = $attributes['reference_id'];
            }
        }
        $locationId = $attributes['stock_location_id'] ?? $parent->stock_location_id ?? $attributes['source_location_id'] ?? null;
        $location = $locationId ? DB::table('stock_locations')->where('company_id', $model->company_id)->where('id', $locationId)->first() : null;
        $unit = $table === 'stock_movements' && $location ? $location->business_unit_id : ($parent->business_unit_id ?? $location?->business_unit_id);
        if (! $unit && ! $location) {
            $unit = DB::table('business_units')->where('company_id', $model->company_id)->where('branch_id', $attributes['branch_id'] ?? 0)->where('code', 'GENERAL')->value('id');
        }
        if ($table === 'audit_logs') {
            $values = $model->getAttribute('new_values');
            $explicit = $values['business_unit_id'] ?? ($attributes['reference_type'] === 'BusinessUnit' ? $attributes['reference_id'] : null);
            if ($explicit) {
                $candidate = DB::table('business_units')->where('company_id', $model->company_id)->where('branch_id', $attributes['branch_id'] ?? null)->where('id', $explicit)->value('id');
                if ($candidate) {
                    $unit = $candidate;
                }
            }
        }
        DB::table('operational_unit_contexts')->insert(['company_id' => $model->company_id, 'branch_id' => $attributes['branch_id'] ?? null, 'record_type' => $table, 'record_id' => $model->getKey(), 'business_unit_id' => $unit, 'stock_location_id' => $locationId, 'created_at' => now()]);
        if ($table === 'stock_transfers') {
            $destination = DB::table('stock_locations')->where('company_id', $model->company_id)->where('id', $attributes['destination_location_id'])->first();
            DB::table('operational_unit_contexts')->insert(['company_id' => $model->company_id, 'branch_id' => $attributes['branch_id'], 'record_type' => 'stock_transfer_destinations', 'record_id' => $model->getKey(), 'business_unit_id' => $destination->business_unit_id, 'stock_location_id' => $destination->id, 'created_at' => now()]);
        }

    }
}
