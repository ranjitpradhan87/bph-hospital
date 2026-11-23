"use client";

import {
  BarChart3,
  Building2,
  CalendarCheck,
  CalendarClock,
  CalendarX2,
  CheckCircle2,
  IndianRupee,
  Inbox,
  type LucideIcon,
  ReceiptIndianRupee,
  RefreshCw,
  Stethoscope,
  UserRound,
  Users,
} from "lucide-react";
import Link from "next/link";

import { ManagerHome } from "@/app/admin/manager-home";
import { BarChart, BarList } from "@/components/charts/bar-chart";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/lib/auth/auth-provider";
import { formatDay, formatTime } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { AppointmentStatus, DashboardSummary, DayPoint, DepartmentStat, MonthPoint } from "@/types/api";

const inr = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 0 });
const inrCompact = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", notation: "compact", maximumFractionDigits: 1 });

export function AdminHome() {
  const { user, can } = useAuth();
  const canDashboard = can("dashboard.view");
  const canFinance = can("reports.financial");

  if (!user) return null;
  if (!canDashboard && can("dashboard.operations")) return <ManagerHome />;
  if (!canDashboard) return <StaffWelcome name={user.full_name} />;
  return <Dashboard canFinance={canFinance} />;
}

function Dashboard({ canFinance }: { canFinance: boolean }) {
  const summary = useApi<DashboardSummary>("/dashboard/summary");
  const trend = useApi<DayPoint[]>("/dashboard/appointment-trend", { days: 14 });
  const depts = useApi<DepartmentStat[]>("/dashboard/department-stats");
  const revenue = useApi<MonthPoint[]>(canFinance ? "/dashboard/revenue-trend" : null, { months: 6 });
  const s = summary.data;

  const reload = () => {
    summary.reload();
    trend.reload();
    depts.reload();
    revenue.reload();
  };

  return (
    <div className="mx-auto grid max-w-7xl gap-6">
      <PageHeader
        title="Dashboard"
        // as_of is already hospital-local time (not UTC), so format its parts directly.
        description={s ? `Hospital activity as of ${formatDay(s.as_of.slice(0, 10))}, ${formatTime(s.as_of.slice(11, 19))}` : "Hospital activity at a glance"}
        actions={
          <Button variant="outline" onClick={reload} disabled={summary.loading}>
            <RefreshCw className={cn(summary.loading && "animate-spin")} aria-hidden /> Refresh
          </Button>
        }
      />

      {summary.error && <p role="alert" className="text-sm text-destructive">{summary.error.message}</p>}

      {!s ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-busy="true" aria-label="Loading dashboard">
          {Array.from({ length: 8 }, (_, i) => <Skeleton key={i} className="h-28 rounded-xl" />)}
        </div>
      ) : (
        <>
          <section aria-label="Appointments" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <TodayCard summary={s} />
            <StatCard icon={CalendarClock} label="Upcoming appointments" value={s.appointments.upcoming} hint="From now onwards" />
            <StatCard icon={CheckCircle2} label="Completed this month" value={s.appointments.completed_this_month} />
            <StatCard icon={CalendarX2} label="Cancelled this month" value={s.appointments.cancelled_this_month} />
          </section>

          <section aria-label="People" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard icon={UserRound} label="Patients" value={s.patients_total} hint={`${s.patients_new_this_month} new this month`} />
            <StatCard icon={Stethoscope} label="Active doctors" value={s.doctors_active} />
            <StatCard icon={Users} label="Active staff" value={s.staff_active} />
            <StatCard
              icon={Inbox}
              label="New enquiries"
              value={s.enquiries_new}
              href="/admin/enquiries"
              hint={s.enquiries_new ? "Awaiting a reply" : "All caught up"}
              highlight={s.enquiries_new > 0}
            />
          </section>

          {s.revenue && (
            <section aria-label="Revenue" className="grid gap-4 sm:grid-cols-3">
              <StatCard icon={IndianRupee} label="Today's collections" value={inr.format(s.revenue.today)} hint="Net of refunds" />
              <StatCard icon={BarChart3} label="This month's collections" value={inr.format(s.revenue.this_month)} hint="Net of refunds" />
              <StatCard
                icon={ReceiptIndianRupee}
                label="Pending payments"
                value={inr.format(s.revenue.pending_amount)}
                hint={`${s.revenue.pending_bills} unpaid or part-paid bill${s.revenue.pending_bills === 1 ? "" : "s"}`}
              />
            </section>
          )}
        </>
      )}

      <div className="grid gap-4 lg:grid-cols-5">
        <Card className="lg:col-span-3">
          <CardHeader>
            <CardTitle>Appointments, last 14 days</CardTitle>
            <CardDescription>By appointment date</CardDescription>
          </CardHeader>
          <CardContent>
            {!trend.data ? (
              <Skeleton className="h-60 w-full" />
            ) : trend.data.every((d) => d.total === 0) ? (
              <EmptyState icon={CalendarCheck} title="No appointments in this period" description="Bookings will appear here once patients start booking slots." />
            ) : (
              <BarChart
                ariaLabel="Appointments per day for the last 14 days, split into completed, cancelled and other"
                series={[
                  { key: "other", label: "Booked / in progress", color: "var(--viz-1)" },
                  { key: "cancelled", label: "Cancelled", color: "var(--viz-2)" },
                  { key: "completed", label: "Completed", color: "var(--viz-3)" },
                ]}
                data={trend.data.map((d) => ({
                  label: formatDay(d.date, { day: "numeric", month: "short" }),
                  title: formatDay(d.date, { weekday: "short", day: "numeric", month: "short" }),
                  values: { other: d.total - d.completed - d.cancelled, cancelled: d.cancelled, completed: d.completed },
                }))}
              />
            )}
          </CardContent>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>By department, last 30 days</CardTitle>
            <CardDescription>Total appointments</CardDescription>
          </CardHeader>
          <CardContent>
            {!depts.data ? (
              <Skeleton className="h-60 w-full" />
            ) : depts.data.every((d) => d.total === 0) ? (
              <EmptyState icon={Building2} title="No department activity yet" />
            ) : (
              <BarList
                items={depts.data.slice(0, 10).map((d) => ({
                  key: d.department_id,
                  label: d.name,
                  value: d.total,
                  detail: `${d.completed} completed · ${d.cancelled} cancelled · ${d.no_show} no-show`,
                }))}
              />
            )}
          </CardContent>
        </Card>
      </div>

      {canFinance && (
        <Card>
          <CardHeader>
            <CardTitle>Collections, last 6 months</CardTitle>
            <CardDescription>Successful payments received, before refunds</CardDescription>
          </CardHeader>
          <CardContent>
            {!revenue.data ? (
              <Skeleton className="h-60 w-full" />
            ) : revenue.data.every((m) => m.collected === 0 && m.refunded === 0) ? (
              <EmptyState icon={ReceiptIndianRupee} title="No payments recorded yet" description="Collections appear here once billing starts." />
            ) : (
              <BarChart
                ariaLabel="Payments collected per month for the last six months"
                format={(n) => inrCompact.format(n)}
                series={[{ key: "collected", label: "Collected", color: "var(--viz-1)" }]}
                data={revenue.data.map((m) => {
                  const [y, mo] = m.month.split("-").map(Number);
                  const name = new Date(Date.UTC(y, mo - 1, 1)).toLocaleString("en-IN", { month: "short", year: "numeric", timeZone: "UTC" });
                  return { label: name, title: `${name} · refunds ${inr.format(m.refunded)}`, values: { collected: m.collected } };
                })}
              />
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

function TodayCard({ summary }: { summary: DashboardSummary }) {
  const entries = (Object.entries(summary.appointments.today_by_status) as [AppointmentStatus, number][]).filter(([, n]) => n > 0);
  return (
    <Card className="gap-2 py-4">
      <CardContent className="grid gap-2 px-4">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <CalendarCheck className="size-4 text-primary" aria-hidden /> Today&apos;s appointments
        </p>
        <p className="font-heading text-3xl font-bold tabular-nums">{summary.appointments.today_total}</p>
        <div className="flex flex-wrap gap-1">
          {entries.length ? (
            entries.map(([status, n]) => (
              <span key={status} className="inline-flex items-center gap-1 text-xs">
                <StatusBadge status={status} /> <span className="tabular-nums">{n}</span>
              </span>
            ))
          ) : (
            <span className="text-xs text-muted-foreground">None scheduled</span>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  href,
  highlight,
}: {
  icon: LucideIcon;
  label: string;
  value: number | string;
  hint?: string;
  href?: string;
  highlight?: boolean;
}) {
  const body = (
    <Card className={cn("h-full gap-2 py-4 transition-colors", href && "hover:border-primary/40", highlight && "border-warning/60")}>
      <CardContent className="grid gap-1 px-4">
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Icon className="size-4 text-primary" aria-hidden /> {label}
        </p>
        <p className="font-heading text-3xl font-bold tabular-nums">{typeof value === "number" ? value.toLocaleString("en-IN") : value}</p>
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  );
  return href ? (
    <Link href={href} className="rounded-xl focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none">
      {body}
    </Link>
  ) : (
    body
  );
}

/** Staff without dashboard.view (doctors, nurses, front desk) get a simple landing until their own dashboards (Phase 8). */
function StaffWelcome({ name }: { name: string }) {
  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader title={`Welcome, ${name.split(" ")[0]}`} description="Use the menu to get to the tools for your role." />
    </div>
  );
}
