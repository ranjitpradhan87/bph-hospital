<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Hospital;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Bills and counter payments for every kind of charge: consultations and procedures billed here, and the
 * lab bills the lab section raises. Payments are recorded as receipts against a bill.
 */
class BillingController extends Controller
{
    public const STATUSES = ['pending', 'partially_paid', 'paid', 'cancelled'];

    private static function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    private static function iso(?string $utc): ?string
    {
        return $utc ? \Illuminate\Support\Carbon::parse($utc, 'UTC')->toIso8601String() : null;
    }

    private static function base()
    {
        return DB::table('bills as b')->join('patients as p', 'p.id', '=', 'b.patient_id')->leftJoin('lab_orders as lo', 'lo.bill_id', '=', 'b.id')
            ->select('b.*', 'p.full_name as patient_name', 'p.patient_code', 'p.mobile as patient_mobile', 'lo.id as lab_order_id', 'lo.order_number as lab_order_number');
    }

    private static function rowOut(object $b): array
    {
        return ['id' => $b->id, 'bill_number' => $b->bill_number, 'bill_date' => $b->bill_date, 'patient_id' => $b->patient_id, 'patient_name' => $b->patient_name, 'patient_code' => $b->patient_code,
            'total' => self::money($b->total_amount), 'paid' => self::money($b->paid_amount), 'balance' => self::money($b->status === 'cancelled' ? 0 : $b->total_amount - $b->paid_amount),
            'status' => $b->status, 'source' => $b->lab_order_id ? 'lab' : 'counter', 'lab_order_number' => $b->lab_order_number];
    }

    private static function filtered(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)], 'q' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'unpaid' => 'nullable|in:0,1,true,false', 'source' => ['nullable', Rule::in(['lab', 'counter'])], 'patient_id' => 'nullable|integer']);
        $q = self::base();
        if ($s = $request->query('status')) {
            $q->where('b.status', $s);
        }
        if ($pid = $request->query('patient_id')) {
            $q->where('b.patient_id', (int) $pid);
        }
        if ($from = $request->query('from')) {
            $q->where('b.bill_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('b.bill_date', '<=', $to);
        }
        if ($request->boolean('unpaid')) {
            $q->where('b.status', '!=', 'cancelled')->whereColumn('b.paid_amount', '<', 'b.total_amount');
        }
        if ($src = $request->query('source')) {
            $src === 'lab' ? $q->whereNotNull('lo.id') : $q->whereNull('lo.id');
        }
        if ($term = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('b.bill_number', 'like', "%$term%")->orWhere('p.full_name', 'like', "%$term%")->orWhere('p.patient_code', 'like', "%$term%")
                ->orWhere('p.mobile', 'like', '%'.preg_replace('/\D/', '', $term).'%'));
        }

        return $q;
    }

    public function index(Request $request): JsonResponse
    {
        $q = self::filtered($request);
        $t = (clone $q)->where('b.status', '!=', 'cancelled')->select(DB::raw('coalesce(sum(b.total_amount),0) as billed, coalesce(sum(b.paid_amount),0) as paid, count(*) as n'))->first();
        $summary = ['bills' => (int) $t->n, 'billed' => self::money($t->billed), 'collected' => self::money($t->paid), 'outstanding' => self::money($t->billed - $t->paid)];
        $page = Paging::respond($request, $q->orderByDesc('b.bill_date')->orderByDesc('b.id'), fn ($b) => self::rowOut($b))->getData(true);

        return response()->json($page + ['summary' => $summary]);
    }

    private static function fullOut(int $id): array
    {
        $b = self::base()->where('b.id', $id)->first() ?? abort(404, 'Bill not found');
        $items = DB::table('bill_items')->where('bill_id', $id)->orderBy('id')->get()->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'quantity' => self::money($i->quantity),
            'unit_price' => self::money($i->unit_price), 'line_total' => self::money($i->line_total)])->values();
        $payments = DB::table('payments as pm')->leftJoin('users as u', 'u.id', '=', 'pm.received_by_user_id')->where('pm.bill_id', $id)->orderBy('pm.id')
            ->get(['pm.id', 'pm.receipt_number', 'pm.amount', 'pm.method', 'pm.reference_no', 'pm.paid_at', 'pm.notes', 'u.full_name as by'])
            ->map(fn ($p) => ['id' => $p->id, 'receipt_number' => $p->receipt_number, 'amount' => self::money($p->amount), 'method' => $p->method, 'reference_no' => $p->reference_no,
                'paid_at' => self::iso($p->paid_at), 'received_by' => $p->by, 'notes' => $p->notes])->values();

        return self::rowOut($b) + ['patient_mobile' => $b->patient_mobile, 'subtotal' => self::money($b->subtotal), 'discount' => self::money($b->discount_amount), 'notes' => $b->notes,
            'cancel_reason' => $b->cancel_reason, 'appointment_id' => $b->appointment_id, 'items' => $items, 'payments' => $payments];
    }

    public function show(int $bill): JsonResponse
    {
        return response()->json(self::fullOut($bill));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'patient_id' => ['required', 'integer'], 'appointment_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:1000'], 'discount' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'items' => ['required', 'array', 'min:1', 'max:30'], 'items.*.description' => ['required', 'string', 'min:2', 'max:255'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'payment' => ['nullable', 'array'], 'payment.amount' => ['required_with:payment', 'numeric', 'gt:0'], 'payment.method' => ['required_with:payment', Rule::in(LabController::METHODS)], 'payment.reference_no' => ['nullable', 'string', 'max:100'],
        ]);
        DB::table('patients')->where('id', $d['patient_id'])->whereNull('deleted_at')->exists() || abort(400, 'Unknown patient');
        ($d['appointment_id'] ?? null) === null || DB::table('appointments')->where('id', $d['appointment_id'])->where('patient_id', $d['patient_id'])->exists() || abort(400, 'That appointment is not this patient\'s');
        if (! empty($d['payment'])) {
            $request->user()->can('payments.collect') || abort(403, 'You do not have permission to perform this action');
        }
        $lines = collect($d['items'])->map(fn ($i) => ['description' => trim($i['description']), 'quantity' => (float) $i['quantity'], 'unit_price' => (float) $i['unit_price'], 'line_total' => self::money($i['quantity'] * $i['unit_price'])]);
        $subtotal = self::money($lines->sum('line_total'));
        $discount = self::money($d['discount'] ?? 0);
        $subtotal > 0 || abort(422, 'The bill total must be more than zero');
        $discount < $subtotal || abort(422, 'The discount cannot be the whole amount or more');
        $total = $subtotal - $discount;
        self::money($d['payment']['amount'] ?? 0) <= $total || abort(422, 'The payment cannot be more than the amount due');

        $id = DB::transaction(function () use ($d, $lines, $subtotal, $discount, $total, $request) {
            $billId = DB::table('bills')->insertGetId(['bill_number' => 'TMP-'.bin2hex(random_bytes(6)), 'patient_id' => $d['patient_id'], 'appointment_id' => $d['appointment_id'] ?? null, 'bill_date' => Hospital::today(),
                'subtotal' => $subtotal, 'discount_amount' => $discount, 'total_amount' => $total, 'paid_amount' => 0, 'status' => 'pending', 'notes' => $d['notes'] ?? null,
                'created_by_user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('bills')->where('id', $billId)->update(['bill_number' => sprintf('BILL-%s-%05d', date('ymd'), $billId)]);
            foreach ($lines as $l) {
                DB::table('bill_items')->insert(['bill_id' => $billId, 'item_type' => 'service', 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                    'line_total' => $l['line_total'], 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! empty($d['payment'])) {
                LabController::pay($billId, (float) $d['payment']['amount'], $d['payment']['method'], $d['payment']['reference_no'] ?? null, $request->user()->id);
            }
            Audit::record('create', 'bill', $billId, ['total' => $total, 'items' => $lines->count()]);

            return $billId;
        });

        return response()->json(self::fullOut($id), 201);
    }

    public function addPayment(Request $request, int $bill): JsonResponse
    {
        DB::table('bills')->where('id', $bill)->exists() || abort(404, 'Bill not found');
        $d = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', Rule::in(LabController::METHODS)], 'reference_no' => ['nullable', 'string', 'max:100']]);
        $payment = DB::transaction(fn () => LabController::pay($bill, (float) $d['amount'], $d['method'], $d['reference_no'] ?? null, $request->user()->id));
        Audit::record('payment', 'bill', $bill, ['payment_id' => $payment, 'amount' => self::money($d['amount']), 'method' => $d['method']]);

        return response()->json(self::fullOut($bill), 201);
    }

    /** Cancel a bill raised here. Lab bills are cancelled from the lab order so the test and the IPD charge go with it. */
    public function cancel(Request $request, int $bill): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        DB::transaction(function () use ($bill, $d, $request) {
            $b = DB::table('bills')->where('id', $bill)->lockForUpdate()->first() ?? abort(404, 'Bill not found');
            DB::table('lab_orders')->where('bill_id', $bill)->exists() && abort(409, 'This is a lab bill. Cancel the lab order in the Lab section.');
            $b->status !== 'cancelled' || abort(409, 'This bill is already cancelled');
            $refund = self::money($b->paid_amount);
            $refund > 0 && ($request->user()->can('payments.refund') || abort(403, 'Only someone allowed to refund can cancel a bill that has been paid'));
            if ($refund > 0) {
                $id = DB::table('payments')->insertGetId(['receipt_number' => 'TMP-'.bin2hex(random_bytes(6)), 'bill_id' => $bill, 'amount' => -$refund, 'method' => 'refund', 'status' => 'success',
                    'paid_at' => now(), 'received_by_user_id' => $request->user()->id, 'notes' => 'Bill cancelled: '.$d['reason'], 'created_at' => now(), 'updated_at' => now()]);
                DB::table('payments')->where('id', $id)->update(['receipt_number' => sprintf('RCT-%s-%05d', date('ymd'), $id)]);
            }
            DB::table('bills')->where('id', $bill)->update(['paid_amount' => 0, 'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $d['reason'], 'updated_at' => now()]);
            Audit::record('cancel', 'bill', $bill, ['reason' => $d['reason'], 'refunded' => $refund]);
        });

        return response()->json(self::fullOut($bill));
    }
}
