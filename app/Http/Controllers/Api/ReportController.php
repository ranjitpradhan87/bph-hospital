<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Hospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports for a date range. Operational figures (appointments, lab, in-patients) need reports.view; every
 * money figure needs reports.financial. Money comes from three places, kept apart so nothing is counted twice:
 * counter and lab bills, online advance payments on appointments, and receipts in the IPD register.
 */
class ReportController extends Controller
{
    private static function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    /** @return array{0: string, 1: string} first and last day, inclusive; at most a year */
    private static function range(Request $request): array
    {
        $d = $request->validate(['from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'], 'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from']]);
        $to = $d['to'] ?? Hospital::today();
        $from = $d['from'] ?? Carbon::parse($to)->subDays(29)->toDateString();
        ($to >= $from && Carbon::parse($from)->diffInDays(Carbon::parse($to)) <= 366) || abort(422, 'Choose a date range of up to one year, ending on or after it starts');

        return [$from, $to];
    }

    private static function utc(string $date): string
    {
        return Carbon::parse($date, Hospital::tz())->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    /** @return array<string, array{bills: float, online: float, ipd: float}> by local date */
    private static function dailyMoney(string $from, string $to): array
    {
        $days = [];
        for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
            $days[$d->toDateString()] = ['bills' => 0.0, 'online' => 0.0, 'ipd' => 0.0];
        }
        $start = self::utc($from);
        $end = self::utc(Carbon::parse($to)->addDay()->toDateString());
        $local = fn (string $utc) => Carbon::parse($utc, 'UTC')->setTimezone(Hospital::tz())->toDateString();

        foreach (DB::table('payments')->where('status', 'success')->where('paid_at', '>=', $start)->where('paid_at', '<', $end)->get(['paid_at', 'amount']) as $p) {
            $days[$local($p->paid_at)]['bills'] += (float) $p->amount; // refunds are negative rows, so they net off
        }
        foreach (DB::table('appointments')->where('payment_mode', 'advance_online')->where('payment_status', 'paid')->where('paid_at', '>=', $start)->where('paid_at', '<', $end)->get(['paid_at', 'advance_amount']) as $a) {
            $days[$local($a->paid_at)]['online'] += (float) $a->advance_amount;
        }
        foreach (DB::table('ipd_entries as e')->join('ipd_heads as h', 'h.id', '=', 'e.head_id')->join('ipd_admissions as a', 'a.id', '=', 'e.admission_id')->whereNull('a.deleted_at')
            ->where('h.type', 'receipt')->whereBetween('e.entry_date', [$from, $to])->get(['e.entry_date', 'e.amount']) as $e) {
            $days[$e->entry_date]['ipd'] += (float) $e->amount;
        }

        return $days;
    }

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = self::range($request);
        $financial = $request->user()->can('reports.financial');

        // appointments
        $appts = fn () => DB::table('appointments as a')->whereBetween('a.appointment_date', [$from, $to]);
        $byStatus = $appts()->groupBy('a.status')->selectRaw('a.status, count(*) as c')->pluck('c', 'status')->map(fn ($c) => (int) $c);
        $byDoctor = $appts()->join('doctors as d', 'd.id', '=', 'a.doctor_id')->join('users as u', 'u.id', '=', 'd.user_id')->groupBy('a.doctor_id', 'u.full_name')
            ->selectRaw("u.full_name as name, count(*) as total, sum(a.status = 'completed') as completed, sum(a.status in ('cancelled','no_show')) as lost")->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => (int) $r->total, 'completed' => (int) $r->completed, 'lost' => (int) $r->lost]);
        $byDept = $appts()->join('departments as dp', 'dp.id', '=', 'a.department_id')->groupBy('a.department_id', 'dp.name')->selectRaw('dp.name, count(*) as total')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($r) => ['name' => $r->name, 'total' => (int) $r->total]);

        // lab
        $labOrders = fn () => DB::table('lab_orders as o')->whereDate('o.created_at', '>=', $from)->whereDate('o.created_at', '<=', $to)->where('o.status', '!=', 'cancelled');
        $labTop = DB::table('lab_order_items as i')->join('lab_orders as o', 'o.id', '=', 'i.order_id')->where('o.status', '!=', 'cancelled')->whereDate('o.created_at', '>=', $from)->whereDate('o.created_at', '<=', $to)
            ->groupBy('i.test_name')->selectRaw('i.test_name as name, count(*) as n, sum(i.price) as amount')->orderByDesc('n')->limit(8)->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n] + ($financial ? ['amount' => self::money($r->amount)] : []));
        $lab = ['orders' => $labOrders()->count(), 'in_patient' => $labOrders()->whereNotNull('o.admission_id')->count(), 'lab_only' => $labOrders()->whereNull('o.admission_id')->count(), 'top_tests' => $labTop];

        // in-patients
        $ipd = ['admitted' => DB::table('ipd_admissions')->whereNull('deleted_at')->whereBetween('admission_date', [$from, $to])->count(),
            'discharged' => DB::table('ipd_admissions')->whereNull('deleted_at')->where('status', 'discharged')->whereBetween('discharge_date', [$from, $to])->count(),
            'in_hospital_now' => DB::table('ipd_admissions')->whereNull('deleted_at')->where('status', 'admitted')->count()];

        $out = ['from' => $from, 'to' => $to, 'financial' => $financial,
            'appointments' => ['total' => $byStatus->sum(), 'by_status' => $byStatus, 'by_doctor' => $byDoctor, 'by_department' => $byDept],
            'lab' => $lab, 'ipd' => $ipd];

        if ($financial) {
            $days = self::dailyMoney($from, $to);
            $sum = fn (string $k) => self::money(collect($days)->sum($k));
            $start = self::utc($from);
            $end = self::utc(Carbon::parse($to)->addDay()->toDateString());
            $byMethod = DB::table('payments')->where('status', 'success')->where('paid_at', '>=', $start)->where('paid_at', '<', $end)->groupBy('method')->selectRaw('method, sum(amount) as amount')->get()
                ->map(fn ($r) => ['method' => $r->method, 'amount' => self::money($r->amount)])->values();
            $ipdTypes = DB::table('ipd_entries as e')->join('ipd_heads as h', 'h.id', '=', 'e.head_id')->join('ipd_admissions as a', 'a.id', '=', 'e.admission_id')->whereNull('a.deleted_at')
                ->whereBetween('e.entry_date', [$from, $to])->groupBy('h.type')->selectRaw('h.type, sum(e.amount) as amount')->pluck('amount', 'type');
            $due = DB::table('bills as b')->join('patients as p', 'p.id', '=', 'b.patient_id')->where('b.status', '!=', 'cancelled')->whereColumn('b.paid_amount', '<', 'b.total_amount');
            $dueTotals = (clone $due)->select(DB::raw('coalesce(sum(b.total_amount - b.paid_amount),0) as amt, count(*) as n'))->first();
            $out['collections'] = [
                'total' => self::money($sum('bills') + $sum('online') + $sum('ipd')),
                'by_source' => ['bills' => $sum('bills'), 'online_advance' => $sum('online'), 'ipd' => $sum('ipd')],
                'by_method' => $byMethod,
                'daily' => collect($days)->map(fn ($v, $date) => ['date' => $date, 'bills' => self::money($v['bills']), 'online_advance' => self::money($v['online']), 'ipd' => self::money($v['ipd']),
                    'total' => self::money($v['bills'] + $v['online'] + $v['ipd'])])->values(),
            ];
            $out['lab']['billed'] = self::money(DB::table('bills as b')->join('lab_orders as o', 'o.bill_id', '=', 'b.id')->where('b.status', '!=', 'cancelled')->whereDate('o.created_at', '>=', $from)->whereDate('o.created_at', '<=', $to)->sum('b.total_amount'));
            $out['ipd'] += ['received' => self::money($ipdTypes['receipt'] ?? 0), 'payouts' => self::money($ipdTypes['payout'] ?? 0), 'charges' => self::money($ipdTypes['charge'] ?? 0)];
            $out['outstanding'] = ['amount' => self::money($dueTotals->amt), 'bills' => (int) $dueTotals->n,
                'top' => (clone $due)->orderByRaw('(b.total_amount - b.paid_amount) desc')->limit(8)->get(['b.id', 'b.bill_number', 'b.bill_date', 'b.total_amount', 'b.paid_amount', 'p.full_name', 'p.patient_code'])
                    ->map(fn ($r) => ['id' => $r->id, 'bill_number' => $r->bill_number, 'bill_date' => $r->bill_date, 'patient_name' => $r->full_name, 'patient_code' => $r->patient_code, 'balance' => self::money($r->total_amount - $r->paid_amount)])->values()];
        }

        return response()->json($out);
    }

    /** The daily collections as a spreadsheet file. */
    public function exportCollections(Request $request): StreamedResponse
    {
        [$from, $to] = self::range($request);
        Audit::record('export', 'collections_report', null, ['from' => $from, 'to' => $to]);
        $days = self::dailyMoney($from, $to);

        return response()->streamDownload(function () use ($days) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Date', 'Bills (counter and lab)', 'Online advance', 'IPD receipts', 'Total']);
            foreach ($days as $date => $v) {
                fputcsv($h, [date('d-m-Y', strtotime($date)), self::money($v['bills']), self::money($v['online']), self::money($v['ipd']), self::money($v['bills'] + $v['online'] + $v['ipd'])]);
            }
            fclose($h);
        }, "collections-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
