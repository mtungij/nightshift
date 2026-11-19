<?php

namespace App\Services;

use App\Domain\Decimal;
use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class CompanyAdminService
{
    public const ROLE = 'Company Admin / Owner';

    public function __construct(private TransactionService $tx) {}

    public function canManage(User $actor): bool
    {
        return $actor->hasPermission('manage-users') && $actor->hasPermission('manage-company')
            && $actor->hasPermission('all-branches') && $actor->hasPermission('all-business-units');
    }

    public function authorize(): void
    {
        abort_unless($this->canManage(app(TenantContext::class)->user()), 403);
    }

    /** Called under the company lock before removing an active administrator. */
    public function ensureAnotherActive(User $user): void
    {
        if (! $user->active || $user->role?->name !== self::ROLE) {
            return;
        }

        $other = User::where('company_id', $user->company_id)->where('role_id', $user->role_id)
            ->where('active', true)->where('id', '<>', $user->id)->lockForUpdate()->first(['id']);
        if (! $other) {
            throw ValidationException::withMessages(['transaction' => __('ui.At least one active Company Admin / Owner must remain.')]);
        }
    }

    public function assertRoleChange(User $user, string $new): void
    {
        $this->ensureAnotherActive($user);
        app(BusinessUnitService::class)->assertIdleStaff($user, 'Resolve open shifts, handovers and treasury cash before changing the staff role.');

        if ($new === self::ROLE || $new === 'POS Terminal') {
            app(StockService::class)->require($user->username && $user->operational_pin,
                __('ui.Set a login PIN for this employee before changing their role.'));
        }
        $this->assertWaiterSettled($user);
    }

    public function assertWaiterSettled(User $user): void
    {
        if ($user->role?->name === 'Waiter') {
            $pending = Order::withoutGlobalScopes()->where('company_id', $user->company_id)->where('waiter_id', $user->id)
                ->whereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE', 'ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED'])->exists();
            app(StockService::class)->require(! $pending && Decimal::cmp(app(WaiterLedgerService::class)->balance($user->id), '0') === 0,
                __('ui.Resolve the waiter’s open orders and outstanding balance before changing their role.'));
        }
    }

    public function setStatus(int $id, bool $active): User
    {
        $this->authorize();

        return $this->tx->run(function () use ($id, $active) {
            $user = User::where('company_id', app(TenantContext::class)->companyId())->lockForUpdate()->findOrFail($id);
            abort_unless($user->role?->name === self::ROLE, 403);
            app(BusinessUnitService::class)->status($user, $active);

            return $user->refresh();
        });
    }

    public function deleteAccount(User $user): void
    {
        abort_unless($user->is(Auth::user()), 403);
        if (! $user->company_id) {
            $user->delete();

            return;
        }

        $this->tx->run(function () use ($user) {
            $current = User::where('company_id', $user->company_id)->lockForUpdate()->findOrFail($user->id);
            $this->ensureAnotherActive($current);
            $current->delete();
        });
    }
}
