<?php

namespace App\Policies;

use App\Domain\StaffVisibility;
use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->hasPermission('view-orders') && app(StaffVisibility::class)->canViewOrder($user, $order);
    }
}
