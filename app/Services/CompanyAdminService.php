<?php

namespace App\Services;

use App\Domain\Decimal;
use App\Domain\TenantContext;
use App\Models\BusinessUnit;
use App\Models\Order;
use App\Models\Role;
use App\Models\StockLocation;
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

    /** @param array<string, mixed> $data */
    public function changeRole(array $data): User
    {
        $this->authorize();

        return $this->tx->run(function () use ($data) {
            $user = User::where('company_id', app(TenantContext::class)->companyId())->lockForUpdate()->findOrFail($data['id']);
            $ordinary = array_diff(config('business.staff_roles'), ['POS Terminal']);
            $old = $user->role?->name;
            $new = $data['role'];
            abort_unless(($old === self::ROLE && in_array($new, $ordinary, true))
                || ($new === self::ROLE && in_array($old, $ordinary, true)), 403);

            $this->ensureAnotherActive($user);
            $units = app(BusinessUnitService::class);
            $units->assertIdleStaff($user);
            $stock = app(StockService::class);
            if ($new === self::ROLE) {
                $stock->require($user->username && $user->operational_pin,
                    __('ui.Set a login PIN for this employee before promoting them.'));
            }
            if ($old === 'Waiter') {
                $pending = Order::withoutGlobalScopes()->where('company_id', $user->company_id)->where('waiter_id', $user->id)
                    ->whereIn('status', ['DRAFT', 'WAITING_FOR_ISSUE', 'ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED'])->exists();
                $stock->require(! $pending && Decimal::cmp(app(WaiterLedgerService::class)->balance($user->id), 0) === 0,
                    __('ui.Resolve the waiter’s open orders and outstanding balance before changing their role.'));
            }
            if ($old === self::ROLE) {
                $branch = (int) ($data['branch_id'] ?? 0);
                $ids = $data['business_unit_ids'] ?? [];
                $selectedUnits = BusinessUnit::where('branch_id', $branch)->whereIn('id', $ids)->get();
                $stock->require($ids !== [] && $selectedUnits->count() === count($ids), __('ui.Choose authorized Business Units.'));
                $counters = [];
                if ($new === 'Cashier') {
                    $available = StockLocation::where('branch_id', $branch)->whereIn('business_unit_id', $ids)
                        ->where('type', 'COUNTER')->where('active', true)->whereNull('archived_at')->pluck('id')->all();
                    $counters = $data['counter_ids'] ?? [];
                    if ($counters === [] && ! $selectedUnits->contains('advanced_locations_enabled', true)) {
                        $counters = $available;
                    }
                    $stock->require($counters !== [] && ! array_diff($counters, $available), __('ui.Choose authorized counters.'));
                }
                $units->branches($user, [$branch]);
                $units->assign($user, $ids);
                $units->counters($user, $counters);
            }
            $user->forceFill([
                'role_id' => Role::where('name', $new)->firstOrFail()->id,
                'counters_configured' => $new === 'Cashier',
            ])->save();
            app(AuditService::class)->record($new === self::ROLE ? 'COMPANY_ADMIN_PROMOTED' : 'COMPANY_ADMIN_DEMOTED', 'Management', 'User', $user->id, ['role' => $old], ['role' => $new]);

            return $user;
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
