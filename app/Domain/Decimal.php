<?php

namespace App\Domain;

use Illuminate\Validation\ValidationException;

final class Decimal
{
    /** @return numeric-string */
    public static function money(string|int $value): string
    {
        return self::round($value, 2);
    }

    /** @return numeric-string */
    public static function quantity(string|int $value): string
    {
        return self::round($value, 6);
    }

    /** @return numeric-string */
    public static function round(string|int $value, int $scale): string
    {
        $value = (string) $value;
        if (! preg_match('/^-?\d+(\.\d+)?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Use a valid decimal amount.']);
        }
        if (strlen(explode('.', ltrim($value, '-'))[0]) > 10) {
            throw ValidationException::withMessages(['amount' => 'Amount is too large.']);
        }
        $half = self::numeric('0.'.str_repeat('0', $scale).'5');
        $value = self::numeric($value);

        return bcadd($value, self::numeric(bccomp($value, '0', 10) < 0 ? '-'.$half : $half), $scale);
    }

    /** @return numeric-string */
    public static function add(string|int $a, string|int $b, int $scale = 2): string
    {
        return bcadd(self::numeric($a), self::numeric($b), $scale);
    }

    /** @return numeric-string */
    public static function sub(string|int $a, string|int $b, int $scale = 2): string
    {
        return bcsub(self::numeric($a), self::numeric($b), $scale);
    }

    /** @return numeric-string */
    public static function mul(string|int $a, string|int $b, int $scale = 2): string
    {
        return self::round(bcmul(self::numeric($a), self::numeric($b), $scale + 2), $scale);
    }

    public static function cmp(string|int $a, string|int $b, int $scale = 6): int
    {
        return bccomp(self::numeric($a), self::numeric($b), $scale);
    }

    /** @return numeric-string */
    private static function numeric(string|int $value): string
    {
        $value = (string) $value;
        if (! is_numeric($value) || ! preg_match('/^-?\d+(\.\d+)?$/D', $value)) {
            throw ValidationException::withMessages(['amount' => 'Use a valid decimal amount.']);
        }

        return $value;
    }
}
