<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $username
 * @property string $name
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $phone
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property int|null $company_id
 * @property int|null $role_id
 * @property bool $active
 * @property string|null $theme_preference
 * @property string|null $language_preference
 * @property string|null $operational_pin
 * @property int $pin_failed_attempts
 * @property CarbonInterface|null $pin_locked_until
 * @property-read Company $company
 * @property-read Role $role
 * @property-read Collection<int, Branch> $branches
 */
#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'operational_pin', 'pin_failed_attempts', 'pin_locked_until', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->username) {
                $user->username = mb_strtolower(trim($user->username));
            }
        });
        static::created(function (User $user): void {
            if (! $user->username) {
                $username = (substr(Str::slug($user->name), 0, 80) ?: 'user').'-'.$user->id;
                if (static::where('username', $username)->exists()) {
                    $username .= '-'.Str::lower(Str::random(8));
                }
                $user->forceFill(['username' => $username])->saveQuietly();
            }
        });
    }

    public function preferredTheme(): string
    {
        return $this->theme_preference === 'dark' ? 'dark' : 'light';
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('company_id');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->active && $this->company_id && $this->role->permissions()->where('name', $permission)->exists();
    }

    public function canAccessBranch(int $id): bool
    {
        return Branch::withoutGlobalScopes()->where('company_id', $this->company_id)->whereKey($id)->exists() && ($this->hasPermission('all-branches') || $this->branches()->withoutGlobalScopes()->where('branches.id', $id)->exists());
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_locked_until' => 'datetime',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
