<?php

namespace App\Support;

/** Human-facing record codes derived from primary keys (unique because ids are). */
final class Codes
{
    public static function placeholder(): string
    {
        return 'TMP-'.bin2hex(random_bytes(8));
    }

    public static function patient(int $id): string
    {
        return sprintf('PAT-%06d', $id);
    }

    public static function doctor(int $id): string
    {
        return sprintf('DOC-%04d', $id);
    }

    public static function staff(int $id): string
    {
        return sprintf('STF-%04d', $id);
    }
}
