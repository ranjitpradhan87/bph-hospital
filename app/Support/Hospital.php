<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Hospital-local time helpers. Slots are stored as local DATE + TIME; timestamps are UTC. */
final class Hospital
{
    public static function tz(): string
    {
        return config('app.hospital_timezone', 'Asia/Kolkata');
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::tz());
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** Local date + time-of-day to a UTC timestamp for storage. */
    public static function toUtc(string $date, ?string $time): ?Carbon
    {
        return $time === null ? null : Carbon::parse("$date $time", self::tz())->utc();
    }

    public static function localTime(?string $utcTimestamp): ?string
    {
        return $utcTimestamp === null ? null : Carbon::parse($utcTimestamp, 'UTC')->setTimezone(self::tz())->format('H:i:s');
    }

    /** Slots a patient could book right now (published, open, not full, in the lead/advance window, no holiday/leave, bookable doctor). */
    public static function bookableSlots(?string $from = null, ?string $to = null)
    {
        $now = self::now();
        $earliest = $now->copy()->addMinutes((int) SettingsRegistry::value('booking.min_lead_minutes', 30));
        $windowEnd = $now->copy()->addDays((int) SettingsRegistry::value('booking.advance_booking_days', 30))->toDateString();
        $start = max($from ?? $earliest->toDateString(), $earliest->toDateString());
        $end = min($to ?? $windowEnd, $windowEnd);

        return DB::table('appointment_slots as s')
            ->join('doctors as d', 'd.id', '=', 's.doctor_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('s.is_published', true)->where('s.status', 'available')
            ->whereColumn('s.booked_count', '<', 's.capacity')
            ->where('s.slot_date', '>=', $start)->where('s.slot_date', '<=', $end)
            ->where(fn ($q) => $q->where('s.slot_date', '>', $earliest->toDateString())->orWhere('s.start_time', '>=', $earliest->format('H:i:s')))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('doctor_leaves as l')->whereColumn('l.doctor_id', 's.doctor_id')->whereColumn('l.start_date', '<=', 's.slot_date')->whereColumn('l.end_date', '>=', 's.slot_date'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('holidays as h')->whereColumn('h.holiday_date', 's.slot_date'))
            ->where('d.status', 'active')->whereNull('d.deleted_at')
            ->where('u.is_active', true)->whereNull('u.deleted_at');
    }
}
