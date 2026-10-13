<?php

namespace App\Http\Requests;

use App\Concerns\BusinessProfileRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RegisterBusinessRequest extends FormRequest
{
    use BusinessProfileRules;

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => mb_strtolower(trim((string) $this->input('username'))),
            'owner_email' => mb_strtolower(trim((string) $this->input('owner_email'))),
            'business_email' => mb_strtolower(trim((string) $this->input('business_email'))),
            'currency' => strtoupper((string) $this->input('currency', 'TZS')),
            'timezone' => $this->input('timezone', 'Africa/Dar_es_Salaam'),
            'country' => $this->input('country', 'Tanzania'), 'default_language' => $this->input('default_language', 'English'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->businessRules(),
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner_phone' => ['required', 'string', 'max:40'],
            'username' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/D', Rule::unique('users', 'username')],
            'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/D'],
            'categories_configured' => ['sometimes', 'boolean'],
            'categories' => ['nullable', 'array', 'max:20'], 'categories.*' => ['required', 'string', 'distinct', Rule::in(config('business.categories'))],
        ];
    }
}
