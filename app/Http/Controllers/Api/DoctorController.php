<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Role;
use App\Models\User;
use App\Services\RbacGuard;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Hospital;
use App\Support\Paging;
use App\Support\Phone;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class DoctorController extends Controller
{
    public const STATUSES = ['active', 'on_leave', 'inactive'];

    private const PROFILE = ['gender', 'qualification', 'specialization', 'experience_years', 'registration_number', 'consultation_fee', 'languages', 'bio', 'photo_url', 'is_featured'];

    public static function imageRule(): \Closure
    {
        return function ($a, $v, $fail) {
            if ($v !== null && $v !== '' && ! (str_starts_with($v, '/api/v1/media/') || str_starts_with($v, 'https://'))) {
                $fail('Use an uploaded image or an https:// URL');
            }
        };
    }

    public static function money(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    /** Appointments that still need the doctor: active status, today (not yet ended) or later. */
    private static function upcomingCount(int $doctorId): int
    {
        $now = Hospital::now();

        return DB::table('appointments')->where('doctor_id', $doctorId)->whereIn('status', DepartmentController::ACTIVE_STATUSES)
            ->where(fn ($q) => $q->where('appointment_date', '>', $now->toDateString())
                ->orWhere(fn ($q2) => $q2->where('appointment_date', $now->toDateString())->where('end_time', '>', $now->format('H:i:s'))))
            ->count();
    }

    private static function out(Doctor $d, bool $withUpcoming = true): array
    {
        $d->loadMissing('user', 'departments');
        $depts = $d->departments->sortBy([['pivot.is_primary', 'desc'], ['name', 'asc']])->map(fn ($x) => [
            'id' => $x->id, 'name' => $x->name, 'slug' => $x->slug, 'is_primary' => (bool) $x->pivot->is_primary,
        ])->values();

        return [
            'id' => $d->id, 'doctor_code' => $d->doctor_code, 'user_id' => $d->user_id, 'full_name' => $d->user->full_name,
            'email' => $d->user->email, 'mobile' => $d->user->mobile, 'gender' => $d->gender,
            'qualification' => $d->qualification, 'specialization' => $d->specialization, 'experience_years' => $d->experience_years,
            'registration_number' => $d->registration_number, 'consultation_fee' => self::money($d->consultation_fee),
            'advance_amount' => $d->advance_amount === null ? null : self::money($d->advance_amount), 'default_advance' => (float) SettingsRegistry::value('booking.advance_amount', 300),
            'languages' => $d->languages, 'bio' => $d->bio, 'photo_url' => $d->photo_url, 'is_featured' => $d->is_featured,
            'status' => $d->status, 'login_active' => (bool) $d->user->is_active, 'departments' => $depts,
            'upcoming_appointments' => $withUpcoming ? self::upcomingCount($d->id) : 0,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    private static function rules(bool $create): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'qualification' => ['required', 'string', 'min:2', 'max:255'],
            'specialization' => ['required', 'string', 'min:2', 'max:120'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:70'],
            'registration_number' => ['required', 'string', 'min:3', 'max:60'],
            'consultation_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'advance_amount' => ['nullable', 'numeric', 'min:0', 'max:100000', 'lte:consultation_fee'],
            'languages' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'photo_url' => ['nullable', 'string', self::imageRule()],
            'is_featured' => ['nullable', 'boolean'],
            'department_ids' => ['required', 'array', 'min:1'], 'department_ids.*' => ['integer'],
            'primary_department_id' => ['nullable', 'integer'],
        ] + ($create ? ['password' => ['required', Password::min(8)->max(128)->letters()->numbers()]] : []);
    }

    private static function input(Request $request, bool $create): array
    {
        $request->merge(['mobile' => Phone::normalize((string) $request->input('mobile', '')), 'email' => strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate(self::rules($create), ['mobile.regex' => 'Enter a valid 10-digit Indian mobile number']);
        if (! empty($data['primary_department_id']) && ! in_array($data['primary_department_id'], $data['department_ids'], true)) {
            abort(422, 'The primary department must be one of the selected departments');
        }
        $data['department_ids'] = array_values(array_unique($data['department_ids']));

        return $data;
    }

    private static function profile(array $data): array
    {
        return [
            'gender' => $data['gender'] ?? null, 'qualification' => trim($data['qualification']), 'specialization' => trim($data['specialization']),
            'experience_years' => $data['experience_years'] ?? 0, 'registration_number' => trim($data['registration_number']),
            'consultation_fee' => $data['consultation_fee'], 'advance_amount' => $data['advance_amount'] ?? null, 'languages' => $data['languages'] ?? null, 'bio' => $data['bio'] ?? null,
            'photo_url' => $data['photo_url'] ?? null, 'is_featured' => (bool) ($data['is_featured'] ?? false),
        ];
    }

    private static function checkDepartments(array $ids): void
    {
        $found = DB::table('departments')->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id')->all();
        if ($missing = array_diff($ids, $found)) {
            abort(400, 'Unknown departments: '.implode(', ', $missing));
        }
    }

    private static function setDepartments(Doctor $d, array $ids, ?int $primary): void
    {
        $primary = $primary ?: $ids[0];
        $d->departments()->sync(collect($ids)->mapWithKeys(fn ($id) => [$id => ['is_primary' => $id === $primary]])->all());
    }

    private static function ensureRegistrationUnique(string $reg, ?int $exceptId = null): void
    {
        $q = Doctor::withTrashed()->whereRaw('lower(registration_number) = ?', [mb_strtolower(trim($reg))]);
        if ($exceptId) {
            $q->where('id', '!=', $exceptId);
        }
        if ($q->exists()) {
            abort(409, 'Another doctor already has this registration number');
        }
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(self::STATUSES)], 'department_id' => 'nullable|integer']);
        $q = Doctor::with('user', 'departments')->join('users', 'users.id', '=', 'doctors.user_id')->select('doctors.*');
        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('users.full_name', 'like', "%$s%")->orWhere('doctors.doctor_code', 'like', "%$s%")
                ->orWhere('doctors.specialization', 'like', "%$s%")->orWhere('doctors.registration_number', 'like', "%$s%")->orWhere('users.mobile', 'like', "%$s%"));
        }
        if ($dept = $request->query('department_id')) {
            $q->whereExists(fn ($e) => $e->select(DB::raw(1))->from('doctor_departments as dd')->whereColumn('dd.doctor_id', 'doctors.id')->where('dd.department_id', $dept));
        }
        if ($status = $request->query('status')) {
            $q->where('doctors.status', $status);
        }

        return Paging::respond($request, $q->orderBy('users.full_name'), fn ($d) => self::out($d));
    }

    public function store(Request $request): JsonResponse
    {
        $data = self::input($request, true);
        RbacGuard::ensureContactAvailable($data['email'], $data['mobile']);
        self::ensureRegistrationUnique($data['registration_number']);
        self::checkDepartments($data['department_ids']);

        $doctor = DB::transaction(function () use ($data, $request) {
            $user = User::create(['email' => $data['email'], 'mobile' => $data['mobile'], 'full_name' => trim($data['full_name']), 'password' => $data['password']]);
            $user->roles()->sync(Role::where('code', 'doctor')->pluck('id'));
            $doctor = Doctor::create(self::profile($data) + ['doctor_code' => Codes::placeholder(), 'user_id' => $user->id]);
            $doctor->update(['doctor_code' => Codes::doctor($doctor->id)]);
            self::setDepartments($doctor, $data['department_ids'], $data['primary_department_id'] ?? null);
            Audit::record('create', 'doctor', $doctor->id, ['name' => $data['full_name'], 'departments' => $data['department_ids']]);

            return $doctor;
        });

        return response()->json(self::out($doctor->refresh()), 201);
    }

    public function show(Doctor $doctor): JsonResponse
    {
        return response()->json(self::out($doctor));
    }

    public function update(Request $request, Doctor $doctor): JsonResponse
    {
        $data = self::input($request, false);
        RbacGuard::ensureContactAvailable($data['email'], $data['mobile'], $doctor->user_id);
        self::ensureRegistrationUnique($data['registration_number'], $doctor->id);
        self::checkDepartments($data['department_ids']);

        DB::transaction(function () use ($data, $doctor) {
            $changes = [];
            $user = $doctor->user;
            $user->fill(['full_name' => trim($data['full_name']), 'email' => $data['email'], 'mobile' => $data['mobile']]);
            $doctor->fill(self::profile($data));
            foreach ([$user, $doctor] as $m) {
                foreach ($m->getDirty() as $k => $v) {
                    $changes[$k] = ['before' => (string) $m->getOriginal($k), 'after' => (string) $v];
                }
                $m->save();
            }
            $before = $doctor->departments()->pluck('departments.id')->sort()->values()->all();
            self::setDepartments($doctor, $data['department_ids'], $data['primary_department_id'] ?? null);
            if ($before !== collect($data['department_ids'])->sort()->values()->all()) {
                $changes['departments'] = ['before' => $before, 'after' => $data['department_ids']];
            }
            if ($changes) {
                Audit::record('update', 'doctor', $doctor->id, $changes);
            }
        });

        return response()->json(self::out($doctor->refresh()));
    }

    /** Withdraw unbooked future slots so nothing new can be booked. */
    private static function closeFutureSlots(int $doctorId, string $reason): int
    {
        return DB::table('appointment_slots')->where('doctor_id', $doctorId)->where('slot_date', '>=', Hospital::today())
            ->where('booked_count', 0)->where('status', 'available')->update(['status' => 'blocked', 'block_reason' => $reason]);
    }

    /** Quick edit of the two amounts patients see: the consultation fee and the advance paid online. */
    public function setFees(Request $request, Doctor $doctor): JsonResponse
    {
        $d = $request->validate([
            'consultation_fee' => ['required', 'numeric', 'min:0', 'max:100000'],
            'advance_amount' => ['nullable', 'numeric', 'min:0', 'max:100000', 'lte:consultation_fee'],
        ]);
        $before = ['fee' => $doctor->consultation_fee, 'advance' => $doctor->advance_amount];
        $doctor->update(['consultation_fee' => $d['consultation_fee'], 'advance_amount' => $d['advance_amount'] ?? null]);
        Audit::record('update_fees', 'doctor', $doctor->id, ['before' => $before, 'after' => ['fee' => $d['consultation_fee'], 'advance' => $d['advance_amount'] ?? null]]);

        return response()->json(self::out($doctor->refresh()));
    }

    public function setStatus(Request $request, Doctor $doctor): JsonResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]])['status'];
        DB::transaction(function () use ($doctor, $status) {
            $closed = 0;
            if ($status === 'inactive') {
                if ($n = self::upcomingCount($doctor->id)) {
                    abort(409, "{$doctor->user->full_name} has $n upcoming appointment(s). Reschedule or cancel them before deactivating.");
                }
                $closed = self::closeFutureSlots($doctor->id, 'Doctor deactivated');
                $doctor->user->update(['is_active' => false]);
                DB::table('sessions')->where('user_id', $doctor->user_id)->delete();
            } else {
                $doctor->user->update(['is_active' => true]);
            }
            $before = $doctor->status;
            $doctor->update(['status' => $status]);
            Audit::record('update_status', 'doctor', $doctor->id, ['before' => $before, 'after' => $status, 'slots_closed' => $closed]);
        });

        return response()->json(self::out($doctor->refresh()));
    }

    public function destroy(Doctor $doctor): JsonResponse
    {
        DB::transaction(function () use ($doctor) {
            if ($n = self::upcomingCount($doctor->id)) {
                abort(409, "{$doctor->user->full_name} has $n upcoming appointment(s). Reschedule or cancel them before removing the doctor.");
            }
            self::closeFutureSlots($doctor->id, 'Doctor removed');
            $name = $doctor->user->full_name;
            $user = $doctor->user;
            // Free the login identifiers for reuse; history keeps the doctor's name.
            $user->forceFill(['is_active' => false, 'email' => null, 'mobile' => null])->save();
            $user->delete();
            $doctor->update(['status' => 'inactive']);
            $doctor->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Audit::record('delete', 'doctor', $doctor->id, ['name' => $name]);
        });

        return response()->json(null, 204);
    }

    // ---------- history & stats ----------

    public static function appointmentRow(object $a): array
    {
        return [
            'id' => $a->id, 'appointment_number' => $a->appointment_number, 'appointment_date' => $a->appointment_date,
            'start_time' => $a->start_time, 'end_time' => $a->end_time, 'status' => $a->status, 'appointment_type' => $a->appointment_type,
            'patient_id' => $a->patient_id, 'patient_code' => $a->patient_code, 'patient_name' => $a->patient_name,
            'doctor_id' => $a->doctor_id, 'doctor_name' => $a->doctor_name, 'department_name' => $a->department_name,
            'consultation_fee' => self::money($a->consultation_fee),
        ];
    }

    public function appointments(Request $request, Doctor $doctor): JsonResponse
    {
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'status' => 'nullable|string']);
        $q = DB::table('appointments as a')->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->join('doctors as d', 'd.id', '=', 'a.doctor_id')->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('departments as dep', 'dep.id', '=', 'a.department_id')
            ->where('a.doctor_id', $doctor->id)
            ->select('a.*', 'p.patient_code', 'p.full_name as patient_name', 'u.full_name as doctor_name', 'dep.name as department_name');
        if ($s = $request->query('status')) {
            $q->where('a.status', $s);
        }
        if ($f = $request->query('from')) {
            $q->where('a.appointment_date', '>=', $f);
        }
        if ($t = $request->query('to')) {
            $q->where('a.appointment_date', '<=', $t);
        }

        return Paging::respond($request, $q->orderByDesc('a.appointment_date')->orderByDesc('a.start_time'), fn ($a) => self::appointmentRow($a));
    }

    public function stats(Request $request, Doctor $doctor): JsonResponse
    {
        $request->validate(['days' => 'nullable|integer|min:7|max:365']);
        $days = (int) $request->query('days', 30);
        $end = Hospital::now()->toDateString();
        $start = Hospital::now()->subDays($days - 1)->toDateString();
        $base = fn () => DB::table('appointments')->where('doctor_id', $doctor->id)->whereBetween('appointment_date', [$start, $end]);

        $byStatus = array_fill_keys(['pending', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'], 0);
        foreach ($base()->groupBy('status')->selectRaw('status, count(*) as c')->get() as $r) {
            $byStatus[$r->status] = (int) $r->c;
        }
        $finished = $byStatus['completed'] + $byStatus['no_show'];
        $open = DB::query()->fromSub(Hospital::bookableSlots(null, Hospital::now()->addDays(30)->toDateString())->where('s.doctor_id', $doctor->id)->select('s.id'), 'x')->count();

        return response()->json([
            'period_from' => $start, 'period_to' => $end, 'total' => array_sum($byStatus), 'by_status' => $byStatus,
            'completion_rate' => $finished ? round($byStatus['completed'] / $finished, 3) : null,
            'unique_patients' => $base()->distinct()->count('patient_id'),
            'upcoming' => self::upcomingCount($doctor->id), 'open_slots_next_30_days' => $open,
        ]);
    }
}
