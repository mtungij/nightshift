<?php

namespace App\Support;

final class TicketAmountFormatter
{
    /** Group digits for narrow tickets without converting stored decimals to floats. */
    public static function format(string $value): string
    {
        $parts = explode('.', $value, 2);
        $whole = preg_replace('/(?<=\d)(?=(?:\d{3})+(?!\d))/', ',', $parts[0]) ?? $parts[0];

        return $whole.(isset($parts[1]) ? '.'.$parts[1] : '');
    }
}
