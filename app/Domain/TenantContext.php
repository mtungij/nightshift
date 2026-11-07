<?php

namespace App\Domain;

use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class TenantContext
{
    private ?User $actor = null;

    public function user(): ?User
    {
        return $this->actor ?? Auth::user();
    }

    public function companyId(): int
    {
        return (int) ($this->user()->company_id ?? 0);
    }

    /** @return list<int>|null */
    public function branches(): ?array
    {
        $u = $this->user();

        if ($u?->isPosTerminal()) {
            return array_values(StockLocation::withoutGlobalScopes()->where('company_id', $u->company_id)->whereKey($u->terminal_stock_location_id)->where('type', 'COUNTER')->where('active', true)->pluck('branch_id')->map(fn ($id) => (int) $id)->all());
        }

        return $u?->hasPermission('all-branches') ? null : ($u ? array_values(DB::table('branch_user')->where('company_id', $u->company_id)->where('user_id', $u->id)->pluck('branch_id')->map(fn ($id) => (int) $id)->values()->all()) : []);
    }

    /** Read-only tenant/visibility scope without replacing Laravel's authenticated user. */
    public function withUserScope(User $user, callable $callback): mixed
    {
        $previous = $this->actor;
        $this->actor = $user;
        try {
            return $callback();
        } finally {
            $this->actor = $previous;
        }
    }

    public function run(User $user, callable $callback): mixed
    {
        $previous = $this->actor;
        $previousAuth = Auth::user();
        $this->actor = $user;
        Auth::setUser($user);
        try {
            return $callback();
        } finally {
            $this->actor = $previous;
            if ($previousAuth) {
                Auth::setUser($previousAuth);
            } else {
                Auth::forgetUser();
            }
        }
    }
}
