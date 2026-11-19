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
