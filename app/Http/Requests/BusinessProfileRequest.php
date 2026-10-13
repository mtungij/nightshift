<?php

namespace App\Http\Requests;

use App\Concerns\BusinessProfileRules;
use Illuminate\Foundation\Http\FormRequest;

final class BusinessProfileRequest extends FormRequest
{
    use BusinessProfileRules;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('manage-company') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->businessRules();
    }
}
