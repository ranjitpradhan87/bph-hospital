<?php

namespace App\Services;

use App\Support\Audit;
use App\Support\Hospital;
use App\Support\SettingsRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Appointment lifecycle. Every change to a slot's capacity happens inside a transaction that holds a
 * row lock on the slot (SELECT … FOR UPDATE), so two people can never take the last seat.
 */
final class BookingService
{
    /** How a booking is paid: at the counter (staff bookings), or an advance paid online (patients booking online). */
    public const PAYMENT_MODES = ['pay_at_hospital', 'advance_online'];

    public const ACTIVE = ['pending', 'confirmed', 'checked_in', 'in_consultation'];

    /** Allowed status moves made by staff; cancelling goes through cancel(). */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'no_show'],
        'confirmed' => ['checked_in', 'no_show'],
        'checked_in' => ['in_consultation', 'no_show'],
        'in_consultation' => ['completed'],
    ];

    public static function slotStart(object $slot): Carbon
    {
        return Carbon::parse($slot->slot_date.' '.$slot->start_time, Hospital::tz());
    }

    /** Minutes from now until the appointment starts (negative once it has started). */
    public static function minutesUntil(string $date, string $time): int
    {
        return (int) Hospital::now()->diffInMinutes(Carbon::parse("$date $time", Hospital::tz()), false);
    }

    private static function history(int $appointmentId, ?string $from, string $to, ?int $userId, ?string $note = null): void
    {
        DB::table('appointment_status_history')->insert([
            'appointment_id' => $appointmentId, 'from_status' => $from, 'to_status' => $to, 'changed_by_user_id' => $userId,
            'note' => $note, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Queue a notification in the outbox (sent by the scheduler in the notifications phase). */
    public static function notify(int $patientId, string $event, string $subject, string $body, int $appointmentId): void
    {
        if (! SettingsRegistry::value('notifications.email_enabled', true)) {
            return;
        }
        $p = DB::table('patients')->where('id', $patientId)->first(['user_id', 'email', 'full_name']);
        if (! $p || ! $p->email) {
            return;
        }
        DB::table('hms_notifications')->insert([
            'user_id' => $p->user_id, 'recipient' => $p->email, 'channel' => 'email', 'event' => $event, 'subject' => $subject,
            'body' => "Dear {$p->full_name},\n\n$body", 'status' => 'queued', 'related_type' => 'appointment', 'related_id' => $appointmentId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private static function describe(object $appt): string
    {
        $doctor = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.id', $appt->doctor_id)->value('u.full_name');

        return "{$doctor} on ".date('D, j M Y', strtotime($appt->appointment_date)).' at '.date('g:i A', strtotime($appt->start_time));
    }

    /**
     * Create a booking inside the caller's transaction. The slot row must already be locked.
     *
     * @param  array{source: string, online: bool, reason: ?string, actor: int, rescheduled_from?: int}  $o
     */
    private static function place(object $slot, int $patientId, array $o): int
    {
        if (! $slot->is_published || $slot->status !== 'available') {
            abort(409, 'This slot is not open for booking');
        }
        if ($slot->booked_count >= $slot->capacity) {
            abort(409, 'This slot has just been taken. Please choose another time.');
        }
        $start = self::slotStart($slot);
        $minutes = (int) Hospital::now()->diffInMinutes($start, false);
        if ($o['online']) {
            $lead = (int) SettingsRegistry::value('booking.min_lead_minutes', 30);
            $window = (int) SettingsRegistry::value('booking.advance_booking_days', 30);
            if ($minutes < $lead) {
                abort(409, 'This slot is too close to start online. Please call the hospital.');
            }
            if ($slot->slot_date > Hospital::now()->addDays($window)->toDateString()) {
                abort(409, "Appointments can be booked up to $window days ahead");
            }
        } elseif (Carbon::parse($slot->slot_date.' '.$slot->end_time, Hospital::tz())->isPast()) {
            abort(409, 'This slot is in the past');
        }

        $doctor = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.id', $slot->doctor_id)
            ->where('d.status', 'active')->whereNull('d.deleted_at')->where('u.is_active', true)->whereNull('u.deleted_at')->first(['d.id']);
        if (! $doctor) {
            abort(409, 'This doctor is not taking bookings right now');
        }
        if (DB::table('holidays')->where('holiday_date', $slot->slot_date)->exists()
            || DB::table('doctor_leaves')->where('doctor_id', $slot->doctor_id)->where('start_date', '<=', $slot->slot_date)->where('end_date', '>=', $slot->slot_date)->exists()) {
            abort(409, 'The doctor is not available on this date');
        }
        if (! DB::table('patients')->where('id', $patientId)->whereNull('deleted_at')->exists()) {
            abort(404, 'Patient not found');
        }
        $clash = DB::table('appointments')->where('patient_id', $patientId)->where('appointment_date', $slot->slot_date)->whereIn('status', self::ACTIVE)
            ->where('start_time', '<', $slot->end_time)->where('end_time', '>', $slot->start_time)->exists();
        if ($clash) {
            abort(409, 'This patient already has an appointment at that time');
        }

        $hold = $o['hold'] ?? null; // ['advance' => float, 'expires' => Carbon] when an online advance must be paid first
        $status = ($hold || ($o['online'] && ! SettingsRegistry::value('booking.auto_confirm', true))) ? 'pending' : 'confirmed';
        try {
            $id = DB::table('appointments')->insertGetId([
                'appointment_number' => 'TMP-'.bin2hex(random_bytes(8)), 'patient_id' => $patientId, 'doctor_id' => $slot->doctor_id,
                'department_id' => $slot->department_id, 'slot_id' => $slot->id, 'active_slot_key' => $slot->id,
                'appointment_date' => $slot->slot_date, 'start_time' => $slot->start_time, 'end_time' => $slot->end_time,
                'appointment_type' => $slot->appointment_type, 'reason' => $o['reason'], 'status' => $status, 'payment_status' => $hold ? 'awaiting_payment' : 'unpaid', 'payment_mode' => $hold ? 'advance_online' : 'pay_at_hospital',
                'advance_amount' => $hold['advance'] ?? 0, 'payment_expires_at' => $hold['expires'] ?? null,
                'consultation_fee' => $slot->consultation_fee, 'booking_source' => $o['source'], 'booked_by_user_id' => $o['actor'],
                'rescheduled_from_id' => $o['rescheduled_from'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This patient has already booked this slot');
        }
        DB::table('appointments')->where('id', $id)->update(['appointment_number' => sprintf('APT-%s-%05d', date('ymd'), $id)]);
        DB::table('appointment_slots')->where('id', $slot->id)->increment('booked_count');
        self::history($id, null, $status, $o['actor'], $o['rescheduled_from'] ?? null ? 'Rescheduled' : ($hold ? 'Held until the advance is paid' : 'Booked ('.$o['source'].')'));

        return $id;
    }

    /** @param bool $text send the confirmation text now (a caller that sends several texts passes false and calls textConfirmation itself) */
    public static function book(int $slotId, int $patientId, int $actorId, string $source, ?string $reason, string $paymentMode = 'pay_at_hospital', bool $text = true): int
    {
        self::releaseExpiredHolds();
        $id = DB::transaction(function () use ($slotId, $patientId, $actorId, $source, $reason, $paymentMode) {
            $slot = DB::table('appointment_slots')->where('id', $slotId)->lockForUpdate()->first() ?? abort(404, 'Slot not found');
            // Patients booking online pay an advance first; the time is held for them meanwhile.
            $hold = null;
            if ($source === 'online' && SettingsRegistry::value('booking.advance_required', true)) {
                $advance = self::advanceFor((float) $slot->consultation_fee, (int) $slot->doctor_id);
                if ($advance > 0) {
                    $paymentMode === 'advance_online' || abort(422, 'An advance payment is needed to book online');
                    $hold = ['advance' => $advance, 'expires' => now()->addMinutes((int) SettingsRegistry::value('booking.payment_hold_minutes', 15))];
                }
            }
            $id = self::place($slot, $patientId, ['source' => $source, 'online' => $source === 'online', 'reason' => $reason, 'actor' => $actorId, 'payment_mode' => $paymentMode, 'hold' => $hold]);
            $a = DB::table('appointments')->find($id);
            $hold || self::notify($patientId, 'appointment_booked', 'Your appointment is booked', 'Your appointment ('.$a->appointment_number.') with '.self::describe($a).' is '.$a->status.'.', $id);
            Audit::record('book', 'appointment', $id, ['slot_id' => $slotId, 'source' => $source], $actorId);

            return $id;
        });
        // After the transaction: a slow or failing SMS provider must never hold the slot lock or undo the booking.
        $text && ! DB::table('appointments')->where('id', $id)->where('payment_status', 'awaiting_payment')->exists() && self::textConfirmation($id);

        return $id;
    }

    /** The advance: the doctor's own amount if they set one, else the hospital default; never more than the fee itself. */
    public static function advanceFor(float $fee, ?int $doctorId = null): float
    {
        $own = $doctorId ? DB::table('doctors')->where('id', $doctorId)->value('advance_amount') : null;

        return min($fee, (float) ($own ?? SettingsRegistry::value('booking.advance_amount', 300)));
    }

    /**
     * Start the online payment for a held booking. If the gateway cannot be reached the hold is released,
     * so the patient is never left with a held time they cannot pay for.
     *
     * @return array<string, mixed> what the browser needs to open the payment window
     */
    public static function startPayment(int $appointmentId, ?string $patientName = null, ?string $patientMobile = null): array
    {
        $a = DB::table('appointments')->find($appointmentId);
        try {
            $order = PaymentGateway::createOrder((float) $a->advance_amount, $a->appointment_number);
        } catch (\Throwable $e) {
            report($e);
            self::expireHold($a, 'Payment could not be started');
            abort(502, 'Online payment is not available right now. Please try again shortly or call the hospital.');
        }
        DB::table('appointments')->where('id', $appointmentId)->update(['payment_order_id' => $order['order_id'], 'updated_at' => now()]);

        return $order + ['appointment_number' => $a->appointment_number, 'expires_at' => Carbon::parse($a->payment_expires_at, 'UTC')->toIso8601String(),
            'name' => (string) SettingsRegistry::value('hospital.name', config('app.name')), 'prefill' => ['name' => $patientName, 'contact' => $patientMobile],
            'balance' => max(0, (float) $a->consultation_fee - (float) $a->advance_amount)];
    }

    /** Reopen the payment for a booking still being held (the patient closed the payment window). */
    public static function resumePayment(int $appointmentId): array
    {
        self::releaseExpiredHolds();
        $a = DB::table('appointments')->find($appointmentId);
        if (! $a || $a->payment_status !== 'awaiting_payment' || $a->status !== 'pending') {
            abort(409, 'This booking is not waiting for payment any more');
        }
        $p = DB::table('patients')->find($a->patient_id);
        if (! $a->payment_order_id) {
            return self::startPayment($appointmentId, $p->full_name, $p->mobile);
        }

        return PaymentGateway::existingOrder($a->payment_order_id, (float) $a->advance_amount) + ['appointment_number' => $a->appointment_number,
            'expires_at' => Carbon::parse($a->payment_expires_at, 'UTC')->toIso8601String(), 'name' => (string) SettingsRegistry::value('hospital.name', config('app.name')),
            'prefill' => ['name' => $p->full_name, 'contact' => $p->mobile], 'balance' => max(0, (float) $a->consultation_fee - (float) $a->advance_amount)];
    }

    /** Give back the time held for a booking whose advance was not paid. */
    private static function expireHold(object $a, string $reason): void
    {
        DB::transaction(function () use ($a, $reason) {
            $moved = DB::table('appointments')->where('id', $a->id)->where('payment_status', 'awaiting_payment')->where('status', 'pending')
                ->update(['status' => 'cancelled', 'payment_status' => 'unpaid', 'active_slot_key' => null, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_at' => now()]);
            if ($moved) {
                DB::table('appointment_slots')->where('id', $a->slot_id)->where('booked_count', '>', 0)->decrement('booked_count');
                self::history($a->id, 'pending', 'cancelled', null, $reason);
            }
        });
    }

    /** Release every held booking whose payment window has passed. Cheap enough to run on each booking. */
    public static function releaseExpiredHolds(): int
    {
        $due = DB::table('appointments')->where('payment_status', 'awaiting_payment')->where('status', 'pending')->where('payment_expires_at', '<', now())->get();
        foreach ($due as $a) {
            self::expireHold($a, 'Advance payment not completed in time');
        }

        return $due->count();
    }

    /**
     * The advance has been paid: confirm the booking and tell the patient. Safe to call twice (browser and webhook).
     *
     * @return object|null the appointment, or null when the order is unknown
     */
    public static function confirmPayment(string $orderId, string $paymentId): ?object
    {
        $a = DB::table('appointments')->where('payment_order_id', $orderId)->first();
        if (! $a) {
            return null;
        }
        if ($a->payment_reference !== null) {
            return $a; // already confirmed
        }
        $confirmed = DB::transaction(function () use ($a, $paymentId) {
            $slot = DB::table('appointment_slots')->where('id', $a->slot_id)->lockForUpdate()->first();
            $update = ['payment_status' => 'advance_paid', 'payment_reference' => $paymentId, 'paid_at' => now(), 'updated_at' => now()];
            if ($a->status === 'pending') {
                DB::table('appointments')->where('id', $a->id)->update($update + ['status' => 'confirmed']);
                self::history($a->id, 'pending', 'confirmed', null, 'Advance paid online');

                return true;
            }
            // Paid after the hold lapsed: take the time back if it is still free, otherwise flag it for a refund.
            $free = $slot && $slot->status === 'available' && $slot->booked_count < $slot->capacity
                && ! DB::table('appointments')->where('patient_id', $a->patient_id)->where('appointment_date', $a->appointment_date)->whereIn('status', self::ACTIVE)->where('start_time', '<', $a->end_time)->where('end_time', '>', $a->start_time)->exists();
            if ($free) {
                DB::table('appointments')->where('id', $a->id)->update($update + ['status' => 'confirmed', 'active_slot_key' => $a->slot_id, 'cancelled_at' => null, 'cancel_reason' => null]);
                DB::table('appointment_slots')->where('id', $a->slot_id)->increment('booked_count');
                self::history($a->id, $a->status, 'confirmed', null, 'Advance paid online after the hold had lapsed');

                return true;
            }
            DB::table('appointments')->where('id', $a->id)->update(['payment_status' => 'refund_due', 'payment_reference' => $paymentId, 'paid_at' => now(), 'updated_at' => now()]);
            Audit::record('payment_refund_due', 'appointment', $a->id, ['payment_id' => $paymentId]);

            return false;
        });
        $fresh = DB::table('appointments')->find($a->id);
        if ($confirmed) {
            Audit::record('advance_paid', 'appointment', $a->id, ['payment_id' => $paymentId]);
            self::textBooked($a->id);
        }

        return $fresh;
    }

    /** The confirmation text. The password link follows later, once the hospital has completed the patient's details. */
    public static function textBooked(int $appointmentId): void
    {
        self::textConfirmation($appointmentId);
    }

    /** Text the patient that their booking is confirmed, with how they will pay. */
    public static function textConfirmation(int $appointmentId): void
    {
        $a = DB::table('appointments as a')->join('patients as p', 'p.id', '=', 'a.patient_id')->where('a.id', $appointmentId)
            ->first(['a.*', 'p.mobile', 'p.user_id']);
        if (! $a || ! $a->mobile) {
            return;
        }
        $doctor = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.id', $a->doctor_id)->value('u.full_name');
        $rs = fn ($n) => 'Rs.'.rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
        $payment = $a->payment_mode === 'advance_online'
            ? 'Advance '.$rs($a->advance_amount).' paid. Balance '.$rs(max(0, $a->consultation_fee - $a->advance_amount)).' payable at the hospital.'
            : 'Please pay '.$rs($a->consultation_fee).' at the hospital counter.';
        SmsService::send($a->mobile, 'booking_confirmed', [
            'hospital' => (string) SettingsRegistry::value('hospital.name', config('app.name')), 'number' => $a->appointment_number, 'doctor' => (string) $doctor,
            'when' => date('D, j M', strtotime($a->appointment_date)).' at '.date('g:i A', strtotime($a->start_time)), 'payment' => $a->status === 'pending' ? 'It will be confirmed shortly. '.$payment : $payment,
        ], $a->user_id, 'appointment', $appointmentId);
    }

    /** @param bool $enforcePolicy apply the patient-facing cutoff rules (staff are exempt) */
    public static function cancel(int $appointmentId, int $actorId, ?string $reason, bool $enforcePolicy): void
    {
        DB::transaction(function () use ($appointmentId, $actorId, $reason, $enforcePolicy) {
            $a = DB::table('appointments')->where('id', $appointmentId)->lockForUpdate()->first() ?? abort(404, 'Appointment not found');
            DB::table('appointment_slots')->where('id', $a->slot_id)->lockForUpdate()->first();
            if (! in_array($a->status, ['pending', 'confirmed'], true)) {
                abort(409, in_array($a->status, self::ACTIVE, true) ? 'This visit has already started and cannot be cancelled' : 'This appointment is already '.str_replace('_', ' ', $a->status));
            }
            if ($enforcePolicy) {
                $cutoff = (int) SettingsRegistry::value('booking.cancellation_cutoff_hours', 4);
                if (self::minutesUntil($a->appointment_date, $a->start_time) < $cutoff * 60) {
                    abort(409, "Appointments can be cancelled online up to $cutoff hour(s) before the start. Please call the hospital.");
                }
            }
            self::release($a, 'cancelled', $reason, $actorId);
            self::notify($a->patient_id, 'appointment_cancelled', 'Your appointment is cancelled', 'Your appointment ('.$a->appointment_number.') with '.self::describe($a).' has been cancelled.', $a->id);
            Audit::record('cancel', 'appointment', $a->id, [], $actorId);
        });
    }

    /** Frees the seat and marks the booking cancelled. Caller holds the locks. */
    private static function release(object $a, string $status, ?string $reason, int $actorId): void
    {
        DB::table('appointments')->where('id', $a->id)->update([
            'status' => $status, 'active_slot_key' => null, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_at' => now(),
        ]);
        DB::table('appointment_slots')->where('id', $a->slot_id)->where('booked_count', '>', 0)->decrement('booked_count');
        self::history($a->id, $a->status, $status, $actorId, $reason);
    }

    public static function reschedule(int $appointmentId, int $newSlotId, int $actorId, ?string $reason, bool $enforcePolicy): int
    {
        return DB::transaction(function () use ($appointmentId, $newSlotId, $actorId, $reason, $enforcePolicy) {
            $a = DB::table('appointments')->where('id', $appointmentId)->lockForUpdate()->first() ?? abort(404, 'Appointment not found');
            $a->payment_status === 'awaiting_payment' && abort(409, 'Pay the advance first, or cancel this booking and book another time');
            // Lock both slots in id order so two concurrent reschedules cannot deadlock.
            $locked = [];
            foreach (collect([$a->slot_id, $newSlotId])->unique()->sort() as $sid) {
                $locked[$sid] = DB::table('appointment_slots')->where('id', $sid)->lockForUpdate()->first();
            }
            $new = $locked[$newSlotId] ?? abort(404, 'Slot not found');
            if (! in_array($a->status, ['pending', 'confirmed'], true)) {
                abort(409, 'Only upcoming bookings can be rescheduled');
            }
            if ($new->id === $a->slot_id) {
                abort(409, 'Choose a different time');
            }
            if ($enforcePolicy) {
                $cutoff = (int) SettingsRegistry::value('booking.reschedule_cutoff_hours', 4);
                if (self::minutesUntil($a->appointment_date, $a->start_time) < $cutoff * 60) {
                    abort(409, "Appointments can be rescheduled online up to $cutoff hour(s) before the start. Please call the hospital.");
                }
                $max = (int) SettingsRegistry::value('booking.max_reschedules', 2);
                if (self::rescheduleCount($a) >= $max) {
                    abort(409, "This appointment has already been rescheduled $max time(s). Please call the hospital.");
                }
            }
            // Free the old seat first so a patient can move to an adjacent time without a self-clash.
            self::release($a, 'cancelled', 'Rescheduled', $actorId);
            $id = self::place($new, $a->patient_id, ['source' => $a->booking_source, 'online' => $enforcePolicy, 'reason' => $reason ?? $a->reason, 'actor' => $actorId, 'rescheduled_from' => $a->id]);
            $n = DB::table('appointments')->find($id);
            self::notify($a->patient_id, 'appointment_rescheduled', 'Your appointment is rescheduled', 'Your appointment is now '.self::describe($n).' ('.$n->appointment_number.').', $id);
            Audit::record('reschedule', 'appointment', $id, ['from' => $a->id], $actorId);

            return $id;
        });
    }

    /** How many times this booking has already been moved (length of the rescheduled_from chain). */
    public static function rescheduleCount(object $a): int
    {
        $n = 0;
        $cur = $a;
        while ($cur->rescheduled_from_id && $n < 20) {
            $n++;
            $cur = DB::table('appointments')->find($cur->rescheduled_from_id);
            if (! $cur) {
                break;
            }
        }

        return $n;
    }

    public static function transition(int $appointmentId, string $to, int $actorId, ?string $note): void
    {
        DB::transaction(function () use ($appointmentId, $to, $actorId, $note) {
            $a = DB::table('appointments')->where('id', $appointmentId)->lockForUpdate()->first() ?? abort(404, 'Appointment not found');
            if (! in_array($to, self::TRANSITIONS[$a->status] ?? [], true)) {
                abort(409, "A {$a->status} appointment cannot be marked ".str_replace('_', ' ', $to));
            }
            // A booking is confirmed by the advance payment, never by hand; staff may only release it.
            if ($a->payment_status === 'awaiting_payment' && $to !== 'cancelled') {
                abort(409, 'This booking is waiting for the advance payment. It is confirmed automatically once paid.');
            }
            $set = ['status' => $to, 'updated_at' => now()];
            if ($to === 'checked_in') {
                $set['checked_in_at'] = now();
                $set['queue_number'] = 1 + (int) DB::table('appointments')->where('doctor_id', $a->doctor_id)->where('appointment_date', $a->appointment_date)->max('queue_number');
            }
            if ($to === 'completed') {
                $set['completed_at'] = now();
            }
            if ($to === 'no_show') {
                if (self::minutesUntil($a->appointment_date, $a->start_time) > 0) {
                    abort(409, 'A visit can only be marked no-show once its time has started');
                }
                $set['active_slot_key'] = null; // the seat is not held for someone who did not come
            }
            DB::table('appointments')->where('id', $a->id)->update($set);
            self::history($a->id, $a->status, $to, $actorId, $note);
            Audit::record('status', 'appointment', $a->id, ['from' => $a->status, 'to' => $to], $actorId);
        });
    }
}
