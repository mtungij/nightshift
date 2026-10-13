<?php

namespace App\Actions\Fortify;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $input['username'] = mb_strtolower(trim($input['username'] ?? ''));
        Validator::make($input, [
            ...$this->profileRules(),
            'username' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/D', 'unique:users,username'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/D'],
        ])->validate();

        return app(OnboardingService::class)->register($input);
    }
}
