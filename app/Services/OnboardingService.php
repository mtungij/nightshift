<?php

namespace App\Services;

use App\Models\User;

final class OnboardingService
{
    public function __construct(private BusinessOnboardingService $business) {}

    /** @param array<string, mixed> $data */
    public function register(array $data): User
    {
        return $this->business->register($data);
    }
}
