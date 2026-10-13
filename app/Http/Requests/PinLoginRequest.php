<?php

namespace App\Http\Requests;

use Laravel\Fortify\Http\Requests\LoginRequest;

final class PinLoginRequest extends LoginRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['username' => mb_strtolower(trim((string) $this->input('username')))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['username' => ['required', 'string', 'max:100'], 'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/D']];
    }
}
