<?php

namespace App\Support;

use App\Models\User;

/**
 * A link texted to a person whose account was created for them, letting them choose their own password.
 * The signature covers the current password hash, so the link stops working once it has been used.
 */
class SetupLink
{
    private const VALID_DAYS = 7;

    private static function sign(User $user, int $expires): string
    {
        return hash_hmac('sha256', $user->id.'|'.$expires.'|'.$user->password, (string) config('app.key'));
    }

    public static function make(User $user): string
    {
        $expires = now()->addDays(self::VALID_DAYS)->timestamp;

        return url('/set-password').'?'.http_build_query(['u' => $user->id, 'e' => $expires, 's' => self::sign($user, $expires)]);
    }

    public static function verify(int $userId, int $expires, string $signature): ?User
    {
        $user = User::find($userId);
        if (! $user || ! $user->is_active || $expires < now()->timestamp || ! hash_equals(self::sign($user, $expires), $signature)) {
            return null;
        }

        return $user;
    }
}
