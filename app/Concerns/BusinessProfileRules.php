<?php

namespace App\Concerns;

use Illuminate\Validation\Rule;

trait BusinessProfileRules
{
    /** @return array<string, mixed> */
    protected function businessRules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', Rule::in(config('business.types'))],
            'business_phone' => ['required', 'string', 'max:40'],
            'business_email' => ['required', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:255'], 'city' => ['required', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:40'], 'vrn' => ['nullable', 'string', 'max:40'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/D'],
            'timezone' => ['required', 'timezone'], 'country' => ['required', 'string', 'max:100'],
            'default_language' => ['required', Rule::in(['English', 'Swahili'])],
            'receipt_header' => ['nullable', 'string', 'max:255'], 'receipt_footer' => ['nullable', 'string', 'max:255'],
        ];
    }
}
