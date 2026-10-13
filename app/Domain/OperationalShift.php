<?php

namespace App\Domain;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;

final class OperationalShift
{
    public function type(User $user): ?string
    {
        return match (($user->role->name ?? null)) {
            'Waiter' => 'WAITER',
            'Cashier' => 'CASHIER',
            default => null,
        };
    }

    public function active(User $user): ?Shift
    {
        $type = $this->type($user);
        if (! $type || ! $user->active || ! ($user->company->active ?? false)) {
            return null;
        }

        return Shift::where('company_id', $user->company_id)->where('user_id', $user->id)
            ->where('type', $type)->where('status', 'OPEN')
            ->whereIn('branch_id', Branch::where('active', true)->select('id'))
            ->whereIn('stock_location_id', StockLocation::where('active', true)->where('type', 'COUNTER')->select('id'))
            ->latest('id')->first();
    }

    public function destination(User $user): string
    {
        if (! $this->type($user)) {
            return route('dashboard', absolute: false);
        }

        return $this->active($user)
            ? route('dashboard', absolute: false).'#'.($this->type($user) === 'WAITER' ? 'pos' : 'waiters')
            : route('shift.open', absolute: false);
    }
}
