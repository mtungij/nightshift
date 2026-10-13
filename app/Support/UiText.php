<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

final class UiText
{
    /** @return array<string, string> */
    public static function catalog(): array
    {
        $catalog = Lang::get('ui');

        return is_array($catalog) ? $catalog : [];
    }

    public static function get(string $text): string
    {
        $catalog = self::catalog();
        if (isset($catalog[$text])) {
            return $catalog[$text];
        }
        if (app()->getLocale() === 'sw' && preg_match('/^Insufficient stock for (.+)\. Available: ([0-9.]+)\.$/u', $text, $matches)) {
            return strtr($catalog['Insufficient stock for :product. Available: :available.'], [':product' => $matches[1], ':available' => $matches[2]]);
        }
        if (app()->getLocale() === 'sw' && preg_match('/^Cannot close shift: outstanding ([0-9.]+)\. A manager override needs a reason\.$/', $text, $matches)) {
            return strtr($catalog['Cannot close shift: outstanding :balance. A manager override needs a reason.'], [':balance' => $matches[1]]);
        }

        return $text;
    }
}
