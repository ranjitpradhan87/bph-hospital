<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BookingService;
use App\Support\Hospital;
use App\Support\Paging;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    private const STATUSES = ['pending', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'];

    private static function base()
    {
        return DB::table('appointments as a')->join('patients as p', 'p.id', '=', 'a.patient_id')->join('doctors as d', 'd.id', '=', 'a.doctor_id')
            ->join('users as u', 'u.id', '=', 'd.user_id')->join('departments as dep', 'dep.id', '=', 'a.department_id')
            ->select('a.*', 'p.patient_code', 'p.full_name as patient_name', 'p.mobile as patient_mobile', 'u.full_name as doctor_name', 'd.specialization', 'dep.name as department_name');
    }

    private static function ownPatientId(User $u): ?int
    {
        return DB::table('patients')->where('user_id', $u->id)->whereNull('deleted_at')->value('id');
    }

    private static function ownDoctorId(User $u): ?int
    {
        return DB::table('doctors')->where('user_id', $u->id)->whereNull('deleted_at')->value('id');
    }

    /** What the signed-in person may do to an appointment right now. */
    private static function out(object $a, User $viewer, bool $staff): array
    {
        $upcoming = in_array($a->status, ['pending', 'confirmed'], true);
        $until = BookingService::minutesUntil($a->appointment_date, $a->start_time);
        if ($staff) {
            $canCancel = $upcoming && $viewer->can('appointments.cancel');
            $canResched = $upcoming && $viewer->can('appointments.reschedule');
        } elseif ($a->patient_id !== self::ownPatientId($viewer)) {
            $canCancel = $canResched = false; // a doctor viewing their own visit cannot use the patient controls
        } else {
            $canCancel = $upcoming && $until >= (int) SettingsRegistry::value('booking.cancellation_cutoff_hours', 4) * 60;
            $canResched = $upcoming && $until >= (int) SettingsRegistry::value('booking.reschedule_cutoff_hours', 4) * 60
                && BookingService::rescheduleCount($a) < (int) SettingsRegistry::value('booking.max_reschedules', 2);
        }

        return [
            'id' => $a->id, 'appointment_number' => $a->appointment_number, 'appointment_date' => $a->appointment_date,
            'start_time' => substr($a->start_time, 0, 5), 'end_time' => substr($a->end_time, 0, 5), 'status' => $a->status,
            'appointment_type' => $a->appointment_type, 'payment_status' => $a->payment_status, 'payment_mode' => $a->payment_mode, 'advance_amount' => DoctorController::money($a->advance_amount), 'consultation_fee' => DoctorController::money($a->consultation_fee),
            'reason' => $a->reason, 'queue_number' => $a->queue_number, 'booking_source' => $a->booking_source,
            'patient_id' => $a->patient_id, 'patient_code' => $a->patient_code, 'patient_name' => $a->patient_name,
            'patient_mobile' => $staff ? $a->patient_mobile : null,
            'doctor_id' => $a->doctor_id, 'doctor_name' => $a->doctor_name, 'specialization' => $a->specialization,
            'department_id' => $a->department_id, 'department_name' => $a->department_name, 'slot_id' => $a->slot_id,
            'cancel_reason' => $a->cancel_reason, 'rescheduled_from_id' => $a->rescheduled_from_id,
            'checked_in_at' => $a->checked_in_at ? \Illuminate\Support\Carbon::parse($a->checked_in_at, 'UTC')->toIso8601String() : null,
            'created_at' => \Illuminate\Support\Carbon::parse($a->created_at, 'UTC')->toIso8601String(),
            'can_cancel' => $canCancel, 'can_reschedule' => $canResched,
        ];
    }

    /** Returns the appointment if this user may see it; patients and doctors only see their own. */
    private static function accessible(int $id, User $u): array
    {
        $a = self::base()->where('a.id', $id)->first() ?? abort(404, 'Appointment not found');
        $staff = $u->can('appointments.read');
        if (! $staff) {
            $own = $a->patient_id === self::ownPatientId($u) || $a->doctor_id === self::ownDoctorId($u);
            $own || abort(404, 'Appointment not found');
        }

        return [$a, $staff];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'doctor_id' => 'nullable|integer', 'department_id' => 'nullable|integer',
            'patient_id' => 'nullable|integer', 'status' => ['nullable', Rule::in(self::STATUSES)], 'q' => 'nullable|string|max:100', 'upcoming' => 'nullable|in:0,1,true,false',
        ]);
        $user = $request->user();
        $staff = $user->can('appointments.read');
        $q = self::base();
        if (! $staff) {
            $pid = self::ownPatientId($user);
            $did = self::ownDoctorId($user);
            $q->where(fn ($w) => $w->when($pid, fn ($x) => $x->orWhere('a.patient_id', $pid))->when($did, fn ($x) => $x->orWhere('a.doctor_id', $did))
                ->when(! $pid && ! $did, fn ($x) => $x->whereRaw('1 = 0')));
        }
        foreach (['doctor_id' => 'a.doctor_id', 'department_id' => 'a.department_id', 'patient_id' => 'a.patient_id', 'status' => 'a.status'] as $param => $col) {
            if ($request->filled($param)) {
                $q->where($col, $request->query($param));
            }
        }
        if ($request->filled('from')) {
            $q->where('a.appointment_date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->where('a.appointment_date', '<=', $request->query('to'));
        }
        if ($request->boolean('upcoming')) {
            $q->whereIn('a.status', ['pending', 'confirmed', 'checked_in', 'in_consultation'])->where('a.appointment_date', '>=', Hospital::today());
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('p.full_name', 'like', "%$term%")->orWhere('p.patient_code', 'like', "%$term%")->orWhere('a.appointment_number', 'like', "%$term%")
                ->orWhere('p.mobile', 'like', '%'.preg_replace('/\D/', '', $term).'%'));
        }
        $upcomingFirst = $request->boolean('upcoming');
        $q = $upcomingFirst ? $q->orderBy('a.appointment_date')->orderBy('a.start_time') : $q->orderByDesc('a.appointment_date')->orderByDesc('a.start_time');

        return Paging::respond($request, $q, fn ($a) => self::out($a, $user, $staff));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$a, $staff] = self::accessible($id, $request->user());
        $history = DB::table('appointment_status_history as h')->leftJoin('users as u', 'u.id', '=', 'h.changed_by_user_id')->where('h.appointment_id', $id)
            ->orderBy('h.id')->get(['h.from_status', 'h.to_status', 'h.note', 'h.created_at', 'u.full_name as changed_by'])
            ->map(fn ($h) => ['from_status' => $h->from_status, 'to_status' => $h->to_status, 'note' => $h->note, 'changed_by' => $h->changed_by,
                'created_at' => \Illuminate\Support\Carbon::parse($h->created_at, 'UTC')->toIso8601String()]);

        return response()->json(self::out($a, $request->user(), $staff) + ['history' => $history]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(['slot_id' => ['required', 'integer'], 'patient_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string', 'max:500'], 'payment_mode' => ['nullable', Rule::in(BookingService::PAYMENT_MODES)]]);
        if ($user->can('patients.read')) {
            $patientId = $d['patient_id'] ?? abort(422, 'Choose the patient to book for');
            $source = array_intersect(['admin', 'super_admin'], $user->roleCodes()) ? 'admin' : 'reception';
        } else {
            $patientId = self::ownPatientId($user) ?? abort(403, 'Only patients and front-desk staff can book appointments');
            $source = 'online';
        }
        // Patients booking online pay an advance; staff booking at the desk take payment at the counter.
        $id = BookingService::book($d['slot_id'], $patientId, $user->id, $source, $d['reason'] ?? null, $d['payment_mode'] ?? ($source === 'online' ? 'advance_online' : 'pay_at_hospital'));
        $response = $this->show($request, $id)->setStatusCode(201);
        if (DB::table('appointments')->where('id', $id)->where('payment_status', 'awaiting_payment')->exists()) {
            $p = DB::table('patients')->find($patientId);
            $response->setData(array_merge($response->getData(true), ['payment' => BookingService::startPayment($id, $p->full_name, $p->mobile)]));
        }

        return $response;
    }

    /** Staff act on any appointment; patients only on their own, under the booking policy. */
    private static function actorScope(Request $request, int $id, string $staffPermission): bool
    {
        $user = $request->user();
        $a = DB::table('appointments')->find($id) ?? abort(404, 'Appointment not found');
        if ($user->can($staffPermission)) {
            return false; // staff: no patient-facing cutoffs
        }
        if ($user->can('portal.access') && $a->patient_id === self::ownPatientId($user)) {
            return true;
        }
        abort(403, 'You do not have permission to perform this action');
    }

    /** A patient reopens the payment for their own held booking. */
    public function pay(Request $request, int $id): JsonResponse
    {
        $a = DB::table('appointments')->find($id) ?? abort(404, 'Appointment not found');
        $a->patient_id === self::ownPatientId($request->user()) || abort(403, 'You do not have permission to perform this action');

        return response()->json(BookingService::resumePayment($id));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $policy = self::actorScope($request, $id, 'appointments.cancel');
        BookingService::cancel($id, $request->user()->id, $d['reason'] ?? null, $policy);

        return $this->show($request, $id);
    }

    public function reschedule(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['slot_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:500']]);
        $policy = self::actorScope($request, $id, 'appointments.reschedule');
        $newId = BookingService::reschedule($id, $d['slot_id'], $request->user()->id, $d['reason'] ?? null, $policy);

        return $this->show($request, $newId);
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $d = $request->validate(['status' => ['required', Rule::in(['confirmed', 'checked_in', 'in_consultation', 'completed', 'no_show'])], 'note' => ['nullable', 'string', 'max:255']]);
        $needed = $d['status'] === 'checked_in' ? 'appointments.check_in' : 'appointments.update_status';
        $user->can($needed) || abort(403, 'You do not have permission to perform this action');
        [$a] = self::accessible($id, $user); // doctors without appointments.read reach only their own visits
        if (in_array($d['status'], ['in_consultation', 'completed'], true) && ! $user->can('appointments.read') && $a->doctor_id !== self::ownDoctorId($user)) {
            abort(404, 'Appointment not found');
        }
        BookingService::transition($id, $d['status'], $user->id, $d['note'] ?? null);

        return $this->show($request, $id);
    }
}
