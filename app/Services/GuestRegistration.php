<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Str;

/**
 * Registers a first-time online patient. This runs only once their advance has been paid (or straight away when
 * there is no advance), never while a booking is merely being held.
 */
final class GuestRegistration
{
    /**
     * Find or create the patient record for a mobile number and name. Call inside a transaction.
     *
     * @return array{0: ?int, 1: int} the account's user id (null when the number belongs to someone already signed up) and the patient id
     */
    public static function register(string $name, string $mobile, ?\DateTimeInterface $consentAt = null, string $via = 'guest_booking'): array
    {
        $name = trim($name);
        $consent = $consentAt ? \Illuminate\Support\Carbon::instance($consentAt) : now();
        $existing = User::where('mobile', $mobile)->first();

        // A number that already has a working account is never written into by a stranger: the paid booking gets its own
        // patient record, and staff link it to the right person at the desk.
        if ($existing && ($existing->password_changed_at !== null || $existing->last_login_at !== null)) {
            $patient = Patient::create(['patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'full_name' => $name, 'mobile' => $mobile, 'consent_at' => $consent]);
            $patient->update(['patient_code' => Patient::codeFor($patient->id)]);
            Audit::record('register', 'patient', $patient->id, ['via' => $via, 'unlinked' => true]);

            return [null, $patient->id];
        }

        $user = $existing ?? User::create(['mobile' => $mobile, 'full_name' => $name, 'password' => Str::random(40), 'last_login_at' => null]);
        $existing || $user->roles()->attach(Role::where('code', 'patient')->value('id'));

        // A walk-in already registered at the desk is linked only when exactly one unlinked record has this mobile and name
        // (families share numbers); otherwise staff sort it out.
        $walkIns = Patient::where('mobile', $mobile)->whereNull('user_id')->whereRaw('lower(full_name) = ?', [mb_strtolower($name)])->get();
        $patient = Patient::where('user_id', $user->id)->first() ?? ($walkIns->count() === 1 ? $walkIns->first() : null);
        if ($patient) {
            $patient->update(['user_id' => $user->id, 'consent_at' => $patient->consent_at ?? $consent]);
        } else {
            $patient = Patient::create(['patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'user_id' => $user->id, 'full_name' => $name, 'mobile' => $mobile, 'consent_at' => $consent]);
            $patient->update(['patient_code' => Patient::codeFor($patient->id)]);
        }
        Audit::record('register', 'patient', $patient->id, ['via' => $via], $user->id);

        return [$user->id, $patient->id];
    }

    /** Is this a number whose owner must sign in rather than book as a guest? */
    public static function mustSignIn(string $mobile): bool
    {
        $u = User::where('mobile', $mobile)->first();

        return $u !== null && ($u->password_changed_at !== null || $u->last_login_at !== null);
    }
}
