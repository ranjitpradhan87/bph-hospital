<?php

namespace App\Services;

use App\Support\Hospital;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns weekly schedule templates into concrete bookable slots.
 * Idempotent: existing slots (unique per doctor, date and start time) are never duplicated or altered.
 */
final class SlotGenerator
{
    /** @return array{created: int, skipped: int, days_closed: list<string>} */
    public static function generate(int $doctorId, string $from, string $to, bool $publish = true): array
    {
        $today = Hospital::today();
        $from = max($from, $today);
        $created = $skipped = 0;
        $closed = [];

        $schedules = DB::table('doctor_schedules')->where('doctor_id', $doctorId)->where('is_active', true)->get();
        if ($schedules->isEmpty() || $from > $to) {
            return ['created' => 0, 'skipped' => 0, 'days_closed' => []];
        }
        $breaks = DB::table('slot_breaks')->whereIn('schedule_id', $schedules->pluck('id'))->get()->groupBy('schedule_id');
        $holidays = DB::table('holidays')->whereBetween('holiday_date', [$from, $to])->pluck('holiday_date')->all();
        $leaves = DB::table('doctor_leaves')->where('doctor_id', $doctorId)->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get();
        $fee = DB::table('doctors')->where('id', $doctorId)->value('consultation_fee');

        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $date = $d->toDateString();
            $dow = $d->dayOfWeekIso - 1; // 0 = Monday
            if (in_array($date, $holidays, true) || $leaves->first(fn ($l) => $l->start_date <= $date && $l->end_date >= $date)) {
                $closed[] = $date;

                continue;
            }
            foreach ($schedules as $s) {
                if ($s->day_of_week !== $dow || $s->effective_from > $date || ($s->effective_to !== null && $s->effective_to < $date)) {
                    continue;
                }
                foreach (self::times($s, $breaks->get($s->id, collect())) as [$start, $end]) {
                    $inserted = DB::table('appointment_slots')->insertOrIgnore([
                        'doctor_id' => $doctorId, 'department_id' => $s->department_id, 'schedule_id' => $s->id, 'slot_date' => $date,
                        'start_time' => $start, 'end_time' => $end, 'capacity' => $s->capacity_per_slot, 'booked_count' => 0,
                        'consultation_fee' => $s->consultation_fee ?? $fee, 'appointment_type' => $s->appointment_type, 'status' => 'available',
                        'is_published' => $publish, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $inserted ? $created++ : $skipped++;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'days_closed' => $closed];
    }

    /** @return list<array{0: string, 1: string}> start/end pairs (H:i:s) that fit the window and avoid every break */
    public static function times(object $schedule, $breaks): array
    {
        $step = max(5, (int) $schedule->slot_duration_min) * 60;
        $end = self::seconds($schedule->end_time);
        $out = [];
        for ($t = self::seconds($schedule->start_time); $t + $step <= $end; $t += $step) {
            $blocked = false;
            foreach ($breaks as $b) {
                if ($t < self::seconds($b->end_time) && $t + $step > self::seconds($b->start_time)) {
                    $blocked = true;
                    break;
                }
            }
            if (! $blocked) {
                $out[] = [gmdate('H:i:s', $t), gmdate('H:i:s', $t + $step)];
            }
        }

        return $out;
    }

    private static function seconds(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 3600 + $m * 60;
    }
}
