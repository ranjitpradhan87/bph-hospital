"use client";

import { BedDouble, CalendarCheck, ClipboardList, FlaskConical, IndianRupee, type LucideIcon, ReceiptIndianRupee, RefreshCw, UserPlus } from "lucide-react";
import Link from "next/link";

import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useAuth } from "@/lib/auth/auth-provider";
import { formatDay, formatTime } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";

const inr = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 0 });

interface Operations {
  as_of: string;
  appointments: { today_total: number; today_by_status: Record<string, number> };
  opd: { visits: number; waiting: number; in_consultation: number; completed: number; cancelled: number; billed: number; collected: number;
    doctors: { doctor_id: number; name: string; visits: number; waiting: number; in_consultation: number; completed: number }[] };
  ipd: { admitted: number; beds_total: number; beds_occupied: number; admissions_today: number; discharges_today: number; received_today: number; paid_out_today: number };
  lab: { orders_today: number; awaiting_sample: number; in_progress: number; completed_today: number };
  patients_new_today: number;
  unpaid_bills: { count: number; amount: number };
  enquiries_new: number;
}

/** What the manager sees on sign-in: today at the front desk, in OPD, IPD and the lab. */
export function ManagerHome() {
  const { can } = useAuth();
  const ops = useApi<Operations>("/dashboard/operations");
  const o = ops.data;

  return (
    <div className="mx-auto grid max-w-7xl gap-6">
      <PageHeader
        title="Today at the hospital"
        description={o ? `As of ${formatDay(o.as_of.slice(0, 10))}, ${formatTime(o.as_of.slice(11, 19))}` : "OPD, IPD, lab and front desk at a glance"}
        actions={
          <>
            {can("opd.manage") && <Link href="/admin/opd"><Button><ClipboardList aria-hidden /> Register OPD visit</Button></Link>}
            <Button variant="outline" onClick={ops.reload} disabled={ops.loading}><RefreshCw className={cn(ops.loading && "animate-spin")} aria-hidden /> Refresh</Button>
          </>
        }
      />
      {ops.error && <p role="alert" className="text-sm text-destructive">{ops.error.message}</p>}

      {!o ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-busy="true" aria-label="Loading">{Array.from({ length: 8 }, (_, i) => <Skeleton key={i} className="h-28 rounded-xl" />)}</div>
      ) : (
        <>
          <section aria-label="OPD" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Tile icon={ClipboardList} label="OPD patients today" value={o.opd.visits} hint={`${o.opd.completed} seen · ${o.opd.cancelled} cancelled`} href="/admin/opd" />
            <Tile icon={ClipboardList} label="Waiting for the doctor" value={o.opd.waiting} hint={`${o.opd.in_consultation} with a doctor now`} href="/admin/opd" highlight={o.opd.waiting > 0} />
            <Tile icon={IndianRupee} label="OPD fees collected" value={inr.format(o.opd.collected)} hint={`of ${inr.format(o.opd.billed)} billed`} href="/admin/opd" />
            <Tile icon={CalendarCheck} label="Appointments today" value={o.appointments.today_total}
              hint={`${o.appointments.today_by_status.checked_in ?? 0} checked in · ${(o.appointments.today_by_status.pending ?? 0) + (o.appointments.today_by_status.confirmed ?? 0)} yet to arrive`} href="/admin/appointments" />
          </section>

          <section aria-label="IPD and lab" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Tile icon={BedDouble} label="Admitted now" value={o.ipd.admitted} hint={o.ipd.beds_total ? `${o.ipd.beds_occupied} of ${o.ipd.beds_total} beds in use` : "No beds set up"} href="/admin/ipd" />
            <Tile icon={BedDouble} label="Admissions / discharges today" value={`${o.ipd.admissions_today} / ${o.ipd.discharges_today}`} href="/admin/ipd" />
            <Tile icon={IndianRupee} label="IPD received today" value={inr.format(o.ipd.received_today)} hint={`${inr.format(o.ipd.paid_out_today)} paid out`} href="/admin/ipd" />
            <Tile icon={FlaskConical} label="Lab: waiting for sample" value={o.lab.awaiting_sample} hint={`${o.lab.in_progress} in progress · ${o.lab.completed_today} done today`} href="/admin/lab" highlight={o.lab.awaiting_sample > 0} />
          </section>

          <section aria-label="People and money" className="grid gap-4 sm:grid-cols-3">
            <Tile icon={UserPlus} label="New patients today" value={o.patients_new_today} href="/admin/patients" />
            <Tile icon={ReceiptIndianRupee} label="Unpaid bills" value={inr.format(o.unpaid_bills.amount)} hint={`${o.unpaid_bills.count} bill${o.unpaid_bills.count === 1 ? "" : "s"} unpaid or part-paid`} href="/admin/billing" highlight={o.unpaid_bills.count > 0} />
            <Tile icon={FlaskConical} label="Lab orders today" value={o.lab.orders_today} href="/admin/lab" />
          </section>

          <Card>
            <CardHeader>
              <CardTitle className="font-heading text-lg">OPD queue by doctor</CardTitle>
              <CardDescription>Patients registered today and where each doctor stands.</CardDescription>
            </CardHeader>
            <CardContent className="px-0">
              {o.opd.doctors.length === 0 ? <p className="px-6 pb-2 text-sm text-muted-foreground">No OPD visits registered yet today.</p> : (
                <Table>
                  <TableHeader><TableRow><TableHead className="pl-6">Doctor</TableHead><TableHead className="text-right">Patients</TableHead><TableHead className="text-right">Waiting</TableHead><TableHead className="text-right">With doctor</TableHead><TableHead className="pr-6 text-right">Seen</TableHead></TableRow></TableHeader>
                  <TableBody>
                    {o.opd.doctors.map((d) => (
                      <TableRow key={d.doctor_id}>
                        <TableCell className="pl-6 font-medium">{d.name}</TableCell>
                        <TableCell className="text-right tabular-nums">{d.visits}</TableCell>
                        <TableCell className="text-right tabular-nums">{d.waiting}</TableCell>
                        <TableCell className="text-right tabular-nums">{d.in_consultation}</TableCell>
                        <TableCell className="pr-6 text-right tabular-nums">{d.completed}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}

function Tile({ icon: Icon, label, value, hint, href, highlight }: { icon: LucideIcon; label: string; value: number | string; hint?: string; href?: string; highlight?: boolean }) {
  const body = (
    <Card className={cn("h-full gap-2 py-4 transition-colors", href && "hover:border-primary/40", highlight && "border-warning/60")}>
      <CardContent className="grid gap-1 px-4">
        <p className="flex items-center gap-2 text-sm text-muted-foreground"><Icon className="size-4 text-primary" aria-hidden /> {label}</p>
        <p className="font-heading text-3xl font-bold tabular-nums">{typeof value === "number" ? value.toLocaleString("en-IN") : value}</p>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  );
  return href ? <Link href={href} className="rounded-xl focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none">{body}</Link> : body;
}
