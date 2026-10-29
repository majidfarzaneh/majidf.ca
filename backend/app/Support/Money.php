<?php

namespace App\Support;

class Money
{
    /** Format cents as "$1,240" (or "$1,240.50" when there are cents). */
    public static function format(?int $cents, bool $forceDecimals = false): string
    {
        if ($cents === null) {
            return '—';
        }

        $decimals = $forceDecimals || $cents % 100 !== 0 ? 2 : 0;

        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, $decimals);
    }

    /** Parse a user-entered dollar amount ("1,240.50") into cents. */
    public static function toCents(float|int|string|null $amount): int
    {
        return (int) round(((float) str_replace([',', '$'], '', (string) $amount)) * 100);
    }
}
