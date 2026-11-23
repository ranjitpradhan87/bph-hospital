<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Hospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Admin dashboard figures. Calendar boundaries are hospital-local; payment timestamps are UTC. */
class DashboardController extends Controller
{
    private const STATUSES = ['pending', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'];

    /** UTC bounds of hospital-local midnight on the given dates. */
    private static function utc(string $date): string
    {
        return Carbon::parse($date, Hospital::tz())->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    private static function collected(string $start, string $endExclusive): float
    {
        return (float) DB::table('payments')->where('status', 'success')->where('paid_at', '>=', self::utc($start))->where('paid_at', '<', self::utc($endExclusive))->sum('amount');
    }

    public function summary(Request $request): JsonResponse
    {
        $now = Hospital::now();
        $today = $now->toDateString();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->startOfMonth()->addMonth()->toDateString();

        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach (DB::table('appointments')->where('appointment_date', $today)->groupBy('status')->selectRaw('status, count(*) as c')->get() as $r) {
            $byStatus[$r->status] = (int) $r->c;
        }
        $monthCount = fn (string $status) => DB::table('appointments')->where('status', $status)->where('appointment_date', '>=', $monthStart)->where('appointment_date', '<', $monthEnd)->count();

        $revenue = null;
        if ($request->user()->can('reports.financial')) {
            $out = DB::table('bills')->whereIn('status', ['pending', 'partially_paid'])->selectRaw('coalesce(sum(total_amount - paid_amount), 0) as amt, count(*) as n')->first();
            $revenue = [
                'today' => self::collected($today, $now->copy()->addDay()->toDateString()),
                'this_month' => self::collected($monthStart, $monthEnd),
                'pending_amount' => (float) $out->amt, 'pending_bills' => (int) $out->n,
            ];
        }

        return response()->json([
            'as_of' => $now->format('Y-m-d\TH:i:s'),
            'patients_total' => DB::table('patients')->whereNull('deleted_at')->count(),
            'patients_new_this_month' => DB::table('patients')->whereNull('deleted_at')->where('created_at', '>=', self::utc($monthStart))->where('created_at', '<', self::utc($monthEnd))->count(),
            'doctors_active' => DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.status', 'active')->whereNull('d.deleted_at')->where('u.is_active', true)->whereNull('u.deleted_at')->count(),
            'staff_active' => DB::table('staff')->whereNull('deleted_at')->where('employment_status', 'active')->count(),
            'appointments' => [
                'today_total' => array_sum($byStatus), 'today_by_status' => $byStatus,
                'upcoming' => DB::table('appointments')->whereIn('status', DepartmentController::ACTIVE_STATUSES)
                    ->where(fn ($q) => $q->where('appointment_date', '>', $today)->orWhere(fn ($q2) => $q2->where('appointment_date', $today)->where('start_time', '>=', $now->format('H:i:s'))))->count(),
                'completed_this_month' => $monthCount('completed'), 'cancelled_this_month' => $monthCount('cancelled'),
            ],
            'revenue' => $revenue,
            'enquiries_new' => DB::table('contact_messages')->where('status', 'new')->count(),
        ]);
    }

    /** Today at the front desk, OPD, IPD and lab, for the manager who runs the floor (no reports or staff data). */
    public function operations(Request $request): JsonResponse
    {
        $now = Hospital::now();
        $today = $now->toDateString();
        $money = fn ($n) => round((float) $n, 2);

        $appts = array_fill_keys(self::STATUSES, 0);
        foreach (DB::table('appointments')->where('appointment_date', $today)->groupBy('status')->selectRaw('status, count(*) as c')->get() as $r) {
            $appts[$r->status] = (int) $r->c;
        }

        $visits = DB::table('opd_visits as v')->leftJoin('bills as b', 'b.id', '=', 'v.bill_id')->where('v.visit_date', $today)
            ->select('v.doctor_id', 'v.status', 'b.total_amount', 'b.paid_amount')->get();
        $live = $visits->where('status', '!=', 'cancelled');
        $byDoctor = $live->groupBy('doctor_id')->map(fn ($g, $id) => [
            'doctor_id' => (int) $id, 'visits' => $g->count(), 'waiting' => $g->where('status', 'waiting')->count(),
            'in_consultation' => $g->where('status', 'in_consultation')->count(), 'completed' => $g->where('status', 'completed')->count(),
        ]);
        $names = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->whereIn('d.id', $byDoctor->keys())->pluck('u.full_name', 'd.id');
        $doctors = $byDoctor->map(fn ($r) => $r + ['name' => $names[$r['doctor_id']] ?? 'Doctor'])->sortByDesc('visits')->values();

        $bedsTotal = DB::table('ipd_beds')->where('is_active', true)->count();
        $admitted = DB::table('ipd_admissions')->whereNull('deleted_at')->where('status', 'admitted');
        $ipdToday = fn (string $type) => $money(DB::table('ipd_entries as e')->join('ipd_heads as h', 'h.id', '=', 'e.head_id')->where('h.type', $type)->where('e.entry_date', $today)->sum('e.amount'));

        $lab = fn (string $s) => DB::table('lab_orders')->where('status', $s)->count();
        $labToday = DB::table('lab_orders')->where('created_at', '>=', self::utc($today))->where('created_at', '<', self::utc(Carbon::parse($today)->addDay()->toDateString()))->where('status', '!=', 'cancelled')->count();
        $unpaid = DB::table('bills')->whereIn('status', ['pending', 'partially_paid'])->selectRaw('coalesce(sum(total_amount - paid_amount), 0) as amt, count(*) as n')->first();

        return response()->json([
            'as_of' => $now->format('Y-m-d\TH:i:s'),
            'appointments' => ['today_total' => array_sum($appts), 'today_by_status' => $appts],
            'opd' => [
                'visits' => $live->count(), 'waiting' => $live->where('status', 'waiting')->count(), 'in_consultation' => $live->where('status', 'in_consultation')->count(),
                'completed' => $live->where('status', 'completed')->count(), 'cancelled' => $visits->where('status', 'cancelled')->count(),
                'billed' => $money($live->sum('total_amount')), 'collected' => $money($live->sum('paid_amount')), 'doctors' => $doctors,
            ],
            'ipd' => [
                'admitted' => (clone $admitted)->count(), 'beds_total' => $bedsTotal, 'beds_occupied' => (clone $admitted)->whereNotNull('bed_id')->count(),
                'admissions_today' => DB::table('ipd_admissions')->whereNull('deleted_at')->where('admission_date', $today)->count(),
                'discharges_today' => DB::table('ipd_admissions')->whereNull('deleted_at')->where('discharge_date', $today)->count(),
                'received_today' => $ipdToday('receipt'), 'paid_out_today' => $ipdToday('payout'),
            ],
            'lab' => ['orders_today' => $labToday, 'awaiting_sample' => $lab('ordered'), 'in_progress' => $lab('sample_collected') + $lab('processing'), 'completed_today' => DB::table('lab_orders')->where('status', 'completed')->where('completed_at', '>=', self::utc($today))->count()],
            'patients_new_today' => DB::table('patients')->whereNull('deleted_at')->where('created_at', '>=', self::utc($today))->count(),
            'unpaid_bills' => ['count' => (int) $unpaid->n, 'amount' => $money($unpaid->amt)],
            'enquiries_new' => DB::table('contact_messages')->where('status', 'new')->count(),
        ]);
    }

    public function departmentStats(Request $request): JsonResponse
    {
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $end = $request->query('to') ?: Hospital::today();
        $start = $request->query('from') ?: Carbon::parse($end)->subDays(29)->toDateString();
        if ($start > $end || Carbon::parse($start)->diffInDays(Carbon::parse($end)) > 366) {
            abort(400, 'Choose a range of up to one year');
        }
        $counts = DB::table('appointments')->whereBetween('appointment_date', [$start, $end])->groupBy('department_id')
            ->selectRaw("department_id, count(*) as total, sum(status = 'completed') as completed, sum(status = 'cancelled') as cancelled, sum(status = 'no_show') as no_show")
            ->get()->keyBy('department_id');
        $out = [];
        foreach (DB::table('departments')->whereNull('deleted_at')->orderBy('display_order')->orderBy('name')->get() as $d) {
            $c = $counts->get($d->id);
            $total = (int) ($c->total ?? 0);
            if ($d->is_active || $total) {
                $out[] = ['department_id' => $d->id, 'name' => $d->name, 'total' => $total, 'completed' => (int) ($c->completed ?? 0), 'cancelled' => (int) ($c->cancelled ?? 0), 'no_show' => (int) ($c->no_show ?? 0)];
            }
        }
        usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

        return response()->json($out);
    }

    public function appointmentTrend(Request $request): JsonResponse
    {
        $request->validate(['days' => 'nullable|integer|min:7|max:90']);
        $days = (int) $request->query('days', 14);
        $end = Hospital::today();
        $start = Carbon::parse($end)->subDays($days - 1)->toDateString();
        $rows = DB::table('appointments')->whereBetween('appointment_date', [$start, $end])->groupBy('appointment_date')
            ->selectRaw("appointment_date as d, count(*) as total, sum(status = 'completed') as completed, sum(status = 'cancelled') as cancelled")->get()->keyBy('d');
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = Carbon::parse($start)->addDays($i)->toDateString();
            $r = $rows->get($d);
            $out[] = ['date' => $d, 'total' => (int) ($r->total ?? 0), 'completed' => (int) ($r->completed ?? 0), 'cancelled' => (int) ($r->cancelled ?? 0)];
        }

        return response()->json($out);
    }

    public function revenueTrend(Request $request): JsonResponse
    {
        $request->validate(['months' => 'nullable|integer|min:1|max:24']);
        $months = (int) $request->query('months', 6);
        $first = Hospital::now()->startOfMonth()->subMonths($months - 1);
        $rows = DB::table('payments')->where('status', 'success')->where('paid_at', '>=', self::utc($first->toDateString()))->get(['paid_at', 'amount']);
        $collected = $refunded = [];
        foreach ($rows as $r) {
            $key = Carbon::parse($r->paid_at, 'UTC')->setTimezone(Hospital::tz())->format('Y-m');
            if ($r->amount >= 0) {
                $collected[$key] = ($collected[$key] ?? 0) + $r->amount;
            } else {
                $refunded[$key] = ($refunded[$key] ?? 0) - $r->amount;
            }
        }
        $out = [];
        for ($i = 0; $i < $months; $i++) {
            $key = $first->copy()->addMonths($i)->format('Y-m');
            $out[] = ['month' => $key, 'collected' => (float) ($collected[$key] ?? 0), 'refunded' => (float) ($refunded[$key] ?? 0)];
        }

        return response()->json($out);
    }
}
