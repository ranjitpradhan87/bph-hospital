<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Support\Audit;
use App\Support\Hospital;
use App\Support\Paging;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The testing lab: test catalogue, orders for a patient, results, and payment through the shared bills. */
class LabController extends Controller
{
    public const STATUSES = ['ordered', 'sample_collected', 'processing', 'completed', 'cancelled'];

    private const FLOW = ['ordered' => ['sample_collected', 'cancelled'], 'sample_collected' => ['processing', 'cancelled'], 'processing' => ['completed'], 'completed' => [], 'cancelled' => []];

    public const METHODS = ['cash', 'upi', 'card', 'cheque', 'other'];

    public const FLAGS = ['normal', 'high', 'low', 'critical'];

    private static function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    private static function iso($t): ?string
    {
        return $t ? \Illuminate\Support\Carbon::parse($t, 'UTC')->toIso8601String() : null;
    }

    // ---------- test catalogue ----------

    private static function testOut(object $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'category' => $t->category, 'sample_type' => $t->sample_type, 'price' => self::money($t->price),
            'turnaround_hours' => $t->turnaround_hours === null ? null : (int) $t->turnaround_hours, 'unit' => $t->unit, 'reference_range' => $t->reference_range,
            'is_active' => (bool) $t->is_active, 'display_order' => (int) $t->display_order];
    }

    public function tests(Request $request): JsonResponse
    {
        $q = DB::table('lab_tests')->orderBy('category')->orderBy('display_order')->orderBy('name');
        // Only people who manage the catalogue see switched-off tests.
        if (! ($request->boolean('all') && $request->user()->can('lab.catalogue'))) {
            $q->where('is_active', true);
        }

        return response()->json($q->get()->map(fn ($t) => self::testOut($t))->values());
    }

    private static function testRules(): array
    {
        return ['name' => ['required', 'string', 'min:2', 'max:150'], 'category' => ['required', 'string', 'min:2', 'max:60'], 'sample_type' => ['nullable', 'string', 'max:40'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'], 'turnaround_hours' => ['nullable', 'integer', 'min:0', 'max:720'], 'unit' => ['nullable', 'string', 'max:30'],
            'reference_range' => ['nullable', 'string', 'max:150'], 'is_active' => ['nullable', 'boolean'], 'display_order' => ['nullable', 'integer', 'min:0', 'max:9999']];
    }

    public function storeTest(Request $request): JsonResponse
    {
        $d = $request->validate(self::testRules());
        DB::table('lab_tests')->whereRaw('lower(name) = ?', [mb_strtolower($d['name'])])->exists() && abort(409, 'A test with this name already exists');
        $id = DB::table('lab_tests')->insertGetId(['code' => 'TMP-'.bin2hex(random_bytes(6))] + self::testFields($d) + ['created_at' => now(), 'updated_at' => now()]);
        DB::table('lab_tests')->where('id', $id)->update(['code' => sprintf('LT-%04d', $id)]);
        Audit::record('create', 'lab_test', $id, ['name' => $d['name'], 'price' => $d['price']]);

        return response()->json(self::testOut(DB::table('lab_tests')->find($id)), 201);
    }

    public function updateTest(Request $request, int $test): JsonResponse
    {
        $row = DB::table('lab_tests')->find($test) ?? abort(404, 'Test not found');
        $d = $request->validate(self::testRules());
        DB::table('lab_tests')->where('name', $d['name'])->where('id', '!=', $test)->exists() && abort(409, 'A test with this name already exists');
        DB::table('lab_tests')->where('id', $test)->update(self::testFields($d, $row) + ['updated_at' => now()]);
        Audit::record('update', 'lab_test', $test, ['before' => ['price' => self::money($row->price), 'active' => (bool) $row->is_active], 'after' => ['price' => self::money($d['price']), 'active' => $d['is_active'] ?? (bool) $row->is_active]]);

        return response()->json(self::testOut(DB::table('lab_tests')->find($test)));
    }

    private static function testFields(array $d, ?object $existing = null): array
    {
        return ['name' => trim($d['name']), 'category' => trim($d['category']), 'sample_type' => $d['sample_type'] ?? null, 'price' => $d['price'], 'turnaround_hours' => $d['turnaround_hours'] ?? null,
            'unit' => $d['unit'] ?? null, 'reference_range' => $d['reference_range'] ?? null, 'is_active' => $d['is_active'] ?? ($existing->is_active ?? true), 'display_order' => $d['display_order'] ?? ($existing->display_order ?? 0)];
    }

    /** What the new-order screen picks from: active tests and the doctors who can order them. */
    public function lookups(): JsonResponse
    {
        $doctors = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->whereNull('d.deleted_at')->where('d.status', 'active')->orderBy('u.full_name')
            ->get(['d.id', 'u.full_name'])->map(fn ($d) => ['id' => $d->id, 'name' => $d->full_name]);

        return response()->json(['doctors' => $doctors, 'tests' => DB::table('lab_tests')->where('is_active', true)->orderBy('category')->orderBy('display_order')->orderBy('name')->get()->map(fn ($t) => self::testOut($t))->values()]);
    }

    // ---------- orders: output ----------

    /** A doctor without lab-management rights sees only the orders they placed. */
    private static function ownDoctorScope(Request $request): ?int
    {
        $u = $request->user();
        if ($u->can('lab.manage') || $u->can('lab.results')) {
            return null;
        }

        return (int) (DB::table('doctors')->where('user_id', $u->id)->value('id') ?? 0);
    }

    private static function baseOrders()
    {
        return DB::table('lab_orders as o')->join('patients as p', 'p.id', '=', 'o.patient_id')->leftJoin('bills as b', 'b.id', '=', 'o.bill_id')
            ->leftJoin('doctors as dr', 'dr.id', '=', 'o.doctor_id')->leftJoin('users as du', 'du.id', '=', 'dr.user_id')
            ->leftJoin('ipd_admissions as ad', 'ad.id', '=', 'o.admission_id')
            ->select('o.*', 'ad.bed as ipd_bed', 'p.patient_code', 'p.full_name as patient_name', 'p.mobile as patient_mobile', 'du.full_name as doctor_name',
                'b.bill_number', 'b.subtotal', 'b.discount_amount', 'b.total_amount', 'b.paid_amount', 'b.status as bill_status');
    }

    private static function billOut(object $o): array
    {
        return ['number' => $o->bill_number, 'subtotal' => self::money($o->subtotal), 'discount' => self::money($o->discount_amount), 'total' => self::money($o->total_amount),
            'paid' => self::money($o->paid_amount), 'balance' => self::money($o->total_amount - $o->paid_amount), 'status' => $o->bill_status];
    }

    private static function rowOut(object $o): array
    {
        return ['id' => $o->id, 'order_number' => $o->order_number, 'status' => $o->status, 'created_at' => self::iso($o->created_at), 'patient_id' => $o->patient_id,
            'patient_code' => $o->patient_code, 'patient_name' => $o->patient_name, 'doctor_id' => $o->doctor_id, 'doctor_name' => $o->doctor_name,
            'tests' => $o->tests ?? null, 'bill' => self::billOut($o), 'admission_id' => $o->admission_id, 'ipd_bed' => $o->ipd_bed, 'opd_visit_id' => $o->opd_visit_id];
    }

    private static function fullOut(int $id): array
    {
        $o = self::baseOrders()->where('o.id', $id)->first() ?? abort(404, 'Lab order not found');
        $items = DB::table('lab_order_items')->where('order_id', $id)->orderBy('id')->get()->map(fn ($i) => [
            'id' => $i->id, 'test_id' => $i->test_id, 'test_name' => $i->test_name, 'price' => self::money($i->price), 'result_value' => $i->result_value, 'result_unit' => $i->result_unit,
            'reference_range' => $i->reference_range, 'flag' => $i->flag, 'result_notes' => $i->result_notes, 'resulted_at' => self::iso($i->resulted_at)])->values();
        $payments = $o->bill_id ? DB::table('payments as pm')->leftJoin('users as u', 'u.id', '=', 'pm.received_by_user_id')->where('pm.bill_id', $o->bill_id)->orderBy('pm.id')
            ->get(['pm.id', 'pm.receipt_number', 'pm.amount', 'pm.method', 'pm.reference_no', 'pm.paid_at', 'u.full_name as by'])
            ->map(fn ($p) => ['id' => $p->id, 'receipt_number' => $p->receipt_number, 'amount' => self::money($p->amount), 'method' => $p->method, 'reference_no' => $p->reference_no, 'paid_at' => self::iso($p->paid_at), 'received_by' => $p->by])->values() : [];

        return self::rowOut($o) + ['patient_mobile' => $o->patient_mobile, 'notes' => $o->notes, 'sample_collected_at' => self::iso($o->sample_collected_at),
            'completed_at' => self::iso($o->completed_at), 'cancel_reason' => $o->cancel_reason, 'items' => $items, 'payments' => $payments];
    }

    // ---------- orders: list / show ----------

    public function orders(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)], 'q' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'patient_id' => 'nullable|integer', 'unpaid' => 'nullable|in:0,1,true,false', 'visit' => ['nullable', Rule::in(['ipd', 'walk_in'])]]);
        $q = self::baseOrders()->selectRaw('(select group_concat(i.test_name order by i.id separator \', \') from lab_order_items i where i.order_id = o.id) as tests');
        if (($own = self::ownDoctorScope($request)) !== null) {
            $q->where('o.doctor_id', $own);
        }
        if ($s = $request->query('status')) {
            $q->where('o.status', $s);
        }
        if ($pid = $request->query('patient_id')) {
            $q->where('o.patient_id', (int) $pid);
        }
        if ($visit = $request->query('visit')) { // in-patients (charged to an admission) or lab-only walk-ins
            $visit === 'ipd' ? $q->whereNotNull('o.admission_id') : $q->whereNull('o.admission_id');
        }
        if ($from = $request->query('from')) {
            $q->whereDate('o.created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('o.created_at', '<=', $to);
        }
        if ($request->boolean('unpaid')) {
            $q->where('o.status', '!=', 'cancelled')->whereColumn('b.paid_amount', '<', 'b.total_amount');
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('o.order_number', 'like', "%$term%")->orWhere('p.full_name', 'like', "%$term%")->orWhere('p.patient_code', 'like', "%$term%")
                ->orWhere('p.mobile', 'like', '%'.preg_replace('/\D/', '', $term).'%')
                ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('lab_order_items as i')->whereColumn('i.order_id', 'o.id')->where('i.test_name', 'like', "%$term%")));
        }

        return Paging::respond($request, $q->orderByDesc('o.id'), fn ($o) => self::rowOut($o));
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $row = DB::table('lab_orders')->find($order) ?? abort(404, 'Lab order not found');
        if (($own = self::ownDoctorScope($request)) !== null && (int) $row->doctor_id !== $own) {
            abort(404, 'Lab order not found'); // do not reveal other doctors' orders
        }

        return response()->json(self::fullOut($order));
    }

    // ---------- orders: create ----------

    public function store(Request $request): JsonResponse
    {
        $request->merge(['patient_mobile' => $request->filled('patient_mobile') ? Phone::normalize((string) $request->input('patient_mobile')) : null]);
        $d = $request->validate([
            'patient_id' => ['nullable', 'integer'], 'patient_name' => ['nullable', 'string', 'min:2', 'max:150'], 'patient_mobile' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
            'doctor_id' => ['nullable', 'integer'], 'bill_to_admission' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:1000'], 'opd_visit_id' => ['nullable', 'integer'],
            'test_ids' => ['required', 'array', 'min:1', 'max:40'], 'test_ids.*' => ['integer', 'distinct'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'payment' => ['nullable', 'array'], 'payment.amount' => ['required_with:payment', 'numeric', 'gt:0'], 'payment.method' => ['required_with:payment', Rule::in(self::METHODS)], 'payment.reference_no' => ['nullable', 'string', 'max:100'],
        ], ['patient_mobile.regex' => 'Enter a valid 10-digit Indian mobile number']);

        // A doctor may order tests for their own OPD visit; taking money or discounting is the lab desk's job.
        $user = $request->user();
        $visit = ($d['opd_visit_id'] ?? null) ? (DB::table('opd_visits')->find($d['opd_visit_id']) ?? abort(404, 'Visit not found')) : null;
        if (! $user->can('lab.manage')) {
            ($visit && empty($d['payment']) && empty($d['discount'])) || abort(403, 'You do not have permission to perform this action');
            (int) $visit->doctor_id === (int) DB::table('doctors')->where('user_id', $user->id)->value('id') || abort(403, 'You can order tests only for your own visits');
        }
        $visit && $visit->status === 'cancelled' && abort(409, 'This visit was cancelled');
        if ($visit) {
            $d['patient_id'] = $visit->patient_id;
            $d['doctor_id'] = $d['doctor_id'] ?? $visit->doctor_id;
            $d['bill_to_admission'] = false;
        }

        $tests = DB::table('lab_tests')->whereIn('id', $d['test_ids'])->where('is_active', true)->get()->keyBy('id');
        count($tests) === count($d['test_ids']) || abort(422, 'One of the chosen tests is not available');
        ($d['doctor_id'] ?? null) === null || DB::table('doctors')->where('id', $d['doctor_id'])->whereNull('deleted_at')->exists() || abort(400, 'Unknown doctor');
        $subtotal = self::money($tests->sum('price'));
        $discount = self::money($d['discount'] ?? 0);
        $discount <= $subtotal || abort(422, 'The discount cannot be more than the total');
        $total = $subtotal - $discount;
        $paying = self::money($d['payment']['amount'] ?? 0);
        $paying <= $total || abort(422, 'The payment cannot be more than the amount due');

        $id = DB::transaction(function () use ($d, $tests, $subtotal, $discount, $total, $request, $visit) {
            $patientId = $d['patient_id'] ?? null;
            if ($patientId) {
                DB::table('patients')->where('id', $patientId)->whereNull('deleted_at')->exists() || abort(400, 'Unknown patient');
            } else {
                // A new walk-in is registered on the spot (details pending) so every lab order belongs to a patient record.
                filled($d['patient_name'] ?? null) || abort(422, 'Choose a registered patient or enter the patient name');
                $patientId = DB::table('patients')->insertGetId(['patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'full_name' => trim($d['patient_name']), 'mobile' => $d['patient_mobile'] ?? null,
                    'registered_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('patients')->where('id', $patientId)->update(['patient_code' => Patient::codeFor($patientId)]);
                Audit::record('create', 'patient', $patientId, ['via' => 'lab_order']);
            }

            // An admitted patient's tests are charged to their stay; a lab-only visitor has no admission.
            $admissionId = ($d['bill_to_admission'] ?? true) ? DB::table('ipd_admissions')->where('patient_id', $patientId)->where('status', 'admitted')->whereNull('deleted_at')->orderByDesc('id')->value('id') : null;

            $billId = DB::table('bills')->insertGetId(['bill_number' => 'TMP-'.bin2hex(random_bytes(6)), 'patient_id' => $patientId, 'bill_date' => Hospital::today(), 'subtotal' => $subtotal,
                'discount_amount' => $discount, 'total_amount' => $total, 'paid_amount' => 0, 'status' => $total > 0 ? 'pending' : 'paid', 'created_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bills')->where('id', $billId)->update(['bill_number' => sprintf('BILL-%s-%05d', date('ymd'), $billId)]);
            $orderId = DB::table('lab_orders')->insertGetId(['order_number' => 'TMP-'.bin2hex(random_bytes(6)), 'patient_id' => $patientId, 'doctor_id' => $d['doctor_id'] ?? null, 'admission_id' => $admissionId, 'opd_visit_id' => $visit?->id,
                'bill_id' => $billId, 'status' => 'ordered', 'notes' => $d['notes'] ?? null, 'created_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lab_orders')->where('id', $orderId)->update(['order_number' => sprintf('LAB-%s-%05d', date('ymd'), $orderId)]);
            foreach ($d['test_ids'] as $tid) {
                $t = $tests[$tid];
                DB::table('lab_order_items')->insert(['order_id' => $orderId, 'test_id' => $t->id, 'test_name' => $t->name, 'price' => $t->price, 'result_unit' => $t->unit, 'reference_range' => $t->reference_range, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('bill_items')->insert(['bill_id' => $billId, 'item_type' => 'lab_test', 'description' => $t->name, 'quantity' => 1, 'unit_price' => $t->price, 'line_total' => $t->price, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($admissionId && $total > 0) {
                DB::table('ipd_entries')->insert(['admission_id' => $admissionId, 'lab_order_id' => $orderId, 'head_id' => self::labHeadId(), 'entry_date' => Hospital::today(), 'amount' => $total,
                    'note' => 'Lab '.sprintf('LAB-%s-%05d', date('ymd'), $orderId).': '.Str::limit($tests->pluck('name')->implode(', '), 150), 'created_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! empty($d['payment'])) {
                self::pay($billId, (float) $d['payment']['amount'], $d['payment']['method'], $d['payment']['reference_no'] ?? null, $request->user()->id);
            }
            Audit::record('create', 'lab_order', $orderId, ['tests' => count($d['test_ids']), 'total' => $total]);

            return $orderId;
        });

        return response()->json(self::fullOut($id), 201);
    }

    /** The IPD register column that lab charges go to (created on first use if it was removed). */
    private static function labHeadId(): int
    {
        $id = DB::table('ipd_heads')->where('code', 'lab')->value('id');

        return $id ?: DB::table('ipd_heads')->insertGetId(['code' => 'lab', 'name' => 'Lab tests', 'type' => 'charge', 'display_order' => 1 + (int) DB::table('ipd_heads')->max('display_order'),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Is this patient in hospital right now? The new-order screen uses it to say the tests go to the IPD register. */
    public function admissionFor(int $patient): JsonResponse
    {
        $a = DB::table('ipd_admissions')->where('patient_id', $patient)->where('status', 'admitted')->whereNull('deleted_at')->orderByDesc('id')->first(['id', 'bed', 'case_title']);

        return response()->json(['admission' => $a ? ['id' => $a->id, 'bed' => $a->bed, 'case_title' => $a->case_title] : null]);
    }

    // ---------- payments ----------

    /** Record a payment against a bill and bring its status up to date. Caller supplies the transaction. */
    public static function pay(int $billId, float $amount, string $method, ?string $reference, ?int $userId): int
    {
        $bill = DB::table('bills')->where('id', $billId)->lockForUpdate()->first();
        if ($bill->status === 'cancelled') {
            abort(409, 'This order was cancelled');
        }
        $balance = self::money($bill->total_amount - $bill->paid_amount);
        $amount = self::money($amount);
        $amount <= $balance || abort(422, 'The payment is more than the balance of ₹'.$balance);
        $id = DB::table('payments')->insertGetId(['receipt_number' => 'TMP-'.bin2hex(random_bytes(6)), 'bill_id' => $billId, 'amount' => $amount, 'method' => $method, 'reference_no' => $reference,
            'status' => 'success', 'paid_at' => now(), 'received_by_user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->where('id', $id)->update(['receipt_number' => sprintf('RCT-%s-%05d', date('ymd'), $id)]);
        $paid = self::money($bill->paid_amount + $amount);
        DB::table('bills')->where('id', $billId)->update(['paid_amount' => $paid, 'status' => $paid >= $bill->total_amount ? 'paid' : 'partially_paid', 'updated_at' => now()]);

        return $id;
    }

    public function addPayment(Request $request, int $order): JsonResponse
    {
        $row = DB::table('lab_orders')->find($order) ?? abort(404, 'Lab order not found');
        $d = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', Rule::in(self::METHODS)], 'reference_no' => ['nullable', 'string', 'max:100']]);
        $payment = DB::transaction(fn () => self::pay($row->bill_id, (float) $d['amount'], $d['method'], $d['reference_no'] ?? null, $request->user()->id));
        Audit::record('payment', 'lab_order', $order, ['payment_id' => $payment, 'amount' => self::money($d['amount']), 'method' => $d['method']]);

        return response()->json(self::fullOut($order), 201);
    }

    // ---------- status, results, cancel ----------

    public function setStatus(Request $request, int $order): JsonResponse
    {
        $to = $request->validate(['status' => ['required', Rule::in(['sample_collected', 'processing', 'completed'])]])['status'];
        DB::transaction(function () use ($order, $to, $request) {
            $o = DB::table('lab_orders')->where('id', $order)->lockForUpdate()->first() ?? abort(404, 'Lab order not found');
            in_array($to, self::FLOW[$o->status], true) || abort(409, "A {$o->status} order cannot be marked ".str_replace('_', ' ', $to));
            // Releasing a report needs the lab's own permission and a result for every test.
            if ($to === 'completed') {
                $request->user()->can('lab.results') || abort(403, 'You do not have permission to perform this action');
                DB::table('lab_order_items')->where('order_id', $order)->where(fn ($w) => $w->whereNull('result_value')->orWhere('result_value', ''))->exists() && abort(409, 'Enter a result for every test before releasing the report');
            }
            $set = ['status' => $to, 'updated_at' => now()];
            $to === 'sample_collected' && $set['sample_collected_at'] = now();
            $to === 'completed' && $set['completed_at'] = now();
            DB::table('lab_orders')->where('id', $order)->update($set);
            Audit::record('status', 'lab_order', $order, ['from' => $o->status, 'to' => $to]);
        });

        return response()->json(self::fullOut($order));
    }

    public function saveResults(Request $request, int $order): JsonResponse
    {
        $o = DB::table('lab_orders')->find($order) ?? abort(404, 'Lab order not found');
        in_array($o->status, ['sample_collected', 'processing', 'completed'], true) || abort(409, 'Collect the sample before entering results');
        $d = $request->validate(['results' => ['required', 'array', 'min:1'], 'results.*.item_id' => ['required', 'integer'], 'results.*.result_value' => ['nullable', 'string', 'max:2000'],
            'results.*.result_unit' => ['nullable', 'string', 'max:30'], 'results.*.reference_range' => ['nullable', 'string', 'max:150'], 'results.*.flag' => ['nullable', Rule::in(self::FLAGS)],
            'results.*.result_notes' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($d, $order, $o, $request) {
            foreach ($d['results'] as $r) {
                $item = DB::table('lab_order_items')->where('id', $r['item_id'])->where('order_id', $order)->first() ?? abort(400, 'A result does not belong to this order');
                $value = isset($r['result_value']) && trim($r['result_value']) !== '' ? trim($r['result_value']) : null;
                DB::table('lab_order_items')->where('id', $item->id)->update(['result_value' => $value, 'result_unit' => $r['result_unit'] ?? null, 'reference_range' => $r['reference_range'] ?? null,
                    'flag' => $r['flag'] ?? null, 'result_notes' => $r['result_notes'] ?? null, 'resulted_by_user_id' => $value === null ? null : $request->user()->id, 'resulted_at' => $value === null ? null : now(), 'updated_at' => now()]);
            }
            // The first result moves the order into processing.
            if ($o->status === 'sample_collected') {
                DB::table('lab_orders')->where('id', $order)->update(['status' => 'processing', 'updated_at' => now()]);
            }
            Audit::record($o->status === 'completed' ? 'correct_results' : 'enter_results', 'lab_order', $order, ['items' => count($d['results'])]);
        });

        return response()->json(self::fullOut($order));
    }

    public function cancel(Request $request, int $order): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        DB::transaction(function () use ($order, $d, $request) {
            $o = DB::table('lab_orders')->where('id', $order)->lockForUpdate()->first() ?? abort(404, 'Lab order not found');
            in_array('cancelled', self::FLOW[$o->status], true) || abort(409, 'Only an order that has not started processing can be cancelled');
            $bill = DB::table('bills')->where('id', $o->bill_id)->lockForUpdate()->first();
            $refund = self::money($bill->paid_amount);
            if ($refund > 0) { // give the money back on record
                $id = DB::table('payments')->insertGetId(['receipt_number' => 'TMP-'.bin2hex(random_bytes(6)), 'bill_id' => $bill->id, 'amount' => -$refund, 'method' => 'refund', 'status' => 'success',
                    'paid_at' => now(), 'received_by_user_id' => $request->user()->id, 'notes' => 'Lab order cancelled: '.$d['reason'], 'created_at' => now(), 'updated_at' => now()]);
                DB::table('payments')->where('id', $id)->update(['receipt_number' => sprintf('RCT-%s-%05d', date('ymd'), $id)]);
            }
            DB::table('bills')->where('id', $bill->id)->update(['paid_amount' => 0, 'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $d['reason'], 'updated_at' => now()]);
            DB::table('ipd_entries')->where('lab_order_id', $order)->delete(); // the charge leaves the IPD register with the order
            DB::table('lab_orders')->where('id', $order)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $d['reason'], 'updated_at' => now()]);
            Audit::record('cancel', 'lab_order', $order, ['reason' => $d['reason'], 'refunded' => $refund]);
        });

        return response()->json(self::fullOut($order));
    }

    // ---------- patient portal ----------

    /** A patient's own lab orders; results are shown only once the lab has released the report. */
    public function mine(Request $request): JsonResponse
    {
        $pid = Patient::where('user_id', $request->user()->id)->value('id') ?? abort(404, 'No patient record is linked to this account');
        $orders = self::baseOrders()->selectRaw('(select group_concat(i.test_name order by i.id separator \', \') from lab_order_items i where i.order_id = o.id) as tests')
            ->where('o.patient_id', $pid)->where('o.status', '!=', 'cancelled')->orderByDesc('o.id')->limit(50)->get();
        $items = DB::table('lab_order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('id')->get()->groupBy('order_id');

        return response()->json($orders->map(function ($o) use ($items) {
            $released = $o->status === 'completed';

            return self::rowOut($o) + ['completed_at' => self::iso($o->completed_at), 'items' => $released ? ($items[$o->id] ?? collect())->map(fn ($i) => [
                'id' => $i->id, 'test_name' => $i->test_name, 'result_value' => $i->result_value, 'result_unit' => $i->result_unit, 'reference_range' => $i->reference_range, 'flag' => $i->flag, 'result_notes' => $i->result_notes])->values() : []];
        })->values());
    }
}
