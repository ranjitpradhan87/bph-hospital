<?php

namespace App\Support;

final class Phone
{
    /** "+91 98765 43210", "098765-43210" → "9876543210". */
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    public static function isValid(string $normalized): bool
    {
        return (bool) preg_match('/^[6-9]\d{9}$/', $normalized);
    }
}
