<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\BookingService;
use App\Support\Audit;
use App\Support\Hospital;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Out-patient (OPD) visits: a walk-in or a booked appointment gets a token for the day, is billed for the consultation,
 * sees the doctor (vitals, diagnosis, prescription), and may be sent for lab tests. Everything hangs off the patient,
 * the doctor, one bill and the lab orders, so each of those screens shows the visit too.
 */
class OpdController extends Controller
{
    private const STATUSES = ['waiting', 'in_consultation', 'completed', 'cancelled'];

    private static function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    private static function iso($t): ?string
    {
        return $t ? Carbon::parse($t, 'UTC')->toIso8601String() : null;
    }

    /** A doctor without the right to manage the desk sees only their own patients. */
    private static function ownDoctorScope(Request $request): ?int
    {
        $u = $request->user();
        if ($u->can('opd.manage')) {
            return null;
        }
        $id = DB::table('doctors')->where('user_id', $u->id)->value('id');

        return $id ? (int) $id : null;
    }

    private static function base()
    {
        return DB::table('opd_visits as v')->join('patients as p', 'p.id', '=', 'v.patient_id')->join('doctors as d', 'd.id', '=', 'v.doctor_id')->join('users as du', 'du.id', '=', 'd.user_id')
            ->leftJoin('departments as dp', 'dp.id', '=', 'v.department_id')->leftJoin('bills as b', 'b.id', '=', 'v.bill_id')->leftJoin('appointments as a', 'a.id', '=', 'v.appointment_id')
            ->select('v.*', 'p.patient_code', 'p.full_name as patient_name', 'p.mobile as patient_mobile', 'du.full_name as doctor_name', 'd.specialization', 'dp.name as department_name',
                'a.appointment_number', 'b.bill_number', 'b.total_amount', 'b.paid_amount', 'b.status as bill_status')
            ->selectRaw('(select count(*) from lab_orders lo where lo.opd_visit_id = v.id and lo.status != \'cancelled\') as lab_count')
            ->selectRaw('(select ia.id from ipd_admissions ia where ia.opd_visit_id = v.id and ia.deleted_at is null order by ia.id desc limit 1) as admission_id');
    }

    private static function rowOut(object $v): array
    {
        return [
            'id' => $v->id, 'visit_date' => $v->visit_date, 'token_number' => (int) $v->token_number, 'status' => $v->status, 'source' => $v->source, 'reason' => $v->reason,
            'patient_id' => $v->patient_id, 'patient_code' => $v->patient_code, 'patient_name' => $v->patient_name, 'patient_mobile' => $v->patient_mobile,
            'doctor_id' => $v->doctor_id, 'doctor_name' => $v->doctor_name, 'specialization' => $v->specialization, 'department' => $v->department_name,
            'appointment_id' => $v->appointment_id, 'appointment_number' => $v->appointment_number, 'admission_id' => $v->admission_id ? (int) $v->admission_id : null, 'fee' => self::money($v->fee), 'lab_count' => (int) $v->lab_count,
            'bill' => $v->bill_id ? ['id' => $v->bill_id, 'number' => $v->bill_number, 'total' => self::money($v->total_amount), 'paid' => self::money($v->paid_amount),
                'balance' => self::money($v->total_amount - $v->paid_amount), 'status' => $v->bill_status] : null,
            'started_at' => self::iso($v->started_at), 'completed_at' => self::iso($v->completed_at), 'cancel_reason' => $v->cancel_reason,
        ];
    }

    private static function fullOut(int $id): array
    {
        $v = self::base()->where('v.id', $id)->first() ?? abort(404, 'Visit not found');
        $c = DB::table('consultations')->where('opd_visit_id', $id)->first();
        $rx = $c ? DB::table('prescriptions')->where('consultation_id', $c->id)->orderBy('id')->get(['id', 'medicine_name', 'dosage', 'frequency', 'duration', 'route', 'instructions']) : collect();
        $labs = DB::table('lab_orders as o')->leftJoin('bills as b', 'b.id', '=', 'o.bill_id')->where('o.opd_visit_id', $id)->orderBy('o.id')
            ->selectRaw('o.id, o.order_number, o.status, b.total_amount, b.paid_amount, (select group_concat(i.test_name order by i.id separator \', \') from lab_order_items i where i.order_id = o.id) as tests')->get()
            ->map(fn ($o) => ['id' => $o->id, 'order_number' => $o->order_number, 'status' => $o->status, 'tests' => $o->tests, 'total' => self::money($o->total_amount), 'paid' => self::money($o->paid_amount)])->values();
        $payments = $v->bill_id ? DB::table('payments as pm')->leftJoin('users as u', 'u.id', '=', 'pm.received_by_user_id')->where('pm.bill_id', $v->bill_id)->orderBy('pm.id')
            ->get(['pm.receipt_number', 'pm.amount', 'pm.method', 'pm.reference_no', 'pm.paid_at', 'u.full_name as by'])
            ->map(fn ($p) => ['receipt_number' => $p->receipt_number, 'amount' => self::money($p->amount), 'method' => $p->method, 'reference_no' => $p->reference_no, 'paid_at' => self::iso($p->paid_at), 'received_by' => $p->by])->values() : [];
        $pt = DB::table('patients')->find($v->patient_id);

        return self::rowOut($v) + [
            'patient' => ['gender' => $pt->gender ?? null, 'age' => $pt && $pt->date_of_birth ? (int) Carbon::parse($pt->date_of_birth)->diffInYears(now()) : null,
                'address' => trim(($pt->address ?? '').($pt && $pt->city ? ', '.$pt->city : ''), ', ') ?: null],
            'consultation' => $c ? ['chief_complaint' => $c->chief_complaint, 'vitals' => $c->vitals ? json_decode($c->vitals, true) : null, 'diagnosis' => $c->diagnosis,
                'notes' => $c->notes_encrypted ? Crypt::decryptString($c->notes_encrypted) : null, 'advice' => $c->advice, 'follow_up_date' => $c->follow_up_date] : null,
            'prescriptions' => $rx, 'lab_orders' => $labs, 'payments' => $payments,
            'hospital' => self::letterhead(),
        ];
    }

    /** The hospital's name, address and phone for the top of printed slips. */
    private static function letterhead(): array
    {
        $addr = (array) \App\Support\SettingsRegistry::value('hospital.address', []);

        return ['name' => (string) \App\Support\SettingsRegistry::value('hospital.name', config('app.name')), 'phone' => (string) \App\Support\SettingsRegistry::value('hospital.phone', ''),
            'address' => implode(', ', array_filter([$addr['line1'] ?? '', $addr['line2'] ?? '', $addr['city'] ?? '', $addr['state'] ?? '', $addr['pincode'] ?? '']))];
    }

    // ---------- lookups and lists ----------

    /** Active doctors with their fee, for the registration screen. */
    public function doctors(): JsonResponse
    {
        $rows = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.status', 'active')->whereNull('d.deleted_at')->orderBy('u.full_name')
            ->get(['d.id', 'u.full_name', 'd.specialization', 'd.consultation_fee']);

        return response()->json($rows->map(fn ($d) => ['id' => $d->id, 'name' => $d->full_name, 'specialization' => $d->specialization, 'fee' => self::money($d->consultation_fee)])->values());
    }

    private static function filtered(Request $request)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'doctor_id' => 'nullable|integer',
            'patient_id' => 'nullable|integer', 'status' => ['nullable', Rule::in(self::STATUSES)], 'q' => 'nullable|string|max:100']);
        $q = self::base();
        if (($own = self::ownDoctorScope($request)) !== null) {
            $q->where('v.doctor_id', $own);
        }
        if ($d = $request->query('date')) {
            $q->where('v.visit_date', $d);
        }
        if ($f = $request->query('from')) {
            $q->where('v.visit_date', '>=', $f);
        }
        if ($t = $request->query('to')) {
            $q->where('v.visit_date', '<=', $t);
        }
        if ($did = $request->query('doctor_id')) {
            $q->where('v.doctor_id', (int) $did);
        }
        if ($pid = $request->query('patient_id')) {
            $q->where('v.patient_id', (int) $pid);
        }
        if ($s = $request->query('status')) {
            $q->where('v.status', $s);
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('p.full_name', 'like', "%$term%")->orWhere('p.patient_code', 'like', "%$term%")->orWhere('du.full_name', 'like', "%$term%")
                ->orWhere('p.mobile', 'like', '%'.preg_replace('/\D/', '', $term).'%'));
        }

        return $q;
    }

    public function index(Request $request): JsonResponse
    {
        self::syncFor($request);
        return Paging::respond($request, self::filtered($request)->orderBy('v.visit_date', 'desc')->orderBy('v.doctor_id')->orderBy('v.token_number'), fn ($v) => self::rowOut($v));
    }

    /** Totals for the day (or range): how many patients, what is waiting, what was billed and collected. */
    public function summary(Request $request): JsonResponse
    {
        self::syncFor($request);
        $rows = self::filtered($request)->get();
        $live = $rows->where('status', '!=', 'cancelled');

        return response()->json([
            'visits' => $live->count(), 'waiting' => $rows->where('status', 'waiting')->count(), 'in_consultation' => $rows->where('status', 'in_consultation')->count(),
            'completed' => $rows->where('status', 'completed')->count(), 'cancelled' => $rows->where('status', 'cancelled')->count(),
            'billed' => self::money($live->sum('total_amount')), 'collected' => self::money($live->sum('paid_amount')), 'lab_orders' => (int) $live->sum('lab_count'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        self::syncFor($request);
        $rows = self::filtered($request)->orderBy('v.visit_date')->orderBy('v.doctor_id')->orderBy('v.token_number')->get();
        Audit::record('export', 'opd_register', null, ['rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Date', 'Token', 'Patient', 'Patient ID', 'Mobile', 'Doctor', 'Source', 'Status', 'Fee', 'Billed', 'Collected', 'Lab orders']);
            foreach ($rows as $v) {
                fputcsv($o, [$v->visit_date, $v->token_number, $v->patient_name, $v->patient_code, $v->patient_mobile, $v->doctor_name, $v->source, $v->status, self::money($v->fee),
                    self::money($v->total_amount), self::money($v->paid_amount), $v->lab_count]);
            }
            fclose($o);
        }, 'opd-register.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, int $visit): JsonResponse
    {
        $row = DB::table('opd_visits')->find($visit) ?? abort(404, 'Visit not found');
        if (($own = self::ownDoctorScope($request)) !== null && (int) $row->doctor_id !== $own) {
            abort(404, 'Visit not found');
        }

        return response()->json(self::fullOut($visit));
    }

    // ---------- register a visit ----------

    public function store(Request $request): JsonResponse
    {
        $request->merge(['patient_mobile' => $request->filled('patient_mobile') ? \App\Support\Phone::normalize((string) $request->input('patient_mobile')) : null]);
        $d = $request->validate([
            'appointment_id' => ['nullable', 'integer'], 'patient_id' => ['nullable', 'integer'], 'patient_name' => ['nullable', 'string', 'min:2', 'max:150'],
            'patient_mobile' => ['nullable', 'regex:/^[6-9]\d{9}$/'], 'doctor_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string', 'max:500'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'payment' => ['nullable', 'array'], 'payment.amount' => ['required_with:payment', 'numeric', 'gt:0'], 'payment.method' => ['required_with:payment', Rule::in(LabController::METHODS)], 'payment.reference_no' => ['nullable', 'string', 'max:100'],
        ], ['patient_mobile.regex' => 'Enter a valid 10-digit Indian mobile number']);
        if (! empty($d['payment'])) {
            $request->user()->can('payments.collect') || abort(403, 'You do not have permission to perform this action');
        }

        $id = DB::transaction(function () use ($d, $request) {
            $appt = null;
            if (! empty($d['appointment_id'])) {
                $appt = DB::table('appointments')->where('id', $d['appointment_id'])->lockForUpdate()->first() ?? abort(404, 'Appointment not found');
                $appt->patient_id !== null || abort(409, 'This booking has no patient record yet. It is created when the advance is paid.');
                in_array($appt->status, ['pending', 'confirmed', 'checked_in'], true) || abort(409, 'This appointment is '.str_replace('_', ' ', $appt->status));
                $appt->payment_status !== 'awaiting_payment' || abort(409, 'The patient is still paying the online advance');
                $appt->payment_status !== 'refund_due' || abort(409, 'This payment is waiting to be refunded');
                DB::table('opd_visits')->where('appointment_id', $appt->id)->exists() && abort(409, 'A visit has already been started for this appointment');
                $patientId = (int) $appt->patient_id;
                $doctorId = (int) $appt->doctor_id;
                $departmentId = $appt->department_id;
                $fee = self::money($appt->consultation_fee);
            } else {
                $doctorId = (int) ($d['doctor_id'] ?? 0) ?: abort(422, 'Choose the doctor');
                $doc = DB::table('doctors')->where('id', $doctorId)->where('status', 'active')->whereNull('deleted_at')->first() ?? abort(400, 'Unknown doctor');
                $fee = self::money($doc->consultation_fee);
                $departmentId = DB::table('doctor_departments')->where('doctor_id', $doctorId)->orderByDesc('is_primary')->value('department_id');
                $patientId = $d['patient_id'] ?? null;
                if ($patientId) {
                    DB::table('patients')->where('id', $patientId)->whereNull('deleted_at')->exists() || abort(400, 'Unknown patient');
                } else {
                    filled($d['patient_name'] ?? null) || abort(422, 'Choose a registered patient or enter the patient name');
                    $patientId = DB::table('patients')->insertGetId(['patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'full_name' => trim($d['patient_name']), 'mobile' => $d['patient_mobile'] ?? null,
                        'registered_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('patients')->where('id', $patientId)->update(['patient_code' => Patient::codeFor($patientId)]);
                    Audit::record('create', 'patient', $patientId, ['via' => 'opd_visit']);
                }
            }
            $visitId = self::makeVisit((int) $patientId, $doctorId, $departmentId, $fee, self::money($d['discount'] ?? 0), $appt, $d['reason'] ?? ($appt->reason ?? null), $request->user()->id, $d['payment'] ?? null);
            if ($appt) { // the booking is checked in as the visit starts
                $appt->status === 'pending' && BookingService::transition($appt->id, 'confirmed', $request->user()->id, 'OPD visit started');
                $appt->status !== 'checked_in' && BookingService::transition($appt->id, 'checked_in', $request->user()->id, 'OPD visit started');
            }
            Audit::record('create', 'opd_visit', $visitId, ['source' => $appt ? 'appointment' : 'walk_in']);

            return $visitId;
        });

        return response()->json(self::fullOut($id), 201);
    }

    /** Token, visit row and fee bill for one patient with one doctor today; shared by the desk and by booked appointments. */
    private static function makeVisit(int $patientId, int $doctorId, ?int $departmentId, float $fee, float $discount, ?object $appt, ?string $reason, ?int $userId, ?array $payment, ?string $date = null): int
    {
        $discount <= $fee || abort(422, 'The discount cannot be more than the fee');
        $total = $fee - $discount;

        $today = $date ?? Hospital::today();
        $token = 1 + (int) DB::table('opd_visits')->where('visit_date', $today)->where('doctor_id', $doctorId)->lockForUpdate()->max('token_number');
        $visitId = DB::table('opd_visits')->insertGetId(['visit_date' => $today, 'token_number' => $token, 'patient_id' => $patientId, 'doctor_id' => $doctorId, 'department_id' => $departmentId,
            'appointment_id' => $appt?->id, 'source' => $appt ? 'appointment' : 'walk_in', 'status' => 'waiting', 'fee' => $total, 'reason' => $reason,
            'created_by_user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        if ($fee > 0) {
            $billId = DB::table('bills')->insertGetId(['bill_number' => 'TMP-'.bin2hex(random_bytes(6)), 'patient_id' => $patientId, 'appointment_id' => $appt?->id, 'bill_date' => $today, 'subtotal' => $fee,
                'discount_amount' => $discount, 'total_amount' => $total, 'paid_amount' => 0, 'status' => $total > 0 ? 'pending' : 'paid', 'notes' => 'OPD visit, token '.$token,
                'created_by_user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bills')->where('id', $billId)->update(['bill_number' => sprintf('BILL-%s-%05d', date('ymd'), $billId)]);
            $doctorName = DB::table('doctors as dd')->join('users as uu', 'uu.id', '=', 'dd.user_id')->where('dd.id', $doctorId)->value('uu.full_name');
            DB::table('bill_items')->insert(['bill_id' => $billId, 'item_type' => 'consultation', 'description' => 'OPD consultation'.($doctorName ? ' - '.$doctorName : ''), 'quantity' => 1, 'unit_price' => $fee,
                'line_total' => $fee, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('opd_visits')->where('id', $visitId)->update(['bill_id' => $billId]);
            // An online advance that is already paid counts towards the fee.
            $advance = $appt && $appt->payment_status === 'advance_paid' ? min($total, self::money($appt->advance_amount)) : 0;
            $advance > 0 && LabController::pay($billId, $advance, 'upi', $appt->payment_reference, $userId);
            if (! empty($payment)) {
                self::money($payment['amount']) <= $total - $advance || abort(422, 'The payment cannot be more than the amount due');
                LabController::pay($billId, (float) $payment['amount'], $payment['method'], $payment['reference_no'] ?? null, $userId);
            }
            $paid = (float) DB::table('bills')->where('id', $billId)->value('paid_amount');
            $appt && DB::table('appointments')->where('id', $appt->id)->update(['payment_status' => $paid >= $total ? 'paid' : $appt->payment_status, 'paid_at' => $paid >= $total ? now() : $appt->paid_at, 'updated_at' => now()]);
        }

        return $visitId;
    }

    /**
     * Booked appointments are OPD patients: every live appointment dated today or later gets its visit (token and fee bill,
     * with any online advance already counted), and a waiting visit whose appointment was cancelled is cancelled with it.
     * Called whenever the register is read, so nothing has to be entered twice.
     */
    public static function syncAppointments(?string $from = null, ?string $to = null): void
    {
        $today = Hospital::today();
        $from = max($from ?: $today, $today);
        $to = min($to ?: $from, Carbon::parse($from)->addDays(62)->toDateString());
        if ($to < $from) {
            return;
        }
        $due = DB::table('appointments as a')->leftJoin('opd_visits as v', 'v.appointment_id', '=', 'a.id')->whereNull('v.id')
            ->whereBetween('a.appointment_date', [$from, $to])->whereIn('a.status', ['pending', 'confirmed', 'checked_in'])->whereNotNull('a.patient_id')
            ->whereNotIn('a.payment_status', ['awaiting_payment', 'refund_due'])->orderBy('a.appointment_date')->orderBy('a.start_time')->orderBy('a.id')->pluck('a.id');
        foreach ($due as $id) {
            try {
                DB::transaction(function () use ($id) {
                    $a = DB::table('appointments')->where('id', $id)->lockForUpdate()->first();
                    if (! $a || DB::table('opd_visits')->where('appointment_id', $id)->exists() || ! in_array($a->status, ['pending', 'confirmed', 'checked_in'], true)) {
                        return;
                    }
                    $visit = self::makeVisit((int) $a->patient_id, (int) $a->doctor_id, $a->department_id ? (int) $a->department_id : null, self::money($a->consultation_fee), 0, $a, $a->reason, null, null, $a->appointment_date);
                    Audit::record('create', 'opd_visit', $visit, ['source' => 'appointment', 'auto' => true]);
                });
            } catch (\Illuminate\Database\QueryException) {
                // another request created it a moment ago
            }
        }
        DB::table('opd_visits as v')->join('appointments as a', 'a.id', '=', 'v.appointment_id')->where('v.status', 'waiting')->whereIn('a.status', ['cancelled', 'rescheduled', 'no_show', 'expired'])
            ->whereBetween('v.visit_date', [$from, $to])->update(['v.status' => 'cancelled', 'v.cancelled_at' => now(), 'v.cancel_reason' => 'The appointment was cancelled or moved', 'v.updated_at' => now()]);
    }

    private static function syncFor(Request $request): void
    {
        $date = $request->query('date');
        self::syncAppointments($date ?: $request->query('from'), $date ?: $request->query('to'));
    }

    /** Send an OPD patient into the IPD register: the same person, doctor and department, with the case carried over. */
    public function admit(Request $request, int $visit): JsonResponse
    {
        $d = $request->validate([
            'admission_date' => ['nullable', 'date_format:Y-m-d'], 'bed_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
            'doctor_ids' => ['nullable', 'array', 'max:10'], 'doctor_ids.*' => ['integer', 'distinct'], 'case_title' => ['nullable', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:2000'],
            'admission_time' => ['nullable', 'date_format:H:i'], 'admission_type' => ['nullable', Rule::in(['emergency', 'planned', 'transfer'])], 'room' => ['nullable', 'string', 'max:30'],
            'attendant_name' => ['nullable', 'string', 'max:150'], 'attendant_mobile' => ['nullable', 'regex:/^[6-9]\d{9}$/'], 'attendant_relation' => ['nullable', 'string', 'max:60'],
        ], ['attendant_mobile.regex' => 'Enter a valid 10-digit Indian mobile number']);
        $admission = DB::transaction(function () use ($request, $visit, $d) {
            $v = self::visitFor($request, $visit);
            $v->status === 'cancelled' && abort(409, 'A cancelled visit cannot be admitted');
            DB::table('ipd_admissions')->where('opd_visit_id', $visit)->whereNull('deleted_at')->exists() && abort(409, 'This patient has already been admitted from this visit');
            DB::table('ipd_admissions')->where('patient_id', $v->patient_id)->where('status', 'admitted')->whereNull('deleted_at')->exists() && abort(409, 'This patient is already admitted in the IPD register');
            $c = DB::table('consultations')->where('opd_visit_id', $visit)->first();
            $id = IpdController::admitFromVisit($v, $d + [
                'doctor_ids' => [(int) $v->doctor_id], 'department_id' => $v->department_id,
                'case_title' => ($c->diagnosis ?? null) ?: ($v->reason ? mb_substr($v->reason, 0, 200) : null),
            ], $request->user()->id);
            // The check-up is over once the patient is admitted.
            $v->status === 'waiting' && self::advance($v, 'in_consultation', $request->user()->id);
            $v->status !== 'completed' && self::advance(DB::table('opd_visits')->find($visit), 'completed', $request->user()->id);
            Audit::record('admit', 'opd_visit', $visit, ['admission_id' => $id]);

            return $id;
        });

        return response()->json(self::fullOut($visit) + ['admission_id' => $admission], 201);
    }

    // ---------- the visit itself ----------

    private static function visitFor(Request $request, int $id): object
    {
        $v = DB::table('opd_visits')->where('id', $id)->lockForUpdate()->first() ?? abort(404, 'Visit not found');
        if (($own = self::ownDoctorScope($request)) !== null && (int) $v->doctor_id !== $own) {
            abort(404, 'Visit not found');
        }

        return $v;
    }

    /** Vitals can be taken by a nurse; the diagnosis and prescription are the doctor's. */
    public function vitals(Request $request, int $visit): JsonResponse
    {
        $d = $request->validate(['bp' => 'nullable|string|max:20', 'pulse' => 'nullable|integer|min:20|max:250', 'temperature' => 'nullable|numeric|min:30|max:45', 'spo2' => 'nullable|integer|min:30|max:100',
            'weight' => 'nullable|numeric|min:0.5|max:400', 'height' => 'nullable|numeric|min:20|max:260', 'respiratory_rate' => 'nullable|integer|min:4|max:80']);
        DB::transaction(function () use ($request, $visit, $d) {
            $v = self::visitFor($request, $visit);
            in_array($v->status, ['cancelled'], true) && abort(409, 'This visit was cancelled');
            self::sheet($v)->update(['vitals' => json_encode(array_filter($d, fn ($x) => $x !== null && $x !== '')), 'updated_at' => now()]);
            Audit::record('update', 'opd_vitals', $visit);
        });

        return response()->json(self::fullOut($visit));
    }

    public function consultation(Request $request, int $visit): JsonResponse
    {
        $d = $request->validate([
            'chief_complaint' => 'nullable|string|max:2000', 'diagnosis' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:5000', 'advice' => 'nullable|string|max:2000', 'follow_up_date' => 'nullable|date_format:Y-m-d',
            'prescriptions' => 'nullable|array|max:30', 'prescriptions.*.medicine_name' => 'required|string|min:2|max:200', 'prescriptions.*.dosage' => 'nullable|string|max:100',
            'prescriptions.*.frequency' => 'nullable|string|max:100', 'prescriptions.*.duration' => 'nullable|string|max:100', 'prescriptions.*.route' => 'nullable|string|max:50', 'prescriptions.*.instructions' => 'nullable|string|max:500',
        ]);
        DB::transaction(function () use ($request, $visit, $d) {
            $v = self::visitFor($request, $visit);
            $v->status === 'cancelled' && abort(409, 'This visit was cancelled');
            $c = self::sheet($v);
            $c->update(['chief_complaint' => $d['chief_complaint'] ?? null, 'diagnosis' => $d['diagnosis'] ?? null, 'advice' => $d['advice'] ?? null, 'follow_up_date' => $d['follow_up_date'] ?? null,
                'notes_encrypted' => filled($d['notes'] ?? null) ? Crypt::encryptString($d['notes']) : null, 'updated_at' => now()]);
            if (array_key_exists('prescriptions', $d)) {
                $cid = DB::table('consultations')->where('opd_visit_id', $v->id)->value('id');
                DB::table('prescriptions')->where('consultation_id', $cid)->delete();
                foreach ($d['prescriptions'] ?? [] as $r) {
                    DB::table('prescriptions')->insert(['consultation_id' => $cid, 'medicine_name' => trim($r['medicine_name']), 'dosage' => $r['dosage'] ?? null, 'frequency' => $r['frequency'] ?? null,
                        'duration' => $r['duration'] ?? null, 'route' => $r['route'] ?? null, 'instructions' => $r['instructions'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $v->status === 'waiting' && self::advance($v, 'in_consultation', $request->user()->id);
            Audit::record('update', 'opd_consultation', $visit);
        });

        return response()->json(self::fullOut($visit));
    }

    /** The consultation row for a visit, created on first use. */
    private static function sheet(object $v): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('consultations')->where('opd_visit_id', $v->id);
        $q->exists() || DB::table('consultations')->insert(['opd_visit_id' => $v->id, 'appointment_id' => $v->appointment_id, 'doctor_id' => $v->doctor_id, 'patient_id' => $v->patient_id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('consultations')->where('opd_visit_id', $v->id);
    }

    /** Move the visit on, keeping a linked appointment in step. */
    private static function advance(object $v, string $to, int $userId): void
    {
        $set = ['status' => $to, 'updated_at' => now()];
        $to === 'in_consultation' && $set['started_at'] = now();
        $to === 'completed' && $set['completed_at'] = now();
        DB::table('opd_visits')->where('id', $v->id)->update($set);
        if ($v->appointment_id) {
            $a = DB::table('appointments')->find($v->appointment_id);
            if ($a && $to === 'in_consultation') { // an auto-listed booking is still pending or confirmed until the patient is seen
                $a->status === 'pending' && BookingService::transition($a->id, 'confirmed', $userId, 'OPD visit');
                in_array($a->status, ['pending', 'confirmed'], true) && BookingService::transition($a->id, 'checked_in', $userId, 'OPD visit');
                $a = DB::table('appointments')->find($v->appointment_id);
            }
            $a && in_array($to, BookingService::TRANSITIONS[$a->status] ?? [], true) && BookingService::transition($a->id, $to, $userId, 'OPD visit');
        }
    }

    public function setStatus(Request $request, int $visit): JsonResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(['in_consultation', 'completed'])]]);
        DB::transaction(function () use ($request, $visit, $d) {
            $v = self::visitFor($request, $visit);
            $ok = ['waiting' => ['in_consultation', 'completed'], 'in_consultation' => ['completed']];
            in_array($d['status'], $ok[$v->status] ?? [], true) || abort(409, 'A '.str_replace('_', ' ', $v->status).' visit cannot be marked '.str_replace('_', ' ', $d['status']));
            $v->status === 'waiting' && $d['status'] === 'completed' && self::advance($v, 'in_consultation', $request->user()->id);
            self::advance(DB::table('opd_visits')->find($visit), $d['status'], $request->user()->id);
            Audit::record('status', 'opd_visit', $visit, ['to' => $d['status']]);
        });

        return response()->json(self::fullOut($visit));
    }

    public function cancel(Request $request, int $visit): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        DB::transaction(function () use ($request, $visit, $d) {
            $v = self::visitFor($request, $visit);
            in_array($v->status, ['completed', 'cancelled'], true) && abort(409, 'A '.$v->status.' visit cannot be cancelled');
            DB::table('lab_orders')->where('opd_visit_id', $visit)->where('status', '!=', 'cancelled')->exists() && abort(409, 'Cancel this visit\'s lab orders in the Lab section first');
            if ($v->bill_id) {
                $b = DB::table('bills')->where('id', $v->bill_id)->lockForUpdate()->first();
                $refund = self::money($b->paid_amount);
                $refund > 0 && ($request->user()->can('payments.refund') || abort(403, 'Only someone allowed to refund can cancel a visit that has been paid'));
                if ($refund > 0) {
                    $pid = DB::table('payments')->insertGetId(['receipt_number' => 'TMP-'.bin2hex(random_bytes(6)), 'bill_id' => $b->id, 'amount' => -$refund, 'method' => 'refund', 'status' => 'success',
                        'paid_at' => now(), 'received_by_user_id' => $request->user()->id, 'notes' => 'OPD visit cancelled: '.$d['reason'], 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('payments')->where('id', $pid)->update(['receipt_number' => sprintf('RCT-%s-%05d', date('ymd'), $pid)]);
                }
                DB::table('bills')->where('id', $b->id)->update(['paid_amount' => 0, 'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $d['reason'], 'updated_at' => now()]);
            }
            DB::table('opd_visits')->where('id', $visit)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $d['reason'], 'updated_at' => now()]);
            Audit::record('cancel', 'opd_visit', $visit, ['reason' => $d['reason']]);
        });

        return response()->json(self::fullOut($visit));
    }

    public function addPayment(Request $request, int $visit): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', Rule::in(LabController::METHODS)], 'reference_no' => ['nullable', 'string', 'max:100']]);
        DB::transaction(function () use ($request, $visit, $d) {
            $v = self::visitFor($request, $visit);
            $v->status === 'cancelled' && abort(409, 'This visit was cancelled');
            $v->bill_id || abort(409, 'This visit has no fee to collect');
            $payment = LabController::pay($v->bill_id, (float) $d['amount'], $d['method'], $d['reference_no'] ?? null, $request->user()->id);
            Audit::record('payment', 'opd_visit', $visit, ['payment_id' => $payment, 'amount' => self::money($d['amount']), 'method' => $d['method']]);
            if ($v->appointment_id) {
                $b = DB::table('bills')->find($v->bill_id);
                $b->paid_amount >= $b->total_amount && DB::table('appointments')->where('id', $v->appointment_id)->update(['payment_status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
            }
        });

        return response()->json(self::fullOut($visit), 201);
    }
}
