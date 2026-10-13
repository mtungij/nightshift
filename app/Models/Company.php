<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $trading_name
 * @property string|null $business_type
 * @property string|null $address
 * @property string|null $city
 * @property string|null $tin
 * @property string|null $vrn
 * @property string|null $logo_path
 * @property string $country
 * @property string $default_language
 * @property string|null $receipt_header
 * @property string|null $receipt_footer
 * @property bool $active
 * @property string|null $subscription_status
 * @property int|null $owner_id
 * @property string|null $registration_key
 * @property-read User|null $owner
 * @property string $currency
 * @property string $timezone
 * @property array<string, mixed>|null $settings
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Company extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    protected function casts(): array
    {
        return ['settings' => 'array', 'active' => 'boolean'];
    }
}
