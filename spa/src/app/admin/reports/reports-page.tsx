import { Download, Loader2 } from "lucide-react";
import Link from "next/link";
import { useState, type ReactNode } from "react";
import { toast } from "sonner";

import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { downloadFile } from "@/lib/download";
import { inr } from "@/lib/forms";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import { todayIST } from "@/types/booking";
import type { ReportOverview } from "@/types/billing";
import { shiftDays } from "@/types/ipd";

export function ReportsPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("reports.view")}>
      <ReportsView />
    </RequireAuth>
  );
}

const today = () => todayIST();
const PRESETS: { label: string; range: () => { from: string; to: string } }[] = [
  { label: "Today", range: () => ({ from: today(), to: today() }) },
  { label: "Last 7 days", range: () => ({ from: shiftDays(today(), -6), to: today() }) },
  { label: "Last 30 days", range: () => ({ from: shiftDays(today(), -29), to: today() }) },
  { label: "This month", range: () => ({ from: `${today().slice(0, 7)}-01`, to: today() }) },
  { label: "Last month", range: () => {
    const first = new Date(`${today().slice(0, 7)}-01T00:00:00Z`);
    first.setUTCDate(0);
    const last = first.toISOString().slice(0, 10);
    return { from: `${last.slice(0, 7)}-01`, to: last };
  } },
];

const STATUS_LABEL: Record<string, string> = { pending: "Pending", confirmed: "Confirmed", checked_in: "Checked in", in_consultation: "In consultation", completed: "Completed", cancelled: "Cancelled", no_show: "No show" };
const METHOD_LABEL: Record<string, string> = { cash: "Cash", upi: "UPI", card: "Card", cheque: "Cheque", other: "Other", refund: "Refunds" };
const dayLabel = (d: string) => formatDay(d, { day: "numeric", month: "short" });

function Section({ title, hint, actions, children }: { title: string; hint?: string; actions?: ReactNode; children: ReactNode }) {
  return (
    <section className="grid gap-3">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div><h2 className="text-base font-semibold">{title}</h2>{hint && <p className="text-xs text-muted-foreground">{hint}</p>}</div>
        {actions}
      </div>
      {children}
    </section>
  );
}

function Stat({ label, value, tone, sub }: { label: string; value: string | number; tone?: string; sub?: string }) {
  return (
    <Card className="gap-1 p-4">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={cn("text-xl font-semibold tabular-nums", tone)}>{typeof value === "number" ? value.toLocaleString("en-IN") : value}</p>
      {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
    </Card>
  );
}

/** One bar per day, stacked by where the money came from. */
function DailyBars({ daily }: { daily: NonNullable<ReportOverview["collections"]>["daily"] }) {
  const max = Math.max(1, ...daily.map((d) => d.total));
  return (
    <Card className="gap-3 p-4">
      <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-primary" /> Bills (counter and lab)</span>
        <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-info" /> Online advance</span>
        <span className="flex items-center gap-1.5"><i className="size-2.5 rounded-sm bg-success" /> IPD receipts</span>
      </div>
      <div className="flex h-40 items-end gap-1" role="img" aria-label="Collections by day">
        {daily.map((d) => (
          <div key={d.date} className="group relative flex h-full min-w-0 flex-1 flex-col justify-end" title={`${dayLabel(d.date)}: ${inr.format(d.total)}`}>
            <div className="flex w-full flex-col-reverse overflow-hidden rounded-t-sm" style={{ height: `${(d.total / max) * 100}%` }}>
              <div className="bg-primary" style={{ height: `${d.total ? (d.bills / d.total) * 100 : 0}%` }} />
              <div className="bg-info" style={{ height: `${d.total ? (d.online_advance / d.total) * 100 : 0}%` }} />
              <div className="bg-success" style={{ height: `${d.total ? (d.ipd / d.total) * 100 : 0}%` }} />
            </div>
          </div>
        ))}
      </div>
      <div className="flex justify-between text-xs text-muted-foreground"><span>{dayLabel(daily[0].date)}</span><span>Highest day {inr.format(max === 1 ? 0 : max)}</span><span>{dayLabel(daily[daily.length - 1].date)}</span></div>
    </Card>
  );
}

function ReportsView() {
  const { can } = useAuth();
  const [range, setRange] = useState(PRESETS[2].range());
  const [exporting, setExporting] = useState(false);
  const r = useApi<ReportOverview>("/reports/overview", range);
  const d = r.data;

  async function exportCsv() {
    setExporting(true);
    try { await downloadFile(`/reports/collections/export?from=${range.from}&to=${range.to}`, `collections-${range.from}_${range.to}.csv`); }
    catch (err) { toast.error(err instanceof ApiError ? err.message : "Could not export"); }
    finally { setExporting(false); }
  }

  return (
    <div className="mx-auto grid max-w-6xl gap-6">
      <PageHeader title="Reports" description="Money collected, appointments, lab and in-patients for any period." />
      <Card className="flex flex-col gap-3 p-3 lg:flex-row lg:items-center">
        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Quick ranges">
          {PRESETS.map((p) => {
            const v = p.range();
            const on = v.from === range.from && v.to === range.to;
            return <Button key={p.label} size="sm" variant={on ? "default" : "outline"} onClick={() => setRange(v)}>{p.label}</Button>;
          })}
        </div>
        <div className="flex items-center gap-2 lg:ml-auto">
          <Input type="date" aria-label="From date" className="w-40" value={range.from} max={range.to} onChange={(e) => e.target.value && setRange((c) => ({ ...c, from: e.target.value }))} />
          <span className="text-muted-foreground">to</span>
          <Input type="date" aria-label="To date" className="w-40" value={range.to} min={range.from} onChange={(e) => e.target.value && setRange((c) => ({ ...c, to: e.target.value }))} />
        </div>
      </Card>

      {r.error ? <p role="alert" className="text-sm text-destructive">{r.error.message}</p>
      : !d ? <div className="grid gap-3"><Skeleton className="h-24" /><Skeleton className="h-48" /><Skeleton className="h-48" /></div>
      : (
        <>
          {d.collections && d.outstanding && (
            <Section title="Money collected" hint="Counter and lab bills, online advance payments and IPD receipts, counted separately so nothing is added twice."
              actions={can("reports.financial") && <Button size="sm" variant="outline" onClick={exportCsv} disabled={exporting}>{exporting ? <Loader2 className="animate-spin" aria-hidden /> : <Download aria-hidden />} Export CSV</Button>}>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Total collected" value={inr.format(d.collections.total)} tone="text-success" />
                <Stat label="Bills (counter and lab)" value={inr.format(d.collections.by_source.bills)} />
                <Stat label="Online advance" value={inr.format(d.collections.by_source.online_advance)} />
                <Stat label="IPD receipts" value={inr.format(d.collections.by_source.ipd)} />
              </div>
              <DailyBars daily={d.collections.daily} />
              <div className="grid gap-3 lg:grid-cols-2">
                <Card className="gap-2 p-4">
                  <h3 className="text-sm font-semibold">Bill payments by method</h3>
                  {d.collections.by_method.length === 0 ? <p className="text-sm text-muted-foreground">No bill payments in this period.</p> : (
                    <ul className="divide-y text-sm">
                      {d.collections.by_method.map((m) => <li key={m.method} className="flex justify-between py-1.5"><span>{METHOD_LABEL[m.method] ?? m.method}</span><span className={cn("tabular-nums", m.amount < 0 && "text-destructive")}>{inr.format(m.amount)}</span></li>)}
                    </ul>
                  )}
                </Card>
                <Card className="gap-2 p-4">
                  <div className="flex items-baseline justify-between"><h3 className="text-sm font-semibold">Outstanding dues</h3>
                    <span className="text-sm font-semibold text-orange-600 tabular-nums dark:text-orange-400">{inr.format(d.outstanding.amount)}</span></div>
                  <p className="text-xs text-muted-foreground">{d.outstanding.bills} unpaid {d.outstanding.bills === 1 ? "bill" : "bills"}, all dates. Largest first.</p>
                  {d.outstanding.top.length === 0 ? <p className="text-sm text-muted-foreground">Nothing is owed.</p> : (
                    <ul className="divide-y text-sm">
                      {d.outstanding.top.map((o) => (
                        <li key={o.id} className="flex justify-between gap-2 py-1.5">
                          <span className="min-w-0 truncate">{o.patient_name} <span className="font-mono text-xs text-muted-foreground">{o.bill_number}</span></span>
                          <span className="font-medium text-orange-600 tabular-nums dark:text-orange-400">{inr.format(o.balance)}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                  {can("billing.read") && d.outstanding.bills > 0 && <Link href="/admin/billing" className="text-xs text-primary hover:underline">Open billing to collect</Link>}
                </Card>
              </div>
            </Section>
          )}

          <Section title="Appointments" hint="By appointment date.">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Stat label="Total" value={d.appointments.total} />
              <Stat label="Completed" value={d.appointments.by_status.completed ?? 0} tone="text-success" />
              <Stat label="Upcoming" value={(d.appointments.by_status.confirmed ?? 0) + (d.appointments.by_status.pending ?? 0) + (d.appointments.by_status.checked_in ?? 0) + (d.appointments.by_status.in_consultation ?? 0)} />
              <Stat label="Cancelled or no show" value={(d.appointments.by_status.cancelled ?? 0) + (d.appointments.by_status.no_show ?? 0)} tone="text-destructive" />
            </div>
            {d.appointments.total > 0 && (
              <div className="flex flex-wrap gap-2 text-xs">
                {Object.entries(d.appointments.by_status).map(([k, v]) => <span key={k} className="rounded-full bg-muted px-2.5 py-1">{STATUS_LABEL[k] ?? k}: <b>{v}</b></span>)}
              </div>
            )}
            <div className="grid gap-3 lg:grid-cols-2">
              <Card className="gap-0 p-0">
                <h3 className="px-4 pt-3 text-sm font-semibold">Busiest doctors</h3>
                {d.appointments.by_doctor.length === 0 ? <p className="p-4 text-sm text-muted-foreground">No appointments in this period.</p> : (
                  <Table><TableHeader><TableRow><TableHead className="pl-4">Doctor</TableHead><TableHead className="text-right">Total</TableHead><TableHead className="text-right">Done</TableHead><TableHead className="pr-4 text-right">Lost</TableHead></TableRow></TableHeader>
                    <TableBody>{d.appointments.by_doctor.map((x) => <TableRow key={x.name}><TableCell className="pl-4">{x.name}</TableCell><TableCell className="text-right tabular-nums">{x.total}</TableCell><TableCell className="text-right tabular-nums">{x.completed}</TableCell><TableCell className="pr-4 text-right tabular-nums">{x.lost}</TableCell></TableRow>)}</TableBody></Table>
                )}
              </Card>
              <Card className="gap-0 p-0">
                <h3 className="px-4 pt-3 text-sm font-semibold">By department</h3>
                {d.appointments.by_department.length === 0 ? <p className="p-4 text-sm text-muted-foreground">No appointments in this period.</p> : (
                  <Table><TableHeader><TableRow><TableHead className="pl-4">Department</TableHead><TableHead className="pr-4 text-right">Appointments</TableHead></TableRow></TableHeader>
                    <TableBody>{d.appointments.by_department.map((x) => <TableRow key={x.name}><TableCell className="pl-4">{x.name}</TableCell><TableCell className="pr-4 text-right tabular-nums">{x.total}</TableCell></TableRow>)}</TableBody></Table>
                )}
              </Card>
            </div>
          </Section>

          <Section title="In-patients (IPD)">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Stat label="Admitted" value={d.ipd.admitted} />
              <Stat label="Discharged" value={d.ipd.discharged} />
              <Stat label="In hospital now" value={d.ipd.in_hospital_now} sub="Right now, not for the period" />
              {d.ipd.received !== undefined && <Stat label="Received minus paid out" value={inr.format((d.ipd.received ?? 0) - (d.ipd.payouts ?? 0))} sub={`${inr.format(d.ipd.received ?? 0)} in, ${inr.format(d.ipd.payouts ?? 0)} out · billed ${inr.format(d.ipd.charges ?? 0)}`} />}
            </div>
          </Section>

          <Section title="Lab" hint="By the date the order was placed. Cancelled orders are left out.">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Stat label="Orders" value={d.lab.orders} />
              <Stat label="For in-patients" value={d.lab.in_patient} />
              <Stat label="Lab only visits" value={d.lab.lab_only} />
              {d.lab.billed !== undefined && <Stat label="Billed" value={inr.format(d.lab.billed)} />}
            </div>
            <Card className="gap-0 p-0">
              <h3 className="px-4 pt-3 text-sm font-semibold">Most ordered tests</h3>
              {d.lab.top_tests.length === 0 ? <p className="p-4 text-sm text-muted-foreground">No lab orders in this period.</p> : (
                <Table><TableHeader><TableRow><TableHead className="pl-4">Test</TableHead><TableHead className="text-right">Ordered</TableHead>{d.lab.top_tests[0].amount !== undefined && <TableHead className="pr-4 text-right">Value</TableHead>}</TableRow></TableHeader>
                  <TableBody>{d.lab.top_tests.map((t) => <TableRow key={t.name}><TableCell className="pl-4">{t.name}</TableCell><TableCell className="text-right tabular-nums">{t.count}</TableCell>{t.amount !== undefined && <TableCell className="pr-4 text-right tabular-nums">{inr.format(t.amount)}</TableCell>}</TableRow>)}</TableBody></Table>
              )}
            </Card>
          </Section>
        </>
      )}
    </div>
  );
}
