<?php

namespace App\Services;

use App\Domain\TenantContext;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BusinessOnboardingService
{
    public function __construct(private TenantDefaultDataService $defaults, private AuditService $audit) {}

    public static function registrationKey(string $name, string $email): string
    {
        return hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name)).'|'.mb_strtolower(trim($email)));
    }

    /** @param array<string, mixed> $data */
    public function register(#[\SensitiveParameter] array $data): User
    {
        $logo = null;
        try {
            if (($data['logo'] ?? null) instanceof UploadedFile) {
                $logo = $data['logo']->store('business-logos', 'local');
                if (! $logo) {
                    throw ValidationException::withMessages(['logo' => 'The logo could not be saved. Please try again.']);
                }
            }

            return DB::transaction(function () use ($data, $logo): User {
                (new PermissionSeeder)->run(preserveExisting: true);
                $name = $data['business_name'] ?? $data['company_name'] ?? $data['name'].'’s workspace';
                $email = mb_strtolower($data['business_email'] ?? $data['email']);
                $key = self::registrationKey($name, $email);
                if (Company::where('registration_key', $key)->exists()) {
                    throw ValidationException::withMessages(['business_name' => 'This business is already registered. Sign in to its existing account.']);
                }
                $company = Company::create([
                    'name' => $name, 'trading_name' => $data['trading_name'] ?? $name,
                    'business_type' => $data['business_type'] ?? 'Bar', 'email' => $email,
                    'phone' => $data['business_phone'] ?? null, 'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null, 'tin' => $data['tin'] ?? null, 'vrn' => $data['vrn'] ?? null,
                    'logo_path' => $logo, 'currency' => $data['currency'] ?? 'TZS',
                    'timezone' => $data['timezone'] ?? 'Africa/Dar_es_Salaam', 'country' => $data['country'] ?? 'Tanzania',
                    'default_language' => $data['default_language'] ?? 'English',
                    'receipt_header' => $data['receipt_header'] ?? null, 'receipt_footer' => $data['receipt_footer'] ?? null,
                    'active' => true, 'subscription_status' => 'trial', 'registration_key' => $key,
                    'settings' => ['onboarding' => ['business' => isset($data['business_name']) ? 'complete' : 'resume', 'branch' => 'complete', 'locations' => 'complete', 'categories' => 'complete']],
                ]);
                $owner = new User;
                $owner->fill(['name' => $data['name'] ?? trim($data['first_name'].' '.$data['last_name']), 'email' => mb_strtolower($data['owner_email'] ?? $data['email']), 'username' => $data['username'] ?? null, 'password' => Str::password(64)]);
                $owner->forceFill(['operational_pin' => Hash::make($data['pin']), 'first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null, 'phone' => $data['owner_phone'] ?? null, 'company_id' => $company->id, 'role_id' => Role::where('name', 'Company Admin / Owner')->firstOrFail()->id, 'active' => true]);
                $owner->save();
                $company->update(['owner_id' => $owner->id]);
                $selected = isset($data['categories_configured']) ? ($data['categories'] ?? []) : null;
                $this->defaults->seed($owner, $selected);
                app(TenantContext::class)->run($owner, fn () => $this->audit->record('BUSINESS_ONBOARDED', 'Onboarding', 'Company', $company->id, [], ['name' => $company->name, 'owner_id' => $owner->id]));

                return $owner;
            });
        } catch (Throwable $error) {
            if ($logo) {
                Storage::disk('local')->delete($logo);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(User::where('email', mb_strtolower($data['owner_email'] ?? $data['email']))->exists()
                    ? ['owner_email' => 'This email address is already registered.']
                    : ['business_name' => 'This business is already registered.']);
            }
            throw $error;
        }
    }
}
