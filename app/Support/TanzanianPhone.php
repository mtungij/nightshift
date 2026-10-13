<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class TanzanianPhone
{
    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[\s()+-]/', '', trim($phone)) ?? '';
        if (str_starts_with($phone, '00255')) {
            $phone = substr($phone, 2);
        }
        if (preg_match('/^0[67]\d{8}$/D', $phone)) {
            $phone = '255'.substr($phone, 1);
        }
        if (! preg_match('/^255[67]\d{8}$/D', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Tanzanian mobile number.']);
        }

        return $phone;
    }
}
