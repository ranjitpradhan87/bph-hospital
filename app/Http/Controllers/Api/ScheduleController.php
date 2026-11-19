<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Services\SlotGenerator;
use App\Support\Audit;
use App\Support\Hospital;
use App\Support\Paging;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Weekly schedules, leave, and the concrete slots generated from them. */
class ScheduleController extends Controller
{
    public const APPOINTMENT_TYPES = ['in_person', 'online'];

    private static function hm(string $t): string
    {
        return substr($t, 0, 5);
    }

    // ---------- schedules ----------

    private static function scheduleOut(object $s, $breaks): array
    {
        return [
            'id' => $s->id, 'doctor_id' => $s->doctor_id, 'department_id' => $s->department_id, 'day_of_week' => (int) $s->day_of_week,
            'start_time' => self::hm($s->start_time), 'end_time' => self::hm($s->end_time), 'slot_duration_min' => (int) $s->slot_duration_min,
            'capacity_per_slot' => (int) $s->capacity_per_slot, 'consultation_fee' => $s->consultation_fee === null ? null : DoctorController::money($s->consultation_fee),
            'appointment_type' => $s->appointment_type, 'effective_from' => $s->effective_from, 'effective_to' => $s->effective_to, 'is_active' => (bool) $s->is_active,
            'breaks' => $breaks->map(fn ($b) => ['id' => $b->id, 'start_time' => self::hm($b->start_time), 'end_time' => self::hm($b->end_time), 'reason' => $b->reason])->values(),
        ];
    }

    public function schedules(Doctor $doctor): JsonResponse
    {
        $rows = DB::table('doctor_schedules')->where('doctor_id', $doctor->id)->orderBy('day_of_week')->orderBy('start_time')->get();
        $breaks = DB::table('slot_breaks')->whereIn('schedule_id', $rows->pluck('id'))->orderBy('start_time')->get()->groupBy('schedule_id');

        return response()->json($rows->map(fn ($s) => self::scheduleOut($s, $breaks->get($s->id, collect())))->values());
    }

    private static function validateSchedule(Request $request, Doctor $doctor, ?int $exceptId = null): array
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
            'slot_duration_min' => ['nullable', 'integer', 'min:5', 'max:240'],
            'capacity_per_slot' => ['nullable', 'integer', 'min:1', 'max:50'],
            'consultation_fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'appointment_type' => ['nullable', Rule::in(self::APPOINTMENT_TYPES)],
            'effective_from' => ['nullable', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['nullable', 'boolean'],
            'breaks' => ['nullable', 'array', 'max:6'],
            'breaks.*.start_time' => ['required', 'date_format:H:i'], 'breaks.*.end_time' => ['required', 'date_format:H:i'], 'breaks.*.reason' => ['nullable', 'string', 'max:120'],
        ]);
        if ($data['end_time'] <= $data['start_time']) {
            abort(422, 'End time must be after the start time');
        }
        $duration = $data['slot_duration_min'] ?? (int) SettingsRegistry::value('booking.default_slot_duration_min', 15);
        if ((strtotime($data['end_time']) - strtotime($data['start_time'])) / 60 < $duration) {
            abort(422, 'The session is shorter than one slot');
        }
        $from = $data['effective_from'] ?? Hospital::today();
        $to = $data['effective_to'] ?? null;
        if ($to !== null && $to < $from) {
            abort(422, 'The end date must not be before the start date');
        }
        if (! DB::table('doctor_departments')->where('doctor_id', $doctor->id)->where('department_id', $data['department_id'])->exists()) {
            abort(400, 'The doctor does not work in that department');
        }
        foreach ($data['breaks'] ?? [] as $b) {
            if ($b['end_time'] <= $b['start_time'] || $b['start_time'] < $data['start_time'] || $b['end_time'] > $data['end_time']) {
                abort(422, 'Breaks must fall inside the session and end after they start');
            }
        }
        $overlap = DB::table('doctor_schedules')->where('doctor_id', $doctor->id)->where('day_of_week', $data['day_of_week'])->where('is_active', true)
            ->where('start_time', '<', $data['end_time'].':00')->where('end_time', '>', $data['start_time'].':00')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->when($to, fn ($q) => $q->where('effective_from', '<=', $to))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if (($data['is_active'] ?? true) && $overlap) {
            abort(409, 'This overlaps another schedule for the same day');
        }

        return $data + ['_from' => $from, '_to' => $to, '_duration' => $duration];
    }

    private static function saveBreaks(int $scheduleId, array $breaks): void
    {
        DB::table('slot_breaks')->where('schedule_id', $scheduleId)->delete();
        foreach ($breaks as $b) {
            DB::table('slot_breaks')->insert(['schedule_id' => $scheduleId, 'start_time' => $b['start_time'], 'end_time' => $b['end_time'], 'reason' => $b['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private static function scheduleFields(array $d): array
    {
        return [
            'department_id' => $d['department_id'], 'day_of_week' => $d['day_of_week'], 'start_time' => $d['start_time'], 'end_time' => $d['end_time'],
            'slot_duration_min' => $d['_duration'], 'capacity_per_slot' => $d['capacity_per_slot'] ?? 1, 'consultation_fee' => $d['consultation_fee'] ?? null,
            'appointment_type' => $d['appointment_type'] ?? 'in_person', 'effective_from' => $d['_from'], 'effective_to' => $d['_to'], 'is_active' => $d['is_active'] ?? true,
        ];
    }

    private function reload(int $id): JsonResponse
    {
        $s = DB::table('doctor_schedules')->find($id);

        return response()->json(self::scheduleOut($s, DB::table('slot_breaks')->where('schedule_id', $id)->orderBy('start_time')->get()));
    }

    public function storeSchedule(Request $request, Doctor $doctor): JsonResponse
    {
        $d = self::validateSchedule($request, $doctor);
        $id = DB::transaction(function () use ($d, $doctor) {
            $id = DB::table('doctor_schedules')->insertGetId(['doctor_id' => $doctor->id, 'created_at' => now(), 'updated_at' => now()] + self::scheduleFields($d));
            self::saveBreaks($id, $d['breaks'] ?? []);
            Audit::record('create', 'doctor_schedule', $id, ['doctor_id' => $doctor->id, 'day' => $d['day_of_week']]);

            return $id;
        });

        return $this->reload($id)->setStatusCode(201);
    }

    public function updateSchedule(Request $request, int $schedule): JsonResponse
    {
        $row = DB::table('doctor_schedules')->find($schedule) ?? abort(404, 'Schedule not found');
        $doctor = Doctor::findOrFail($row->doctor_id);
        $d = self::validateSchedule($request, $doctor, $schedule);
        DB::transaction(function () use ($d, $schedule) {
            DB::table('doctor_schedules')->where('id', $schedule)->update(['updated_at' => now()] + self::scheduleFields($d));
            self::saveBreaks($schedule, $d['breaks'] ?? []);
            Audit::record('update', 'doctor_schedule', $schedule);
        });

        return $this->reload($schedule);
    }

    /** Removes the template only. Slots already generated from it stay (and keep their bookings). */
    public function destroySchedule(int $schedule): JsonResponse
    {
        DB::table('doctor_schedules')->where('id', $schedule)->exists() || abort(404, 'Schedule not found');
        DB::table('doctor_schedules')->where('id', $schedule)->delete();
        Audit::record('delete', 'doctor_schedule', $schedule);

        return response()->json(null, 204);
    }

    // ---------- leave ----------

    public function leaves(Doctor $doctor): JsonResponse
    {
        return response()->json(DB::table('doctor_leaves')->where('doctor_id', $doctor->id)->where('end_date', '>=', Hospital::today())->orderBy('start_date')->get()
            ->map(fn ($l) => ['id' => $l->id, 'doctor_id' => $l->doctor_id, 'start_date' => $l->start_date, 'end_date' => $l->end_date, 'reason' => $l->reason]));
    }

    public function addLeave(Request $request, Doctor $doctor): JsonResponse
    {
        $d = $request->validate(['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:255']]);
        if ($d['end_date'] < $d['start_date']) {
            abort(422, 'The end date must not be before the start date');
        }
        if ($d['end_date'] < Hospital::today()) {
            abort(422, 'Leave must include today or a future date');
        }
        $result = DB::transaction(function () use ($d, $doctor) {
            $id = DB::table('doctor_leaves')->insertGetId(['doctor_id' => $doctor->id, 'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'reason' => $d['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            // Withdraw open, unbooked slots in the range so nobody can book while the doctor is away.
            $closed = DB::table('appointment_slots')->where('doctor_id', $doctor->id)->whereBetween('slot_date', [max($d['start_date'], Hospital::today()), $d['end_date']])
                ->where('booked_count', 0)->where('status', 'available')->update(['status' => 'blocked', 'block_reason' => 'Doctor on leave']);
            $booked = DB::table('appointments')->where('doctor_id', $doctor->id)->whereBetween('appointment_date', [$d['start_date'], $d['end_date']])->whereIn('status', ['pending', 'confirmed'])->count();
            Audit::record('create', 'doctor_leave', $id, ['doctor_id' => $doctor->id, 'slots_closed' => $closed, 'bookings_affected' => $booked]);

            return ['id' => $id, 'slots_closed' => $closed, 'bookings_affected' => $booked];
        });

        return response()->json($result + $d + ['doctor_id' => $doctor->id], 201);
    }

    public function deleteLeave(int $leave): JsonResponse
    {
        $l = DB::table('doctor_leaves')->find($leave) ?? abort(404, 'Leave not found');
        DB::transaction(function () use ($l) {
            DB::table('doctor_leaves')->where('id', $l->id)->delete();
            // Reopen the slots this leave closed, unless another leave still covers them.
            $slots = DB::table('appointment_slots')->where('doctor_id', $l->doctor_id)->whereBetween('slot_date', [$l->start_date, $l->end_date])
                ->where('status', 'blocked')->where('block_reason', 'Doctor on leave')->get(['id', 'slot_date']);
            $other = DB::table('doctor_leaves')->where('doctor_id', $l->doctor_id)->get();
            $ids = $slots->filter(fn ($s) => ! $other->first(fn ($o) => $o->start_date <= $s->slot_date && $o->end_date >= $s->slot_date))->pluck('id');
            DB::table('appointment_slots')->whereIn('id', $ids)->update(['status' => 'available', 'block_reason' => null]);
            Audit::record('delete', 'doctor_leave', $l->id, ['slots_reopened' => $ids->count()]);
        });

        return response()->json(null, 204);
    }

    // ---------- slots ----------

    public static function slotOut(object $s): array
    {
        $display = in_array($s->status, ['blocked', 'cancelled'], true) ? $s->status : ($s->booked_count >= $s->capacity ? 'fully_booked' : ($s->booked_count > 0 ? 'partially_booked' : 'available'));

        return [
            'id' => $s->id, 'doctor_id' => $s->doctor_id, 'doctor_name' => $s->doctor_name ?? null, 'department_id' => $s->department_id, 'department_name' => $s->department_name ?? null,
            'slot_date' => $s->slot_date, 'start_time' => self::hm($s->start_time), 'end_time' => self::hm($s->end_time), 'capacity' => (int) $s->capacity,
            'booked_count' => (int) $s->booked_count, 'remaining' => max(0, $s->capacity - $s->booked_count), 'consultation_fee' => DoctorController::money($s->consultation_fee),
            'appointment_type' => $s->appointment_type, 'status' => $s->status, 'display_status' => $display, 'is_published' => (bool) $s->is_published, 'block_reason' => $s->block_reason,
        ];
    }

    private static function slotQuery()
    {
        return DB::table('appointment_slots as s')->join('doctors as d', 'd.id', '=', 's.doctor_id')->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('departments as dep', 'dep.id', '=', 's.department_id')->select('s.*', 'u.full_name as doctor_name', 'dep.name as department_name');
    }

    public function slots(Request $request): JsonResponse
    {
        $request->validate(['doctor_id' => 'nullable|integer', 'department_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|string', 'published' => 'nullable|in:0,1,true,false']);
        $from = $request->query('from') ?: Hospital::today();
        $to = $request->query('to') ?: Carbon::parse($from)->addDays(6)->toDateString();
        if ($to < $from || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 62) {
            abort(400, 'Choose a range of up to two months');
        }
        $q = self::slotQuery()->whereBetween('s.slot_date', [$from, $to]);
        foreach (['doctor_id' => 's.doctor_id', 'department_id' => 's.department_id'] as $param => $col) {
            if ($request->filled($param)) {
                $q->where($col, $request->query($param));
            }
        }
        if ($request->filled('published')) {
            $q->where('s.is_published', $request->boolean('published'));
        }
        if ($st = $request->query('status')) {
            match ($st) {
                'fully_booked' => $q->where('s.status', 'available')->whereColumn('s.booked_count', '>=', 's.capacity'),
                'partially_booked' => $q->where('s.status', 'available')->where('s.booked_count', '>', 0)->whereColumn('s.booked_count', '<', 's.capacity'),
                'available' => $q->where('s.status', 'available')->where('s.booked_count', 0),
                default => $q->where('s.status', $st),
            };
        }

        return Paging::respond($request, $q->orderBy('s.slot_date')->orderBy('s.start_time')->orderBy('s.doctor_id'), fn ($s) => self::slotOut($s));
    }

    public function createSlot(Request $request): JsonResponse
    {
        $d = $request->validate([
            'doctor_id' => ['required', 'integer'], 'department_id' => ['required', 'integer'], 'slot_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'], 'capacity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'consultation_fee' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'appointment_type' => ['nullable', Rule::in(self::APPOINTMENT_TYPES)], 'is_published' => ['nullable', 'boolean'],
        ]);
        $doctor = Doctor::find($d['doctor_id']) ?? abort(400, 'Unknown doctor');
        if ($d['end_time'] <= $d['start_time']) {
            abort(422, 'End time must be after the start time');
        }
        if ($d['slot_date'] < Hospital::today()) {
            abort(422, 'Slots cannot be created in the past');
        }
        if (! DB::table('doctor_departments')->where('doctor_id', $doctor->id)->where('department_id', $d['department_id'])->exists()) {
            abort(400, 'The doctor does not work in that department');
        }
        if (DB::table('appointment_slots')->where('doctor_id', $doctor->id)->where('slot_date', $d['slot_date'])->where('start_time', '<', $d['end_time'].':00')->where('end_time', '>', $d['start_time'].':00')->exists()) {
            abort(409, 'The doctor already has a slot overlapping that time');
        }
        $id = DB::table('appointment_slots')->insertGetId([
            'doctor_id' => $doctor->id, 'department_id' => $d['department_id'], 'slot_date' => $d['slot_date'], 'start_time' => $d['start_time'], 'end_time' => $d['end_time'],
            'capacity' => $d['capacity'] ?? 1, 'booked_count' => 0, 'consultation_fee' => $d['consultation_fee'] ?? $doctor->consultation_fee,
            'appointment_type' => $d['appointment_type'] ?? 'in_person', 'status' => 'available', 'is_published' => $d['is_published'] ?? true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Audit::record('create', 'slot', $id, ['doctor_id' => $doctor->id, 'date' => $d['slot_date']]);

        return response()->json(self::slotOut(self::slotQuery()->where('s.id', $id)->first()), 201);
    }

    public function generate(Request $request): JsonResponse
    {
        $d = $request->validate(['doctor_id' => ['required', 'integer'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d'], 'publish' => ['nullable', 'boolean']]);
        Doctor::find($d['doctor_id']) ?? abort(400, 'Unknown doctor');
        if ($d['to'] < $d['from'] || Carbon::parse($d['from'])->diffInDays(Carbon::parse($d['to'])) > 92) {
            abort(400, 'Choose a range of up to three months');
        }
        $result = DB::transaction(fn () => SlotGenerator::generate($d['doctor_id'], $d['from'], $d['to'], $d['publish'] ?? true));
        Audit::record('generate', 'slots', $d['doctor_id'], ['from' => $d['from'], 'to' => $d['to']] + ['created' => $result['created'], 'skipped' => $result['skipped']]);

        return response()->json($result);
    }

    /** Close, reopen, publish or unpublish slots. Closing never touches existing bookings. */
    public function bulk(Request $request): JsonResponse
    {
        $d = $request->validate([
            'action' => ['required', Rule::in(['block', 'unblock', 'publish', 'unpublish'])],
            'slot_ids' => ['nullable', 'array', 'max:500'], 'slot_ids.*' => ['integer'],
            'doctor_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $q = DB::table('appointment_slots')->where('slot_date', '>=', Hospital::today());
        if (! empty($d['slot_ids'])) {
            $q->whereIn('id', $d['slot_ids']);
        } elseif (! empty($d['doctor_id']) && ! empty($d['from']) && ! empty($d['to'])) {
            if (Carbon::parse($d['from'])->diffInDays(Carbon::parse($d['to'])) > 92) {
                abort(400, 'Choose a range of up to three months');
            }
            $q->where('doctor_id', $d['doctor_id'])->whereBetween('slot_date', [$d['from'], $d['to']]);
        } else {
            abort(422, 'Choose slots, or a doctor with a date range');
        }
        $changed = match ($d['action']) {
            'block' => $q->where('status', 'available')->update(['status' => 'blocked', 'block_reason' => $d['reason'] ?? 'Closed by staff', 'updated_at' => now()]),
            'unblock' => $q->where('status', 'blocked')->update(['status' => 'available', 'block_reason' => null, 'updated_at' => now()]),
            'publish' => $q->update(['is_published' => true, 'updated_at' => now()]),
            'unpublish' => $q->update(['is_published' => false, 'updated_at' => now()]),
        };
        Audit::record('bulk_'.$d['action'], 'slots', null, ['count' => $changed]);

        return response()->json(['changed' => $changed]);
    }

    /** Delete a slot nobody booked (e.g. one created by mistake). */
    public function deleteSlot(int $slot): JsonResponse
    {
        $s = DB::table('appointment_slots')->find($slot) ?? abort(404, 'Slot not found');
        if ($s->booked_count > 0 || DB::table('appointments')->where('slot_id', $slot)->exists()) {
            abort(409, 'This slot has bookings. Close it instead of deleting it.');
        }
        DB::table('appointment_slots')->where('id', $slot)->delete();
        Audit::record('delete', 'slot', $slot);

        return response()->json(null, 204);
    }
}
