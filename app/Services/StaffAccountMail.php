<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use App\Support\SetupLink;
use App\Support\SettingsRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Emails a new staff member their temporary password and a link to choose their own. */
final class StaffAccountMail
{
    /** Returns true when the message was handed to the mail server. A failure never blocks creating the account. */
    public static function welcome(User $user, string $temporaryPassword): bool
    {
        if (! $user->email) {
            return false;
        }
        $hospital = (string) SettingsRegistry::value('hospital.name', config('app.name'));
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
        $login = url('/login');
        $link = SetupLink::make($user);
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#111;max-width:520px">'
            .'<p>Hello '.$e($user->full_name).',</p>'
            .'<p>An account has been created for you at <b>'.$e($hospital).'</b>.</p>'
            .'<p>Sign in at <a href="'.$e($login).'">'.$e($login).'</a><br>Email: <b>'.$e($user->email).'</b><br>Temporary password: <b>'.$e($temporaryPassword).'</b></p>'
            .'<p>Please choose your own password now. The link works for 7 days and only once:</p>'
            .'<p><a href="'.$e($link).'" style="display:inline-block;background:#0b6bcb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Set my password</a></p>'
            .'<p style="font-size:13px;color:#555">If the button does not open, copy this address into your browser:<br>'.$e($link).'</p>'
            .'<p style="font-size:13px;color:#555">If you were not expecting this, ignore this email or contact the hospital.</p></div>';

        try {
            Mail::html($html, fn ($m) => $m->to($user->email, $user->full_name)->subject("Your {$hospital} staff account"));
            Audit::record('account_email', 'user', $user->id);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Staff account email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
