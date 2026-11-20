<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Support\Audit;
use App\Support\Hospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The in-patient (IPD) register: admissions, and dated money entries under configurable heads. */
class IpdController extends Controller
{
    public const HEAD_TYPES = ['receipt', 'payout', 'charge'];

    public const STATUSES = ['admitted', 'discharged'];

    public const PAYMENT_MODES = ['cash', 'upi', 'card', 'cheque', 'other'];

    /** The columns of the original spreadsheet. Admins can add or retype heads later. */
    public const DEFAULT_HEADS = [
        ['cash', 'Cash', 'receipt'], ['upi', 'UPI', 'receipt'], ['collection', 'Collection', 'receipt'],
        ['doctor_fee', 'Doctor fee', 'payout'], ['anaesthesia', 'Anaesthesia', 'payout'], ['extra_expenses', 'Extra expenses', 'payout'],
        ['card', 'Card', 'receipt'], ['implant', 'Implant', 'charge'], ['extra_payment', 'Extra payment', 'charge'],
        ['xray', 'X-ray', 'charge'], ['usg', 'USG', 'charge'], ['ecg', 'ECG', 'charge'],
        ['lab', 'Lab tests', 'charge'], // filled in automatically when the lab tests an admitted patient
    ];

    private static function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    /** @return array{0: string, 1: string} first day and last day of a YYYY-MM month */
    private static function monthBounds(Request $request): array
    {
        $d = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'], 'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
        ]);
        // A custom date range wins over the month (up to a year, so a screen never loads the whole history).
        if (! empty($d['from']) && ! empty($d['to'])) {
            if ($d['to'] < $d['from'] || Carbon::parse($d['from'])->diffInDays(Carbon::parse($d['to'])) > 366) {
                abort(422, 'Choose a date range of up to one year, ending on or after it starts');
            }

            return [$d['from'], $d['to'], $d['from'].'_'.$d['to']];
        }
        $month = $d['month'] ?? Hospital::now()->format('Y-m');
        $first = Carbon::createFromFormat('!Y-m', $month);

        return [$first->toDateString(), $first->copy()->endOfMonth()->toDateString(), $month];
    }

    // ---------- heads ----------

    private static function headOut(object $h): array
    {
        return ['id' => $h->id, 'code' => $h->code, 'name' => $h->name, 'type' => $h->type, 'display_order' => (int) $h->display_order, 'is_active' => (bool) $h->is_active];
    }

    public function heads(): JsonResponse
    {
        return response()->json(DB::table('ipd_heads')->orderBy('display_order')->orderBy('id')->get()->map(fn ($h) => self::headOut($h)));
    }

    public function storeHead(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'min:2', 'max:60'], 'type' => ['required', Rule::in(self::HEAD_TYPES)]]);
        $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($d['name'])), '_') ?: 'head';
        if (DB::table('ipd_heads')->where('code', $code)->exists() || DB::table('ipd_heads')->whereRaw('lower(name) = ?', [mb_strtolower($d['name'])])->exists()) {
            abort(409, 'A column with this name already exists');
        }
        $id = DB::table('ipd_heads')->insertGetId(['code' => $code, 'name' => trim($d['name']), 'type' => $d['type'], 'display_order' => 1 + (int) DB::table('ipd_heads')->max('display_order'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('create', 'ipd_head', $id, ['name' => $d['name'], 'type' => $d['type']]);

        return response()->json(self::headOut(DB::table('ipd_heads')->find($id)), 201);
    }

    public function updateHead(Request $request, int $head): JsonResponse
    {
        $row = DB::table('ipd_heads')->find($head) ?? abort(404, 'Column not found');
        $d = $request->validate(['name' => ['required', 'string', 'min:2', 'max:60'], 'type' => ['required', Rule::in(self::HEAD_TYPES)], 'display_order' => ['nullable', 'integer', 'min:0', 'max:999'], 'is_active' => ['nullable', 'boolean']]);
        if (DB::table('ipd_heads')->where('id', '!=', $head)->whereRaw('lower(name) = ?', [mb_strtolower($d['name'])])->exists()) {
            abort(409, 'A column with this name already exists');
        }
        DB::table('ipd_heads')->where('id', $head)->update(['name' => trim($d['name']), 'type' => $d['type'], 'display_order' => $d['display_order'] ?? $row->display_order, 'is_active' => $d['is_active'] ?? $row->is_active, 'updated_at' => now()]);
        Audit::record('update', 'ipd_head', $head, ['before' => ['name' => $row->name, 'type' => $row->type], 'after' => ['name' => $d['name'], 'type' => $d['type']]]);

        return response()->json(self::headOut(DB::table('ipd_heads')->find($head)));
    }

    // ---------- admissions ----------

    private static function entryOut(object $e): array
    {
        return ['id' => $e->id, 'head_id' => $e->head_id, 'entry_date' => $e->entry_date, 'amount' => self::money($e->amount), 'payment_mode' => $e->payment_mode, 'receipt_no' => $e->receipt_no, 'note' => $e->note, 'lab_order_id' => $e->lab_order_id];
    }

    /** @param \Illuminate\Support\Collection $rows @return list<array<string, mixed>> */
    private static function admissionsOut($rows): array
    {
        $heads = DB::table('ipd_heads')->get()->keyBy('id');
        $entries = DB::table('ipd_entries')->whereIn('admission_id', $rows->pluck('id'))->orderBy('entry_date')->orderBy('id')->get()->groupBy('admission_id');
        $linked = self::linkedDoctors($rows->pluck('id')->all());

        return $rows->map(function ($a) use ($entries, $heads, $linked) {
            $list = $entries->get($a->id, collect());
            $byHead = [];
            $sum = ['receipt' => 0.0, 'payout' => 0.0, 'charge' => 0.0];
            foreach ($list as $e) {
                $byHead[$e->head_id] = self::money(($byHead[$e->head_id] ?? 0) + $e->amount);
                $sum[$heads[$e->head_id]->type ?? 'charge'] += (float) $e->amount;
            }

            return [
                'id' => $a->id, 'admission_date' => $a->admission_date, 'patient_id' => $a->patient_id, 'patient_code' => $a->patient_code ?? null, 'patient_name' => $a->patient_name,
                'bed' => $a->bed, 'bed_id' => $a->bed_id, 'department_id' => $a->department_id, 'department' => $a->department_name ?? null, 'case_title' => $a->case_title,
                'doctor_ids' => collect($linked[$a->id] ?? [])->pluck('id')->all(), 'doctor_list' => $linked[$a->id] ?? [], 'other_doctors' => $a->doctors, 'doctors' => self::doctorsText($a, $linked),
                'anaesthetist_id' => $a->anaesthetist_id, 'anaesthetist' => $a->anaesthetist, 'status' => $a->status, 'discharge_date' => $a->discharge_date, 'notes' => $a->notes,
                'entries' => $list->map(fn ($e) => self::entryOut($e))->values(), 'totals' => (object) $byHead,
                'received' => self::money($sum['receipt']), 'payouts' => self::money($sum['payout']), 'charges' => self::money($sum['charge']),
                'balance' => self::money($sum['receipt'] - $sum['payout']),
            ];
        })->values()->all();
    }

    private static function baseAdmissions()
    {
        return DB::table('ipd_admissions as a')->leftJoin('patients as p', 'p.id', '=', 'a.patient_id')->leftJoin('departments as dp', 'dp.id', '=', 'a.department_id')
            ->whereNull('a.deleted_at')->select('a.*', 'p.patient_code', 'dp.name as department_name');
    }

    /** @param list<int> $ids @return array<int, list<array{id: int, name: string}>> registered doctors treating each admission */
    private static function linkedDoctors(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('ipd_admission_doctors as x')->join('doctors as d', 'd.id', '=', 'x.doctor_id')->join('users as u', 'u.id', '=', 'd.user_id')
            ->whereIn('x.admission_id', $ids)->orderBy('u.full_name')->get(['x.admission_id', 'd.id', 'u.full_name']) as $r) {
            $out[$r->admission_id][] = ['id' => $r->id, 'name' => $r->full_name];
        }

        return $out;
    }

    /** Registered doctors' names first, then any free-text names (visiting doctors). */
    private static function doctorsText(object $a, array $linked): ?string
    {
        $names = array_column($linked[$a->id] ?? [], 'name');
        if (filled($a->doctors)) {
            $names[] = $a->doctors;
        }

        return $names === [] ? null : implode(', ', $names);
    }

    /** Admissions admitted in the month, plus any with money entries dated in the month. */
    private static function monthQuery(string $from, string $to)
    {
        return self::baseAdmissions()->where(fn ($w) => $w->whereBetween('a.admission_date', [$from, $to])
            ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('ipd_entries as e')->whereColumn('e.admission_id', 'a.id')->whereBetween('e.entry_date', [$from, $to])));
    }

    /** Day-by-day view: for every date, who was admitted and which money entries were made that day. */
    public function days(Request $request): JsonResponse
    {
        [$from, $to, $month] = self::monthBounds($request);
        $heads = DB::table('ipd_heads')->get()->keyBy('id');
        $days = [];
        $blank = fn () => ['admissions' => [], 'entries' => [], 'received' => 0.0, 'payouts' => 0.0, 'charges' => 0.0];

        $dayRows = self::baseAdmissions()->whereBetween('a.admission_date', [$from, $to])->orderBy('a.admission_date')->orderBy('a.id')->get();
        $linked = self::linkedDoctors($dayRows->pluck('id')->all());
        foreach ($dayRows as $a) {
            $days[$a->admission_date] ??= $blank();
            $days[$a->admission_date]['admissions'][] = ['id' => $a->id, 'patient_id' => $a->patient_id, 'patient_name' => $a->patient_name, 'bed' => $a->bed, 'department' => $a->department_name,
                'case_title' => $a->case_title, 'doctors' => self::doctorsText($a, $linked), 'doctor_list' => $linked[$a->id] ?? [], 'other_doctors' => $a->doctors, 'anaesthetist' => $a->anaesthetist, 'anaesthetist_id' => $a->anaesthetist_id, 'status' => $a->status];
        }
        $entries = DB::table('ipd_entries as e')->join('ipd_admissions as a', 'a.id', '=', 'e.admission_id')->whereNull('a.deleted_at')->whereBetween('e.entry_date', [$from, $to])
            ->orderBy('e.entry_date')->orderBy('e.id')->get(['e.id', 'e.entry_date', 'e.head_id', 'e.amount', 'e.note', 'e.admission_id', 'a.patient_name', 'a.patient_id']);
        foreach ($entries as $e) {
            $h = $heads[$e->head_id] ?? null;
            $type = $h->type ?? 'charge';
            $days[$e->entry_date] ??= $blank();
            $days[$e->entry_date]['entries'][] = ['id' => $e->id, 'admission_id' => $e->admission_id, 'patient_id' => $e->patient_id, 'patient_name' => $e->patient_name, 'head_id' => $e->head_id,
                'head_name' => $h->name ?? '—', 'head_type' => $type, 'amount' => self::money($e->amount), 'note' => $e->note];
            $days[$e->entry_date][['receipt' => 'received', 'payout' => 'payouts', 'charge' => 'charges'][$type]] += (float) $e->amount;
        }
        ksort($days);

        return response()->json(['month' => $month, 'days' => collect($days)->map(fn ($d, $date) => ['date' => $date] + array_map(fn ($v) => is_float($v) ? self::money($v) : $v, $d))->values()]);
    }

    public function admissions(Request $request): JsonResponse
    {
        // One doctor's in-patient cases (their record's In-patient tab): as a treating doctor or as the anaesthetist.
        if ($request->filled('doctor_id')) {
            $docId = (int) $request->query('doctor_id');
            $rows = self::baseAdmissions()->where(fn ($w) => $w->where('a.anaesthetist_id', $docId)
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('ipd_admission_doctors as x')->whereColumn('x.admission_id', 'a.id')->where('x.doctor_id', $docId)))
                ->orderByDesc('a.admission_date')->orderByDesc('a.id')->limit(100)->get();

            return response()->json(['month' => null, 'items' => self::admissionsOut($rows)]);
        }
        // One person's full IPD history (for their patient record), regardless of month.
        if ($request->filled('patient_id')) {
            $rows = self::baseAdmissions()->where('a.patient_id', (int) $request->query('patient_id'))->orderByDesc('a.admission_date')->orderByDesc('a.id')->limit(100)->get();

            return response()->json(['month' => null, 'items' => self::admissionsOut($rows)]);
        }
        [$from, $to, $month] = self::monthBounds($request);
        $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)], 'q' => 'nullable|string|max:100']);
        $q = self::monthQuery($from, $to);
        if ($s = $request->query('status')) {
            $q->where('a.status', $s);
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('a.patient_name', 'like', "%$term%")->orWhere('a.case_title', 'like', "%$term%")->orWhere('a.doctors', 'like', "%$term%")->orWhere('a.anaesthetist', 'like', "%$term%")->orWhere('dp.name', 'like', "%$term%")
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('ipd_admission_doctors as x')->join('doctors as d', 'd.id', '=', 'x.doctor_id')->join('users as u', 'u.id', '=', 'd.user_id')->whereColumn('x.admission_id', 'a.id')->where('u.full_name', 'like', "%$term%"))->orWhere('a.bed', 'like', "%$term%"));
        }
        $rows = $q->orderBy('a.admission_date')->orderBy('a.id')->limit(500)->get();

        return response()->json(['month' => $month, 'items' => self::admissionsOut($rows)]);
    }

    public function showAdmission(int $admission): JsonResponse
    {
        $row = self::baseAdmissions()->where('a.id', $admission)->first() ?? abort(404, 'Admission not found');

        return response()->json(self::admissionsOut(collect([$row]))[0]);
    }

    private static function admissionRules(): array
    {
        return [
            'admission_date' => ['required', 'date_format:Y-m-d'], 'patient_id' => ['nullable', 'integer'], 'patient_name' => ['nullable', 'string', 'min:2', 'max:150'], 'patient_mobile' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'bed' => ['nullable', 'string', 'max:20'], 'bed_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
            'doctor_ids' => ['nullable', 'array', 'max:10'], 'doctor_ids.*' => ['integer', 'distinct'], 'anaesthetist_id' => ['nullable', 'integer'], 'case_title' => ['nullable', 'string', 'max:200'], 'doctors' => ['nullable', 'string', 'max:255'], 'anaesthetist' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(self::STATUSES)], 'discharge_date' => ['nullable', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private static function admissionFields(array $d, ?object $existing = null): array
    {
        // Every in-patient is a registered patient: choose one, or the typed name is registered as a new patient
        // (details pending) so the Patients page and the register always show the same people.
        $typed = trim((string) ($d['patient_name'] ?? ''));
        $patientId = $d['patient_id'] ?? null;
        if (! $patientId && $existing?->patient_id && mb_strtolower($typed) === mb_strtolower($existing->patient_name)) {
            $patientId = $existing->patient_id; // the same person, name unchanged
        }
        if ($patientId) {
            $p = DB::table('patients')->where('id', $patientId)->whereNull('deleted_at')->first() ?? abort(400, 'Unknown patient');
            $name = $p->full_name;
        } else {
            $typed !== '' || abort(422, 'Enter the patient name or choose a registered patient');
            $patientId = DB::table('patients')->insertGetId(['patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'full_name' => $typed, 'mobile' => $d['patient_mobile'] ?? null,
                'registered_by_user_id' => request()->user()?->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('patients')->where('id', $patientId)->update(['patient_code' => Patient::codeFor($patientId)]);
            Audit::record('create', 'patient', $patientId, ['via' => 'ipd_admission']);
            $name = $typed;
        }
        $status = $d['status'] ?? $existing->status ?? 'admitted';
        $discharge = $status === 'discharged' ? ($d['discharge_date'] ?? $existing->discharge_date ?? Hospital::today()) : null;
        if ($discharge !== null && $discharge < $d['admission_date']) {
            abort(422, 'The discharge date cannot be before the admission date');
        }

        // Bed: a registered bed (kept free of double booking); a typed label is matched to, or added as, a bed.
        $bedId = $d['bed_id'] ?? null;
        if (! $bedId && filled($d['bed'] ?? null)) {
            $code = strtoupper(trim($d['bed']));
            $bedId = DB::table('ipd_beds')->where('code', $code)->value('id')
                ?? DB::table('ipd_beds')->insertGetId(['code' => $code, 'display_order' => 1 + (int) DB::table('ipd_beds')->max('display_order'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $bed = $bedId ? (DB::table('ipd_beds')->find($bedId) ?? abort(400, 'Unknown bed')) : null;
        if ($bed && ! $bed->is_active && (int) $bed->id !== (int) ($existing->bed_id ?? 0)) {
            abort(422, "Bed {$bed->code} is switched off");
        }
        if ($bed && $status === 'admitted') {
            $taken = DB::table('ipd_admissions')->where('bed_id', $bed->id)->where('status', 'admitted')->whereNull('deleted_at')->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->value('patient_name');
            $taken === null || abort(409, "Bed {$bed->code} is occupied by $taken");
        }

        $departmentId = $d['department_id'] ?? null;
        $departmentId === null || DB::table('departments')->where('id', $departmentId)->whereNull('deleted_at')->exists() || abort(400, 'Unknown department');
        foreach (array_filter([...($d['doctor_ids'] ?? []), $d['anaesthetist_id'] ?? null]) as $docId) {
            DB::table('doctors')->where('id', $docId)->whereNull('deleted_at')->exists() || abort(400, 'Unknown doctor');
        }
        $anaesthetist = $d['anaesthetist'] ?? null;
        if (! empty($d['anaesthetist_id'])) {
            $anaesthetist = DB::table('doctors as dr')->join('users as u', 'u.id', '=', 'dr.user_id')->where('dr.id', $d['anaesthetist_id'])->value('u.full_name');
        }

        return ['admission_date' => $d['admission_date'], 'patient_id' => $patientId, 'patient_name' => trim($name), 'bed' => $bed?->code, 'bed_id' => $bed?->id, 'department_id' => $departmentId,
            'case_title' => $d['case_title'] ?? null, 'doctors' => $d['doctors'] ?? null, 'anaesthetist' => $anaesthetist, 'anaesthetist_id' => $d['anaesthetist_id'] ?? null,
            'status' => $status, 'discharge_date' => $discharge, 'notes' => $d['notes'] ?? null];
    }

    /** @param array<string, mixed> $d */
    private static function syncDoctors(int $admission, array $d): void
    {
        if (! array_key_exists('doctor_ids', $d)) {
            return;
        }
        DB::table('ipd_admission_doctors')->where('admission_id', $admission)->delete();
        foreach (array_unique($d['doctor_ids'] ?? []) as $docId) {
            DB::table('ipd_admission_doctors')->insert(['admission_id' => $admission, 'doctor_id' => $docId]);
        }
    }

    public function storeAdmission(Request $request): JsonResponse
    {
        $d = $request->validate(self::admissionRules());
        $id = DB::transaction(function () use ($d, $request) {
            $fields = self::admissionFields($d);
            $id = DB::table('ipd_admissions')->insertGetId($fields + ['created_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            self::syncDoctors($id, $d);

            return $id;
        });
        Audit::record('create', 'ipd_admission', $id, ['date' => $d['admission_date']]);

        return $this->showAdmission($id)->setStatusCode(201);
    }

    public function updateAdmission(Request $request, int $admission): JsonResponse
    {
        $row = DB::table('ipd_admissions')->where('id', $admission)->whereNull('deleted_at')->first() ?? abort(404, 'Admission not found');
        $d = $request->validate(self::admissionRules());
        $fields = [];
        DB::transaction(function () use ($admission, &$fields, $d, $row) {
            $fields = self::admissionFields($d, $row);
            DB::table('ipd_admissions')->where('id', $admission)->update($fields + ['updated_at' => now()]);
            self::syncDoctors($admission, $d);
        });
        Audit::record('update', 'ipd_admission', $admission, ['fields' => array_keys(array_diff_assoc($fields, (array) $row))]);

        return $this->showAdmission($admission);
    }

    public function deleteAdmission(int $admission): JsonResponse
    {
        DB::table('ipd_admissions')->where('id', $admission)->whereNull('deleted_at')->exists() || abort(404, 'Admission not found');
        $entries = DB::table('ipd_entries')->where('admission_id', $admission)->count();
        DB::table('ipd_admissions')->where('id', $admission)->update(['deleted_at' => now()]); // soft delete: the money entries stay on record
        Audit::record('delete', 'ipd_admission', $admission, ['entries' => $entries]);

        return response()->json(null, 204);
    }

    // ---------- entries ----------

    private static function entryRules(): array
    {
        return ['head_id' => ['required', 'integer'], 'entry_date' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'payment_mode' => ['nullable', Rule::in(self::PAYMENT_MODES)], 'receipt_no' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:255']];
    }

    /** Money received carries how it was paid; when not given, the column implies it (Cash, UPI, Card). */
    private static function paymentMode(array $d): ?string
    {
        $head = DB::table('ipd_heads')->find($d['head_id']);
        if ($head->type !== 'receipt') {
            return null;
        }

        return $d['payment_mode'] ?? (in_array($head->code, ['cash', 'upi', 'card'], true) ? $head->code : null);
    }

    private static function checkHead(int $id): void
    {
        DB::table('ipd_heads')->where('id', $id)->where('is_active', true)->exists() || abort(400, 'Choose a valid column');
    }

    public function addEntry(Request $request, int $admission): JsonResponse
    {
        DB::table('ipd_admissions')->where('id', $admission)->whereNull('deleted_at')->exists() || abort(404, 'Admission not found');
        $d = $request->validate(self::entryRules());
        self::checkHead($d['head_id']);
        $id = DB::table('ipd_entries')->insertGetId(['admission_id' => $admission, 'head_id' => $d['head_id'], 'entry_date' => $d['entry_date'], 'amount' => $d['amount'], 'payment_mode' => self::paymentMode($d), 'receipt_no' => $d['receipt_no'] ?? null, 'note' => $d['note'] ?? null,
            'created_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('create', 'ipd_entry', $id, ['admission_id' => $admission, 'head_id' => $d['head_id'], 'amount' => self::money($d['amount'])]);

        return $this->showAdmission($admission)->setStatusCode(201);
    }

    public function updateEntry(Request $request, int $entry): JsonResponse
    {
        $row = DB::table('ipd_entries')->find($entry) ?? abort(404, 'Entry not found');
        $row->lab_order_id === null || abort(409, 'This charge comes from a lab order. Change or cancel it in the Lab section.');
        $d = $request->validate(self::entryRules());
        self::checkHead($d['head_id']);
        DB::table('ipd_entries')->where('id', $entry)->update(['head_id' => $d['head_id'], 'entry_date' => $d['entry_date'], 'amount' => $d['amount'], 'payment_mode' => self::paymentMode($d), 'receipt_no' => $d['receipt_no'] ?? null, 'note' => $d['note'] ?? null, 'updated_at' => now()]);
        Audit::record('update', 'ipd_entry', $entry, ['before' => ['head_id' => $row->head_id, 'amount' => self::money($row->amount), 'date' => $row->entry_date], 'after' => ['head_id' => $d['head_id'], 'amount' => self::money($d['amount']), 'date' => $d['entry_date']]]);

        return $this->showAdmission($row->admission_id);
    }

    public function deleteEntry(int $entry): JsonResponse
    {
        $row = DB::table('ipd_entries')->find($entry) ?? abort(404, 'Entry not found');
        $row->lab_order_id === null || abort(409, 'This charge comes from a lab order. Cancel the lab order to remove it.');
        DB::table('ipd_entries')->where('id', $entry)->delete();
        Audit::record('delete', 'ipd_entry', $entry, ['admission_id' => $row->admission_id, 'head_id' => $row->head_id, 'amount' => self::money($row->amount), 'date' => $row->entry_date]);

        return response()->json(null, 204);
    }

    // ---------- beds and lookups ----------

    /** Every bed with who is in it right now. */
    private static function bedsOut(): array
    {
        $occupied = DB::table('ipd_admissions')->where('status', 'admitted')->whereNull('deleted_at')->whereNotNull('bed_id')->get(['id', 'bed_id', 'patient_name'])->keyBy('bed_id');

        return DB::table('ipd_beds as b')->leftJoin('departments as d', 'd.id', '=', 'b.department_id')->orderBy('b.display_order')->orderBy('b.code')
            ->get(['b.id', 'b.code', 'b.ward', 'b.department_id', 'd.name as department', 'b.display_order', 'b.is_active'])
            ->map(fn ($b) => ['id' => $b->id, 'code' => $b->code, 'ward' => $b->ward, 'department_id' => $b->department_id, 'department' => $b->department, 'display_order' => (int) $b->display_order,
                'is_active' => (bool) $b->is_active, 'occupied_by' => ($o = $occupied->get($b->id)) ? ['admission_id' => $o->id, 'patient_name' => $o->patient_name] : null])->all();
    }

    public function beds(): JsonResponse
    {
        return response()->json(self::bedsOut());
    }

    private static function bedRules(): array
    {
        return ['code' => ['required', 'string', 'min:1', 'max:20'], 'ward' => ['nullable', 'string', 'max:60'], 'department_id' => ['nullable', 'integer'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'], 'is_active' => ['nullable', 'boolean']];
    }

    public function storeBed(Request $request): JsonResponse
    {
        $d = $request->validate(self::bedRules());
        $code = strtoupper(trim($d['code']));
        DB::table('ipd_beds')->where('code', $code)->exists() && abort(409, 'A bed with this number already exists');
        ($d['department_id'] ?? null) === null || DB::table('departments')->where('id', $d['department_id'])->whereNull('deleted_at')->exists() || abort(400, 'Unknown department');
        $id = DB::table('ipd_beds')->insertGetId(['code' => $code, 'ward' => $d['ward'] ?? null, 'department_id' => $d['department_id'] ?? null,
            'display_order' => $d['display_order'] ?? 1 + (int) DB::table('ipd_beds')->max('display_order'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('create', 'ipd_bed', $id, ['code' => $code]);

        return response()->json(collect(self::bedsOut())->firstWhere('id', $id), 201);
    }

    public function updateBed(Request $request, int $bed): JsonResponse
    {
        $row = DB::table('ipd_beds')->find($bed) ?? abort(404, 'Bed not found');
        $d = $request->validate(self::bedRules());
        $code = strtoupper(trim($d['code']));
        DB::table('ipd_beds')->where('code', $code)->where('id', '!=', $bed)->exists() && abort(409, 'A bed with this number already exists');
        ($d['department_id'] ?? null) === null || DB::table('departments')->where('id', $d['department_id'])->whereNull('deleted_at')->exists() || abort(400, 'Unknown department');
        $active = $d['is_active'] ?? (bool) $row->is_active;
        if (! $active && DB::table('ipd_admissions')->where('bed_id', $bed)->where('status', 'admitted')->whereNull('deleted_at')->exists()) {
            abort(409, 'A patient is in this bed - discharge or move them first');
        }
        DB::transaction(function () use ($bed, $code, $d, $row, $active) {
            DB::table('ipd_beds')->where('id', $bed)->update(['code' => $code, 'ward' => $d['ward'] ?? null, 'department_id' => $d['department_id'] ?? null,
                'display_order' => $d['display_order'] ?? $row->display_order, 'is_active' => $active, 'updated_at' => now()]);
            DB::table('ipd_admissions')->where('bed_id', $bed)->update(['bed' => $code]); // the register shows the current number
        });
        Audit::record('update', 'ipd_bed', $bed, ['before' => $row->code, 'after' => $code]);

        return response()->json(collect(self::bedsOut())->firstWhere('id', $bed));
    }

    /** Everything the admission form needs to pick from: registered doctors, departments and beds. */
    public function lookups(): JsonResponse
    {
        $doctors = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->whereNull('d.deleted_at')->orderBy('u.full_name')
            ->get(['d.id', 'u.full_name', 'd.specialization', 'd.status'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->full_name, 'specialization' => $d->specialization, 'is_active' => $d->status === 'active']);

        return response()->json([
            'doctors' => $doctors,
            'departments' => DB::table('departments')->whereNull('deleted_at')->where('is_active', true)->orderBy('display_order')->orderBy('name')->get(['id', 'name']),
            'beds' => self::bedsOut(),
        ]);
    }

    // ---------- summary & export ----------

    public function summary(Request $request): JsonResponse
    {
        [$from, $to, $month] = self::monthBounds($request);
        $byHead = DB::table('ipd_heads as h')->leftJoin('ipd_entries as e', fn ($j) => $j->on('e.head_id', '=', 'h.id')->whereBetween('e.entry_date', [$from, $to]))
            ->groupBy('h.id', 'h.code', 'h.name', 'h.type', 'h.display_order', 'h.is_active')->orderBy('h.display_order')->orderBy('h.id')
            ->selectRaw('h.id, h.code, h.name, h.type, h.is_active, coalesce(sum(e.amount), 0) as total, count(e.id) as entries')->get();
        $heads = $byHead->map(fn ($h) => ['id' => $h->id, 'code' => $h->code, 'name' => $h->name, 'type' => $h->type, 'is_active' => (bool) $h->is_active, 'total' => self::money($h->total), 'entries' => (int) $h->entries])->values();
        $sum = fn (string $t) => self::money($heads->where('type', $t)->sum('total'));

        $days = [];
        $rows = DB::table('ipd_entries as e')->join('ipd_heads as h', 'h.id', '=', 'e.head_id')->whereBetween('e.entry_date', [$from, $to])->groupBy('e.entry_date', 'h.type')
            ->selectRaw('e.entry_date as d, h.type, sum(e.amount) as total')->get();
        foreach ($rows as $r) {
            $days[$r->d][$r->type] = self::money($r->total);
        }
        ksort($days);

        return response()->json([
            'month' => $month, 'admissions_in_month' => DB::table('ipd_admissions')->whereNull('deleted_at')->whereBetween('admission_date', [$from, $to])->count(),
            'currently_admitted' => DB::table('ipd_admissions')->whereNull('deleted_at')->where('status', 'admitted')->count(),
            'received' => $sum('receipt'), 'payouts' => $sum('payout'), 'charges' => $sum('charge'), 'net' => self::money($sum('receipt') - $sum('payout')),
            'heads' => $heads,
            'daily' => collect($days)->map(fn ($v, $d) => ['date' => $d, 'received' => $v['receipt'] ?? 0, 'payouts' => $v['payout'] ?? 0, 'charges' => $v['charge'] ?? 0])->values(),
        ]);
    }

    /** CSV that opens cleanly in Excel: one row per admission, "amount (dd.mm)" per head, like the old register. */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $month] = self::monthBounds($request);
        $heads = DB::table('ipd_heads')->where('is_active', true)->orderBy('display_order')->orderBy('id')->get();
        // Cash and UPI sit right after the patient, like the on-screen register.
        $pay = $heads->whereIn('code', ['cash', 'upi'])->sortBy(fn ($h) => $h->code === 'cash' ? 0 : 1)->values();
        $other = $heads->reject(fn ($h) => in_array($h->code, ['cash', 'upi'], true))->values();
        $rows = self::admissionsOut(self::monthQuery($from, $to)->orderBy('a.admission_date')->orderBy('a.id')->get());
        Audit::record('export', 'ipd_register', null, ['month' => $month, 'rows' => count($rows)]);

        return response()->streamDownload(function () use ($pay, $other, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['Date', 'Patient'], $pay->pluck('name')->all(), ['Bed', 'Department', 'Case', 'Doctors', 'Anaesthetist', 'Status'], $other->pluck('name')->all(), ['Total received', 'Total payouts', 'Balance']));
            foreach ($rows as $r) {
                $cell = fn ($h) => collect($r['entries'])->where('head_id', $h->id)->map(fn ($e) => rtrim(rtrim(number_format($e['amount'], 2, '.', ''), '0'), '.').' ('.date('d.m', strtotime($e['entry_date'])).')')->implode('; ');
                fputcsv($out, array_merge([date('d-m-Y', strtotime($r['admission_date'])), $r['patient_name']], $pay->map($cell)->all(), [$r['bed'], $r['department'], $r['case_title'], $r['doctors'], $r['anaesthetist'], $r['status']], $other->map($cell)->all(), [$r['received'], $r['payouts'], $r['balance']]));
            }
            fclose($out);
        }, "ipd-register-$month.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
