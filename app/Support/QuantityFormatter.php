<?php

namespace App\Support;

final class QuantityFormatter
{
    public static function format(string|int|float|null $value): string
    {
        $text = (string) ($value ?? '0');
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
