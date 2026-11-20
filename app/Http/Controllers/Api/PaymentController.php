<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Services\PaymentGateway;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The booking advance: what it costs, confirming a payment, and the gateway's webhook. */
class PaymentController extends Controller
{
    public function options(): JsonResponse
    {
        $required = (bool) SettingsRegistry::value('booking.advance_required', true);

        return response()->json([
            'advance_required' => $required, 'advance_amount' => (int) SettingsRegistry::value('booking.advance_amount', 300),
            'hold_minutes' => (int) SettingsRegistry::value('booking.payment_hold_minutes', 15), 'driver' => PaymentGateway::driver(),
        ]);
    }

    /** The browser reports a finished payment; the signature proves it is real. */
    public function verify(Request $request): JsonResponse
    {
        $d = $request->validate(['order_id' => ['required', 'string', 'max:60'], 'payment_id' => ['required', 'string', 'max:60'], 'signature' => ['required', 'string', 'max:200']]);
        if (! PaymentGateway::verifyPayment($d['order_id'], $d['payment_id'], $d['signature'])) {
            return response()->json(['detail' => 'The payment could not be verified. If money was taken, it will be returned - please call the hospital.'], 422);
        }
        $a = BookingService::confirmPayment($d['order_id'], $d['payment_id']) ?? abort(404, 'Booking not found');
        if ($a->payment_status === 'refund_due') {
            return response()->json(['detail' => 'Your payment was received, but the time you chose was taken meanwhile. It will be refunded - please book another time.'], 409);
        }

        return response()->json(self::summary($a));
    }

    /** Razorpay calls this too, so a payment is confirmed even if the customer closes the browser early. */
    public function webhook(Request $request): JsonResponse
    {
        if (! PaymentGateway::verifyWebhook($request->getContent(), (string) $request->header('X-Razorpay-Signature'))) {
            return response()->json(['detail' => 'Bad signature'], 400);
        }
        $event = $request->json('event');
        if (in_array($event, ['payment.captured', 'order.paid'], true)) {
            $payment = $request->json('payload.payment.entity') ?? [];
            if (! empty($payment['order_id']) && ! empty($payment['id'])) {
                BookingService::confirmPayment($payment['order_id'], $payment['id']);
            }
        }

        return response()->json(['ok' => true]);
    }

    public static function summary(object $a): array
    {
        $doctor = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.id', $a->doctor_id)->value('u.full_name');
        $u = $a->patient_id ? DB::table('patients as p')->leftJoin('users as u', 'u.id', '=', 'p.user_id')->where('p.id', $a->patient_id)->first(['p.mobile', 'u.password_changed_at', 'u.last_login_at', 'u.id as user_id']) : null;

        return [
            'appointment_number' => $a->appointment_number, 'doctor' => $doctor, 'appointment_date' => $a->appointment_date, 'start_time' => $a->start_time, 'status' => $a->status,
            'payment_status' => $a->payment_status, 'payment_mode' => $a->payment_mode, 'consultation_fee' => (float) $a->consultation_fee, 'advance_amount' => (float) $a->advance_amount,
            'mobile' => $u->mobile ?? $a->guest_mobile, 'account_created' => $u !== null && $u->user_id !== null && $u->password_changed_at === null && $u->last_login_at === null,
        ];
    }
}
