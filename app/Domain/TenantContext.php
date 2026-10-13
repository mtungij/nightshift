<?php

namespace App\Domain;

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

        return $u?->hasPermission('all-branches') ? null : ($u ? array_values(DB::table('branch_user')->where('company_id', $u->company_id)->where('user_id', $u->id)->pluck('branch_id')->map(fn ($id) => (int) $id)->values()->all()) : []);
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
