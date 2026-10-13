<?php

namespace App\Services;

use App\Domain\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class StaffPinService
{
    public function set(int $id, #[\SensitiveParameter] ?string $pin): User
    {
        Gate::authorize('manage-users');
        Gate::authorize('manage-company');
        $actor = app(TenantContext::class)->user();
        abort_unless(in_array($actor->role->name, ['Company Admin / Owner', 'Super Admin'], true), 403);
        $this->validate($pin, true);

        return app(TransactionService::class)->run(function () use ($actor, $id, $pin) {
            $staff = User::where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($id);
            $staff->forceFill(['operational_pin' => $pin === null ? null : Hash::make($pin), 'pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();
            app(AuditService::class)->record('STAFF_PIN_RESET', 'Users', 'User', $staff->id, [], ['configured' => $pin !== null]);

            return $staff;
        });
    }

    public function verify(int $id, #[\SensitiveParameter] string $pin): User
    {
        $this->validate($pin);
        $actor = app(TenantContext::class)->user();
        $key = 'staff-pin:'.$actor->company_id.':'.$actor->id.':'.hash('sha256', request()->ip() ?? 'local');
        if (RateLimiter::tooManyAttempts($key, 10)) {
            abort(429, 'Too many PIN attempts. Try again shortly.');
        }

        return $this->check($id, $pin, $actor->company_id, $key);
    }

    public function login(string $username, #[\SensitiveParameter] string $pin, ?int $companyId = null): ?User
    {
        $this->validate($pin);
        $user = User::where('username', mb_strtolower(trim($username)))->when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('active', true)->whereHas('company', fn ($q) => $q->where('active', true))->first();
        if (! $user) {
            return null;
        }

        return $this->check($user->id, $pin, $user->company_id, 'login-pin:'.hash('sha256', $username.'|'.request()->ip()));
    }

    private function check(int $id, #[\SensitiveParameter] string $pin, int $companyId, string $key): User
    {
        // This transaction must finish before the order transaction: a rejected
        // PIN must not roll back its persistent failure counter.
        $result = DB::transaction(function () use ($companyId, $id, $pin) {
            $staff = User::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            abort_unless($staff->active && $staff->company->active, 403);
            if ($staff->pin_locked_until?->isFuture()) {
                return null;
            }
            if ($staff->pin_locked_until) {
                $staff->pin_failed_attempts = 0;
                $staff->pin_locked_until = null;
            }
            if (! $staff->operational_pin || ! Hash::check($pin, $staff->operational_pin)) {
                $staff->pin_failed_attempts++;
                if ($staff->pin_failed_attempts >= 5) {
                    $staff->pin_locked_until = now()->addMinutes(15);
                }
                $staff->save();

                return false;
            }
            $staff->forceFill(['pin_failed_attempts' => 0, 'pin_locked_until' => null])->save();

            return $staff;
        });
        if (! $result) {
            RateLimiter::hit($key, 60);
            if ($result === null) {
                abort(429, 'This staff PIN is temporarily locked. Try again in 15 minutes or ask your Company Admin to reset it.');
            }
            throw ValidationException::withMessages(['pin' => 'The PIN could not be confirmed.']);
        }

        return $result;
    }

    private function validate(#[\SensitiveParameter] ?string $pin, bool $nullable = false): void
    {
        if (! ($nullable && $pin === null) && ! preg_match('/^[0-9]{4}$/D', $pin ?? '')) {
            throw ValidationException::withMessages(['pin' => 'Enter exactly four numeric digits.']);
        }
    }
}
