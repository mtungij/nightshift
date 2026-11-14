<?php

namespace App\Support;

use App\Models\BusinessUnit;
use App\Models\StockLocation;

/** Read-only workspace representation; never repairs persisted data. */
final class CounterLocationPresenter
{
    /** @return array<string,mixed> */
    public function present(StockLocation $location): array
    {
        if ($location->exists && (! array_key_exists('max_active_cashiers', $location->getAttributes()) || ! array_key_exists('company_id', $location->getAttributes()))) {
            $location = StockLocation::findOrFail($location->id);
        }
        $data = $location->toArray();
        // Creation defaults do not replace a null value on an already hydrated model.
        $data['max_active_cashiers'] = max(1, (int) ($location->max_active_cashiers ?? 1));
        $data['active_cashier_count'] = $data['active_cashiers'] ?? $location->active_cashiers;
        $data['active_cashiers'] = $data['active_cashier_count'];
        $data['is_shared'] = (bool) $location->is_shared;
        $data['business_unit_name'] = ! $data['is_shared'] && $location->business_unit_id ? BusinessUnit::find($location->business_unit_id)?->name : null;

        return $data;
    }
}
