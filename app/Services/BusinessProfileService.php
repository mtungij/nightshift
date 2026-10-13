<?php

namespace App\Services;

use App\Domain\TenantContext;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BusinessProfileService
{
    public function __construct(private TransactionService $tx, private AuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function update(array $data): Company
    {
        Gate::authorize('manage-company');
        $logo = null;
        $previousLogo = null;
        try {
            if (($data['logo'] ?? null) instanceof UploadedFile) {
                $logo = $data['logo']->store('business-logos', 'local');
                if (! $logo) {
                    throw ValidationException::withMessages(['logo' => 'The logo could not be saved.']);
                }
            }
            $company = $this->tx->run(function () use ($data, $logo, &$previousLogo): Company {
                $company = app(TenantContext::class)->user()->company->fresh();
                $previousLogo = $company->logo_path;
                $fields = [];
                foreach (['business_name' => 'name', 'trading_name' => 'trading_name', 'business_type' => 'business_type', 'business_email' => 'email', 'business_phone' => 'phone', 'address' => 'address', 'city' => 'city', 'tin' => 'tin', 'vrn' => 'vrn', 'currency' => 'currency', 'timezone' => 'timezone', 'country' => 'country', 'default_language' => 'default_language', 'receipt_header' => 'receipt_header', 'receipt_footer' => 'receipt_footer'] as $input => $column) {
                    if (array_key_exists($input, $data)) {
                        $fields[$column] = $data[$input];
                    }
                }
                if ($logo) {
                    $fields['logo_path'] = $logo;
                }
                $old = $company->only(array_keys($fields));
                $company->update($fields);
                $this->audit->record('BUSINESS_PROFILE_UPDATED', 'Management', 'Company', $company->id, $old, $company->only(array_keys($fields)));

                return $company;
            });
        } catch (Throwable $error) {
            if ($logo) {
                Storage::disk('local')->delete($logo);
            }
            throw $error;
        }
        if ($logo && $previousLogo && str_starts_with($previousLogo, 'business-logos/')) {
            Storage::disk('local')->delete($previousLogo);
        }

        return $company;
    }
}
