<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingService;
use App\Support\Audit;
use App\Support\Phone;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A first-time visitor books without signing in: the mobile number creates their patient account, and a text
 * message lets them set their own password. Nothing secret is ever sent in the text itself.
 */
class GuestBookingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (SettingsRegistry::value('registration.patient_self_signup', true) === false) {
            return response()->json(['detail' => 'Online booking is currently disabled. Please call the hospital.'], 403);
        }
        $request->merge(['mobile' => Phone::normalize((string) $request->input('mobile', ''))]);
        $d = $request->validate([
            'slot_id' => ['required', 'integer'],
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'reason' => ['nullable', 'string', 'max:500'],
            'payment_mode' => ['nullable', Rule::in(BookingService::PAYMENT_MODES)],
            'consent' => ['accepted'],
        ], ['mobile.regex' => 'Enter a valid 10-digit Indian mobile number', 'consent.accepted' => 'Please agree to the use of your details to continue']);

        // A number with an account that is in use signs in instead, so nobody can book into someone else's account.
        // An account made by an earlier booking whose password was never set (e.g. the payment was abandoned) is carried on.
        $existing = User::where('mobile', $d['mobile'])->first();
        if ($existing && ($existing->password_changed_at !== null || $existing->last_login_at !== null)) {
            return response()->json(['detail' => 'This mobile number already has an account. Please sign in to book.', 'code' => 'account_exists'], 409);
        }
        $key = 'guest-book|'.$d['mobile'];
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['detail' => 'Too many booking attempts for this number. Please try again later or call the hospital.'], 429);
        }
        RateLimiter::hit($key, 3600);

        // One transaction: if the slot has just been taken, the account is not created either.
        [$user, $appointmentId] = DB::transaction(function () use ($d, $existing) {
            $user = $existing ?? User::create(['mobile' => $d['mobile'], 'full_name' => trim($d['full_name']), 'password' => Str::random(40), 'last_login_at' => null]);
            $existing || $user->roles()->attach(Role::where('code', 'patient')->value('id'));
            // The earlier booking's patient record is reused. A walk-in already registered at the desk is linked only when
            // exactly one unlinked record has this mobile and name (families share numbers), otherwise staff sort it out.
            $walkIns = Patient::where('mobile', $d['mobile'])->whereNull('user_id')->whereRaw('lower(full_name) = ?', [mb_strtolower(trim($d['full_name']))])->get();
            $patient = Patient::where('user_id', $user->id)->first() ?? ($walkIns->count() === 1 ? $walkIns->first() : null);
            if ($patient) {
                $patient->update(['user_id' => $user->id, 'consent_at' => $patient->consent_at ?? now()]);
            } else {
                $patient = Patient::create(['patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'user_id' => $user->id, 'full_name' => trim($d['full_name']), 'mobile' => $d['mobile'], 'consent_at' => now()]);
                $patient->update(['patient_code' => Patient::codeFor($patient->id)]);
            }
            Audit::record('register', 'patient', $patient->id, ['via' => 'guest_booking'], $user->id);
            $id = BookingService::book($d['slot_id'], $patient->id, $user->id, 'online', $d['reason'] ?? null, $d['payment_mode'] ?? 'advance_online', false);

            return [$user, $id];
        });

        $a = DB::table('appointments')->find($appointmentId);
        $summary = PaymentController::summary($a);
        if ($a->payment_status === 'awaiting_payment') {
            // The time is held while the advance is paid; the texts go out once it is.
            return response()->json($summary + ['payment' => BookingService::startPayment($appointmentId, trim($d['full_name']), $d['mobile'])], 201);
        }
        // No advance to pay: confirmed at once, so text now.
        BookingService::textBooked($appointmentId);

        return response()->json($summary + ['payment' => null], 201);
    }
}
