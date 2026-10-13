<?php

namespace App\Policies;

use App\Domain\StaffVisibility;
use App\Models\Shift;
use App\Models\User;

final class ShiftPolicy
{
    public function view(User $user, Shift $shift): bool
    {
        return $user->hasPermission('open-shifts') && app(StaffVisibility::class)->canViewShift($user, $shift);
    }
}
