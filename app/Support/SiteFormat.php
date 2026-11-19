<?php

namespace App\Support;

/** Formatting helpers for the public Blade pages. */
final class SiteFormat
{
    private static ?array $icons = null;

    public static function iconBody(string $name): string
    {
        self::$icons ??= require resource_path('views/components/icons.php');

        return self::$icons[$name] ?? self::$icons['hospital'];
    }

    public static function address(?array $a): string
    {
        if (! $a) {
            return '';
        }
        $parts = array_filter([$a['line1'] ?? '', $a['line2'] ?? '', $a['city'] ?? '', trim(($a['state'] ?? '').' '.($a['pincode'] ?? ''))]);

        return implode(', ', $parts);
    }

    /** [0,1,2,3,4,5] => "Mon – Sat"; non-consecutive days are listed. Day 0 is Monday. */
    public static function workingDays(?array $days): string
    {
        $names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        if (! $days) {
            return '';
        }
        sort($days);
        $consecutive = $days === range($days[0], $days[count($days) - 1]);
        if (count($days) > 2 && $consecutive) {
            return $names[$days[0]].' – '.$names[$days[count($days) - 1]];
        }

        return implode(', ', array_map(fn ($d) => $names[$d] ?? '', $days));
    }

    public static function dayName(int $d): string
    {
        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$d] ?? '';
    }

    public static function tel(?string $phone): string
    {
        return 'tel:'.preg_replace('/[^\d+]/', '', (string) $phone);
    }

    public static function time(?string $t): string
    {
        return $t ? date('g:i A', strtotime($t)) : '';
    }

    public static function fee(mixed $amount): string
    {
        return '₹'.number_format((float) $amount, (float) $amount == floor((float) $amount) ? 0 : 2);
    }

    public static function initials(string $name): string
    {
        $name = trim(preg_replace('/^(dr\.?|prof\.?)\s+/i', '', trim($name)));
        $parts = preg_split('/\s+/', $name) ?: [];

        return strtoupper(mb_substr($parts[0] ?? '?', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    }

    public static function dateLabel(?string $date): string
    {
        return $date ? date('D, j M', strtotime($date)) : '';
    }
}
