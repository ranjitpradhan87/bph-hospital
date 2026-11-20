<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Services\GuestRegistration;
use App\Support\Phone;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * A first-time visitor books without signing in. The time is held while they pay the advance, and only when the
 * payment is confirmed is their patient record created (and a text sent). Nothing secret is ever sent in a text.
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
        if (GuestRegistration::mustSignIn($d['mobile'])) {
            return response()->json(['detail' => 'This mobile number already has an account. Please sign in to book.', 'code' => 'account_exists'], 409);
        }
        $key = 'guest-book|'.$d['mobile'];
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['detail' => 'Too many booking attempts for this number. Please try again later or call the hospital.'], 429);
        }
        RateLimiter::hit($key, 3600);

        // While the advance is unpaid the time is only held. The patient is registered when the payment is confirmed.
        $appointmentId = BookingService::bookGuest($d['slot_id'], trim($d['full_name']), $d['mobile'], $d['reason'] ?? null);

        $a = DB::table('appointments')->find($appointmentId);
        $summary = PaymentController::summary($a);
        if ($a->payment_status === 'awaiting_payment') {
            return response()->json($summary + ['payment' => BookingService::startPayment($appointmentId, trim($d['full_name']), $d['mobile'])], 201);
        }

        return response()->json($summary + ['payment' => null], 201);
    }
}
