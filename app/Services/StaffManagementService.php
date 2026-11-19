<?php

namespace App\Services;

use App\Domain\BusinessUnitContext;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffManagementService
{
    public function __construct(
        private TransactionService $tx,
        private CompanyAdminService $admins,
        private BusinessUnitService $units,
        private StockService $stock,
        private AuditService $audit,
    ) {}

    private function managed(int $id, bool $lock = false): User
    {
        Gate::authorize('manage-users');
        $actor = app(TenantContext::class)->user();
        $user = User::where('company_id', $actor->company_id)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        abort_unless(in_array($user->role?->name, [...config('business.staff_roles'), CompanyAdminService::ROLE], true), 403);
        if ($user->isPosTerminal()) {
            $this->admins->authorize();
        }
        $this->units->assertManagedUser($user);
        if ($actor->counters_configured && ! $this->admins->canManage($actor)) {
            abort_if(array_diff($this->assignmentIds($user, 'counter_user', 'stock_location_id'), $this->assignmentIds($actor, 'counter_user', 'stock_location_id')), 403);
        }
        $branches = app(TenantContext::class)->branches();
        abort_if($branches !== null && array_diff($this->assignmentIds($user, 'branch_user', 'branch_id'), $branches), 403);

        return $user;
    }

    /** @return list<string> */
    private function roles(): array
    {
        return $this->admins->canManage(app(TenantContext::class)->user())
            ? [...config('business.staff_roles'), CompanyAdminService::ROLE]
            : array_values(array_diff(config('business.staff_roles'), ['POS Terminal']));
    }

    /** @return array<string, mixed> */
    public function details(int $id, bool $deletion): array
    {
        $user = $this->managed($id);
        if ($deletion) {
            return ['archive' => $user->archived_at !== null || $this->hasHistory($user)];
        }

        return [
            ...$this->snapshot($user),
            'roles' => Role::whereIn('name', $this->roles())->orderBy('name')->get(['id', 'name'])->all(),
            'branches' => Branch::where('active', true)->orderBy('name')->get(['id', 'name'])->all(),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array{id: int, role_changed: bool}
     */
    public function update(array $data): array
    {
        return $this->tx->run(fn () => app(BusinessUnitContext::class)->withoutSelection(function () use ($data) {
            $user = $this->managed((int) $data['id'], true);
            $this->stock->require($user->archived_at === null, __('ui.Archived staff cannot be edited.'));
            $role = Role::whereKey($data['role_id'])->whereIn('name', $this->roles())->firstOrFail();
            $old = $this->snapshot($user);
            $changedRole = $role->id !== $user->role_id;

            $profile = array_intersect_key($data, array_flip(['first_name', 'last_name', 'name', 'username', 'email', 'phone']));
            $profile['email'] = mb_strtolower(trim($profile['email']));
            if ($user->username !== null && array_key_exists('username', $profile) && $profile['username'] === null) {
                throw ValidationException::withMessages(['username' => __('validation.required', ['attribute' => __('ui.Username')])]);
            }
            Validator::make($profile, [
                'email' => [Rule::unique('users')->ignore($user)],
                'username' => ['nullable', Rule::unique('users')->ignore($user)],
            ])->validate();
            $user->forceFill($profile);
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            if ($changedRole) {
                $this->admins->assertRoleChange($user, $role->name);
            }

            $editAssignments = $changedRole || array_key_exists('branch_ids', $data)
                || array_key_exists('business_unit_ids', $data) || array_key_exists('counter_ids', $data)
                || array_key_exists('terminal_stock_location_id', $data);
            if ($editAssignments) {
                $this->assignments($user, $role, $data);
            } else {
                $this->saveProfile($user);
            }
            $new = $this->snapshot($user);
            $this->recordChange('STAFF_UPDATED', $user, $old, $new);
            if ($changedRole) {
                $this->recordChange('STAFF_ROLE_CHANGED', $user, $old, $new);
            }

            return ['id' => $user->id, 'role_changed' => $changedRole];
        }));
    }

    private function saveProfile(User $user): void
    {
        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => __('ui.This username or email is already registered.')]);
        }
    }

    /** @param array<string, mixed> $data */
    private function assignments(User $user, Role $role, array $data): void
    {
        $branches = array_map('intval', $data['branch_ids'] ?? []);
        $unitIds = array_map('intval', $data['business_unit_ids'] ?? []);
        $counterIds = array_map('intval', $data['counter_ids'] ?? []);
        $terminalId = isset($data['terminal_stock_location_id']) ? (int) $data['terminal_stock_location_id'] : null;
        $this->stock->require($branches !== [], __('ui.Choose at least one branch.'));
        abort_unless(Branch::where('active', true)->whereIn('id', $branches)->count() === count($branches), 404);
        $units = BusinessUnit::where('active', true)->whereIn('id', $unitIds)->whereIn('branch_id', $branches)->get();
        abort_unless($units->count() === count($unitIds), 404);
        $this->stock->require($role->name === CompanyAdminService::ROLE || $unitIds !== [], __('ui.Choose at least one Business Unit.'));

        $terminal = null;
        if ($role->name === 'POS Terminal') {
            $this->stock->require(count($branches) === 1 && count($unitIds) === 1 && $counterIds === [], __('ui.Select one Business Unit and one Counter.'));
            $unit = $units->first();
            $this->stock->require($unit->advanced_locations_enabled || $terminalId === null || $terminalId === $unit->default_stock_location_id, __('ui.Use the Business Unit operational stock context.'));
            $terminalId = $unit->advanced_locations_enabled ? $terminalId : $unit->default_stock_location_id;
            $this->stock->require((bool) $terminalId, __('ui.Select one Business Unit and one Counter.'));
            $terminal = StockLocation::where('type', 'COUNTER')->where('active', true)->where('business_unit_id', $unit->id)
                ->where('branch_id', $branches[0])->findOrFail($terminalId);
        } else {
            $this->stock->require($terminalId === null && ($role->name === 'Cashier' || $counterIds === []), __('ui.Choose authorized counters.'));
        }
        if ($role->name === 'Cashier') {
            $locations = StockLocation::where('type', 'COUNTER')->where('active', true)->whereIn('business_unit_id', $unitIds)->whereIn('branch_id', $branches)->get();
            abort_unless($locations->whereIn('id', $counterIds)->count() === count($counterIds), 404);
            foreach ($units as $unit) {
                if ($unit->advanced_locations_enabled) {
                    $this->stock->require($locations->where('business_unit_id', $unit->id)->whereIn('id', $counterIds)->isNotEmpty(), __('ui.Choose a counter for each Business Unit with advanced stock locations.'));
                } else {
                    $default = $locations->where('business_unit_id', $unit->id)->firstWhere('id', $unit->default_stock_location_id);
                    $this->stock->require((bool) $default, __('ui.Choose authorized counters.'));
                    $counterIds[] = $default->id;
                }
            }
            $counterIds = array_values(array_unique($counterIds));
        }

        $user->forceFill(['role_id' => $role->id, 'terminal_stock_location_id' => $terminal?->id,
            'terminal_business_unit_id' => $terminal?->business_unit_id])->setRelation('role', $role);
        $this->saveProfile($user);
        $this->units->branches($user, $branches, staffEditor: true);
        $this->units->assign($user, $unitIds);
        if ($role->name === 'Cashier' || $this->assignmentIds($user, 'counter_user', 'stock_location_id') !== []) {
            $this->units->counters($user, $counterIds);
        }
        $user->forceFill(['counters_configured' => $role->name === 'Cashier'])->save();
    }

    public function status(int $id, bool $active): User
    {
        return $this->tx->run(function () use ($id, $active) {
            $user = $this->managed($id, true);
            $old = $this->snapshot($user);
            if (! $active) {
                $this->assertRemovalAllowed($user);
            }
            $this->units->status($user, $active, staffEditor: true);
            $user->refresh();
            if ($old['active'] !== (bool) $user->active) {
                $this->recordChange($active ? 'STAFF_ACTIVATED' : 'STAFF_DEACTIVATED', $user, $old, $this->snapshot($user));
            }

            return $user;
        });
    }

    /** @return array{id: int, archived: bool} */
    public function delete(int $id, bool $archiveOnly): array
    {
        return $this->tx->run(function () use ($id, $archiveOnly) {
            $user = $this->managed($id, true);
            $this->assertRemovalAllowed($user);
            if ($user->archived_at !== null) {
                return ['id' => $id, 'archived' => true];
            }
            $this->units->assertIdleStaff($user);
            $this->admins->assertWaiterSettled($user);
            $old = $this->snapshot($user);
            $archive = $archiveOnly || $this->hasHistory($user);
            DB::table('sessions')->where('user_id', $id)->delete();

            if ($archive) {
                $user->forceFill(['active' => false, 'archived_at' => now(), 'remember_token' => null])->save();
                $this->recordChange('STAFF_ARCHIVED', $user, $old, $this->snapshot($user));
            } else {
                foreach (['branch_user', 'business_unit_user', 'counter_user'] as $table) {
                    DB::table($table)->where('company_id', $user->company_id)->where('user_id', $id)->delete();
                }
                $user->delete();
                $this->recordChange('STAFF_DELETED', $user, $old, []);
            }

            return ['id' => $id, 'archived' => $archive];
        });
    }

    private function assertRemovalAllowed(User $user): void
    {
        $this->admins->ensureAnotherActive($user);
        $this->stock->require($user->id !== app(TenantContext::class)->user()->id, __('ui.You cannot deactivate or delete your own staff account.'));
    }

    private function hasHistory(User $user): bool
    {
        // Check the actual schema so new operational references are protected even if their FK cascades or nulls out.
        $mutable = ['branch_user', 'business_unit_user', 'counter_user', 'passkeys', 'sessions'];
        if (DB::table('whatsapp_report_schedules')->where('company_id', $user->company_id)->where('created_by', $user->id)->exists()) {
            return true;
        }
        $schema = DB::getDriverName() === 'sqlite' ? 'main' : DB::connection()->getDatabaseName();
        foreach (Schema::getTableListing($schema, schemaQualified: false) as $table) {
            if (in_array($table, $mutable, true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $index = array_search('id', $foreign['foreign_columns'], true);
                if ($foreign['foreign_table'] !== 'users' || $index === false) {
                    continue;
                }
                $query = DB::table($table)->where($foreign['columns'][$index], $user->id);
                $company = array_search('company_id', $foreign['foreign_columns'], true);
                if ($company !== false) {
                    $query->where($foreign['columns'][$company], $user->company_id);
                }
                if ($query->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<int> */
    private function assignmentIds(User $user, string $table, string $column): array
    {
        return DB::table($table)->where('company_id', $user->company_id)->where('user_id', $user->id)
            ->orderBy($column)->pluck($column)->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [
            ...$user->only(['id', 'first_name', 'last_name', 'name', 'username', 'email', 'phone', 'role_id', 'terminal_stock_location_id']),
            'active' => (bool) $user->active, 'role' => $user->role->name, 'archived_at' => $user->archived_at?->toIso8601String(),
            'branch_ids' => $this->assignmentIds($user, 'branch_user', 'branch_id'),
            'business_unit_ids' => $this->assignmentIds($user, 'business_unit_user', 'business_unit_id'),
            'counter_ids' => $this->assignmentIds($user, 'counter_user', 'stock_location_id'),
        ];
    }

    /** @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private function recordChange(string $action, User $user, array $old, array $new): void
    {
        $fields = array_filter(array_keys($old), fn ($key) => ($new[$key] ?? null) !== $old[$key]);
        if ($fields === []) {
            return;
        }
        $keys = array_flip([...$fields, 'name', 'role', 'role_id']);
        $branches = array_values(array_unique([...$old['branch_ids'], ...($new['branch_ids'] ?? [])]));
        $this->audit->record($action, 'Management', 'User', $user->id, array_intersect_key($old, $keys),
            [...array_intersect_key($new, $keys), 'changed_fields' => array_values($fields)], count($branches) === 1 ? $branches[0] : null);
    }
}
