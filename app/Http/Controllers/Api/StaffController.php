<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\User;
use App\Services\RbacGuard;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Hospital;
use App\Support\Paging;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller
{
    public const EMPLOYMENT = ['active', 'on_leave', 'suspended', 'resigned', 'terminated'];

    public const ATTENDANCE = ['present', 'absent', 'half_day', 'leave', 'holiday'];

    public const SHIFT_TYPES = ['morning', 'evening', 'night', 'general'];

    private const PROFILE = ['gender', 'date_of_birth', 'address', 'photo_url', 'department_id', 'designation', 'joining_date'];

    private static function canSeeSalary(User $u): bool
    {
        return $u->can('staff.salary');
    }

    private static function out(Staff $s, User $viewer): array
    {
        $s->loadMissing('user.roles', 'department');
        $salary = self::canSeeSalary($viewer) && $s->salary_encrypted !== null && $s->salary_encrypted !== ''
            ? DoctorController::money($s->salary_encrypted) : null;

        return [
            'id' => $s->id, 'staff_code' => $s->staff_code, 'user_id' => $s->user_id, 'full_name' => $s->user->full_name,
            'email' => $s->user->email, 'mobile' => $s->user->mobile, 'gender' => $s->gender,
            'date_of_birth' => $s->date_of_birth?->toDateString(), 'address' => $s->address, 'photo_url' => $s->photo_url,
            'department_id' => $s->department_id, 'department_name' => $s->department?->name, 'designation' => $s->designation,
            'joining_date' => $s->joining_date->toDateString(), 'employment_status' => $s->employment_status,
            'roles' => collect($s->user->roleCodes())->sort()->values()->all(), 'login_active' => (bool) $s->user->is_active,
            'salary' => $salary, 'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    private static function rules(bool $create): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:-130 years'],
            'address' => ['nullable', 'string', 'max:500'],
            'photo_url' => ['nullable', 'string', DoctorController::imageRule()],
            'department_id' => ['nullable', 'integer'],
            'designation' => ['required', 'string', 'min:2', 'max:100'],
            'joining_date' => ['required', 'date_format:Y-m-d'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ] + ($create ? [
            'password' => ['required', Password::min(8)->max(128)->letters()->numbers()],
            'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string'],
        ] : []);
    }

    private static function input(Request $request, bool $create): array
    {
        $request->merge(['mobile' => Phone::normalize((string) $request->input('mobile', '')), 'email' => strtolower(trim((string) $request->input('email', '')))]);

        return $request->validate(self::rules($create), ['mobile.regex' => 'Enter a valid 10-digit Indian mobile number']);
    }

    private static function profile(array $d): array
    {
        $o = [];
        foreach (self::PROFILE as $f) {
            $o[$f] = $d[$f] ?? null;
        }
        $o['designation'] = trim($d['designation']);

        return $o;
    }

    private static function checkDepartment(?int $id): void
    {
        if ($id && ! DB::table('departments')->where('id', $id)->whereNull('deleted_at')->exists()) {
            abort(400, 'Unknown department');
        }
    }

    private static function checkSalaryPermission(Request $request, User $actor): void
    {
        if ($request->has('salary') && $request->input('salary') !== null && ! self::canSeeSalary($actor)) {
            abort(403, "You don't have permission to set salaries");
        }
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(self::EMPLOYMENT)], 'department_id' => 'nullable|integer']);
        $q = Staff::with('user.roles', 'department')->join('users', 'users.id', '=', 'staff.user_id')->select('staff.*');
        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('users.full_name', 'like', "%$s%")->orWhere('staff.staff_code', 'like', "%$s%")
                ->orWhere('staff.designation', 'like', "%$s%")->orWhere('users.mobile', 'like', "%$s%")->orWhere('users.email', 'like', "%$s%"));
        }
        if ($dept = $request->query('department_id')) {
            $q->where('staff.department_id', $dept);
        }
        if ($status = $request->query('status')) {
            $q->where('staff.employment_status', $status);
        }
        $viewer = $request->user();

        return Paging::respond($request, $q->orderBy('users.full_name'), fn ($s) => self::out($s, $viewer));
    }

    public function store(Request $request): JsonResponse
    {
        $data = self::input($request, true);
        $actor = $request->user();
        if (array_intersect(['doctor', 'patient'], $data['roles'])) {
            abort(400, 'Doctors are added from the Doctors screen and patients from Patients');
        }
        self::checkSalaryPermission($request, $actor);
        self::checkDepartment($data['department_id'] ?? null);
        RbacGuard::ensureContactAvailable($data['email'], $data['mobile']);
        $roles = RbacGuard::resolveRoles($data['roles'], $actor);

        $staff = DB::transaction(function () use ($data, $roles) {
            $user = User::create(['email' => $data['email'], 'mobile' => $data['mobile'], 'full_name' => trim($data['full_name']), 'password' => $data['password']]);
            $user->roles()->sync($roles->pluck('id'));
            $staff = Staff::create(self::profile($data) + [
                'staff_code' => Codes::placeholder(), 'user_id' => $user->id,
                'salary_encrypted' => isset($data['salary']) ? DoctorController::money($data['salary']) : null,
            ]);
            $staff->update(['staff_code' => Codes::staff($staff->id)]);
            Audit::record('create', 'staff', $staff->id, ['name' => $data['full_name'], 'roles' => collect($data['roles'])->sort()->values()->all()]);

            return $staff;
        });

        return response()->json(self::out($staff->refresh(), $actor), 201);
    }

    public function show(Request $request, Staff $staff): JsonResponse
    {
        return response()->json(self::out($staff, $request->user()));
    }

    public function update(Request $request, Staff $staff): JsonResponse
    {
        $data = self::input($request, false);
        $actor = $request->user();
        if ($staff->user_id !== $actor->id) {
            RbacGuard::guardTarget($staff->user, $actor);
        }
        self::checkSalaryPermission($request, $actor);
        self::checkDepartment($data['department_id'] ?? null);
        RbacGuard::ensureContactAvailable($data['email'], $data['mobile'], $staff->user_id);

        DB::transaction(function () use ($data, $staff, $actor, $request) {
            $changes = [];
            $staff->user->fill(['full_name' => trim($data['full_name']), 'email' => $data['email'], 'mobile' => $data['mobile']]);
            $staff->fill(self::profile($data));
            foreach ([$staff->user, $staff] as $m) {
                foreach ($m->getDirty() as $k => $v) {
                    $changes[$k] = ['before' => (string) $m->getOriginal($k), 'after' => (string) $v];
                }
                $m->save();
            }
            if (self::canSeeSalary($actor) && $request->has('salary')) {
                $new = isset($data['salary']) ? DoctorController::money($data['salary']) : null;
                $old = $staff->salary_encrypted === null ? null : DoctorController::money($staff->salary_encrypted);
                if ($old !== $new) {
                    $staff->update(['salary_encrypted' => $new]);
                    $changes['salary'] = 'changed'; // never write the amount into the audit log
                }
            }
            if ($changes) {
                Audit::record('update', 'staff', $staff->id, $changes);
            }
        });

        return response()->json(self::out($staff->refresh(), $actor));
    }

    public function setEmployment(Request $request, Staff $staff): JsonResponse
    {
        $status = $request->validate(['employment_status' => ['required', Rule::in(self::EMPLOYMENT)]])['employment_status'];
        $actor = $request->user();
        RbacGuard::guardTarget($staff->user, $actor); // also forbids changing your own status
        $before = $staff->employment_status;
        DB::transaction(function () use ($staff, $status, $before) {
            $staff->update(['employment_status' => $status]);
            // Only active employees can sign in; leaving or suspension signs them out everywhere.
            $staff->user->update(['is_active' => $status === 'active']);
            if ($status !== 'active') {
                DB::table('sessions')->where('user_id', $staff->user_id)->delete();
            }
            Audit::record('update_employment', 'staff', $staff->id, ['before' => $before, 'after' => $status]);
        });

        return response()->json(self::out($staff->refresh(), $actor));
    }

    // ---------- shifts ----------

    private static function shiftOut(object $s): array
    {
        return ['id' => $s->id, 'staff_id' => $s->staff_id, 'shift_date' => $s->shift_date, 'start_time' => $s->start_time,
            'end_time' => $s->end_time, 'shift_type' => $s->shift_type, 'notes' => $s->notes];
    }

    public function shifts(Request $request, Staff $staff): JsonResponse
    {
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $start = $request->query('from') ?: Hospital::today();
        $end = $request->query('to') ?: Carbon::parse($start)->addDays(13)->toDateString();
        if ($end < $start || Carbon::parse($start)->diffInDays(Carbon::parse($end)) > 92) {
            abort(400, 'Choose a range of up to three months');
        }
        $rows = DB::table('staff_shifts')->where('staff_id', $staff->id)->whereBetween('shift_date', [$start, $end])
            ->orderBy('shift_date')->orderBy('start_time')->get();

        return response()->json($rows->map(fn ($s) => self::shiftOut($s)));
    }

    public function addShift(Request $request, Staff $staff): JsonResponse
    {
        $data = $request->validate([
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
            'shift_type' => ['nullable', Rule::in(self::SHIFT_TYPES)], 'notes' => ['nullable', 'string', 'max:255'],
        ]);
        if ($data['start_time'] === $data['end_time']) {
            abort(422, "A shift can't start and end at the same time");
        }
        $dup = DB::table('staff_shifts')->where('staff_id', $staff->id)->where('shift_date', $data['shift_date'])->where('start_time', $data['start_time'].':00')->exists();
        if ($dup) {
            abort(409, 'This person already has a shift starting at that time');
        }
        $id = DB::table('staff_shifts')->insertGetId([
            'staff_id' => $staff->id, 'shift_date' => $data['shift_date'], 'start_time' => $data['start_time'], 'end_time' => $data['end_time'],
            'shift_type' => $data['shift_type'] ?? 'general', 'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Audit::record('create', 'staff_shift', $id, ['staff_id' => $staff->id, 'date' => $data['shift_date']]);

        return response()->json(self::shiftOut(DB::table('staff_shifts')->find($id)), 201);
    }

    public function deleteShift(int $shift): JsonResponse
    {
        if (! DB::table('staff_shifts')->where('id', $shift)->exists()) {
            abort(404, 'Shift not found');
        }
        Audit::record('delete', 'staff_shift', $shift);
        DB::table('staff_shifts')->where('id', $shift)->delete();

        return response()->json(null, 204);
    }

    // ---------- attendance ----------

    private static function attendanceRow(Staff $s, string $day, ?object $r): array
    {
        return [
            'staff_id' => $s->id, 'staff_code' => $s->staff_code, 'full_name' => $s->user->full_name, 'designation' => $s->designation,
            'department_name' => $s->department?->name, 'attendance_date' => $day,
            'status' => $r?->status, 'check_in' => Hospital::localTime($r?->check_in), 'check_out' => Hospital::localTime($r?->check_out),
            'remarks' => $r?->remarks,
        ];
    }

    private static function dayRows(string $day, ?int $departmentId): array
    {
        $q = Staff::with('user', 'department')->join('users', 'users.id', '=', 'staff.user_id')->select('staff.*')
            ->whereNotIn('staff.employment_status', ['resigned', 'terminated'])->where('staff.joining_date', '<=', $day)->orderBy('users.full_name');
        if ($departmentId) {
            $q->where('staff.department_id', $departmentId);
        }
        $staff = $q->get();
        $records = DB::table('staff_attendance')->where('attendance_date', $day)->whereIn('staff_id', $staff->pluck('id'))->get()->keyBy('staff_id');

        return $staff->map(fn ($s) => self::attendanceRow($s, $day, $records->get($s->id)))->values()->all();
    }

    public function attendanceDay(Request $request): JsonResponse
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d', 'department_id' => 'nullable|integer']);

        return response()->json(self::dayRows($request->query('date') ?: Hospital::today(), $request->query('department_id') ? (int) $request->query('department_id') : null));
    }

    public function recordAttendance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.staff_id' => ['required', 'integer'],
            'entries.*.status' => ['required', Rule::in(self::ATTENDANCE)],
            'entries.*.check_in' => ['nullable', 'date_format:H:i'], 'entries.*.check_out' => ['nullable', 'date_format:H:i'],
            'entries.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);
        foreach ($data['entries'] as $i => $e) {
            if (! empty($e['check_in']) && ! empty($e['check_out']) && $e['check_out'] <= $e['check_in']) {
                abort(422, 'Check-out must be after check-in');
            }
        }
        $day = $data['attendance_date'];
        if ($day > Hospital::today()) {
            abort(400, "Attendance can't be recorded for a future date");
        }
        $ids = collect($data['entries'])->pluck('staff_id')->unique()->values();
        $known = DB::table('staff')->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id');
        if ($ids->diff($known)->isNotEmpty()) {
            abort(400, 'Unknown staff: '.$ids->diff($known)->values()->implode(', '));
        }
        DB::transaction(function () use ($data, $day, $request) {
            foreach ($data['entries'] as $e) {
                $values = [
                    'status' => $e['status'], 'check_in' => Hospital::toUtc($day, $e['check_in'] ?? null), 'check_out' => Hospital::toUtc($day, $e['check_out'] ?? null),
                    'remarks' => $e['remarks'] ?? null, 'recorded_by_user_id' => $request->user()->id, 'updated_at' => now(),
                ];
                $exists = DB::table('staff_attendance')->where('staff_id', $e['staff_id'])->where('attendance_date', $day)->exists();
                if ($exists) {
                    DB::table('staff_attendance')->where('staff_id', $e['staff_id'])->where('attendance_date', $day)->update($values);
                } else {
                    DB::table('staff_attendance')->insert($values + ['staff_id' => $e['staff_id'], 'attendance_date' => $day, 'created_at' => now()]);
                }
            }
            Audit::record('record', 'staff_attendance', null, ['date' => $day, 'count' => count($data['entries'])]);
        });

        return response()->json(self::dayRows($day, null));
    }

    public function staffMonth(Request $request, Staff $staff): JsonResponse
    {
        $request->validate(['month' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        try {
            $first = Carbon::createFromFormat('!Y-m', $request->query('month'));
        } catch (\Throwable) {
            abort(400, 'Month must look like 2026-11');
        }
        if (! $first || $first->format('Y-m') !== $request->query('month')) {
            abort(400, 'Month must look like 2026-11');
        }
        $rows = DB::table('staff_attendance')->where('staff_id', $staff->id)
            ->whereBetween('attendance_date', [$first->toDateString(), $first->copy()->endOfMonth()->toDateString()])->orderBy('attendance_date')->get();
        $staff->loadMissing('user', 'department');

        return response()->json($rows->map(fn ($r) => self::attendanceRow($staff, $r->attendance_date, $r)));
    }
}
