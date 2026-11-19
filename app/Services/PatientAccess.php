<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use App\Support\SettingsRegistry;
use App\Support\SetupLink;

/** Getting a patient who booked online their own password, once the hospital has completed their details. */
class PatientAccess
{
    /** Has this patient's login never been used or given a password of its own? */
    public static function passwordPending(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->password_changed_at === null && $user->last_login_at === null;
    }

    /** Text the patient a link to choose a password. Returns false when there is nothing to send (no login, or already set up). */
    public static function sendSetupLink(Patient $patient): bool
    {
        $user = $patient->user_id ? User::find($patient->user_id) : null;
        if (! self::passwordPending($user) || ! $user->mobile) {
            return false;
        }
        SmsService::send($user->mobile, 'account_created', [
            'hospital' => (string) SettingsRegistry::value('hospital.name', config('app.name')), 'mobile' => $user->mobile, 'link' => SetupLink::make($user),
        ], $user->id, 'user', $user->id);

        return true;
    }
}
