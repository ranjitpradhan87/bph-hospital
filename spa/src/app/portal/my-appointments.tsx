import { CalendarDays, CalendarPlus } from "lucide-react";
import Link from "next/link";
import { useState } from "react";

import { CancelDialog, RescheduleDialog } from "@/app/admin/appointments/appointments-page";
import { PayHeldBooking } from "@/app/portal/book-page";
import { EmptyState } from "@/components/shared/empty-state";
import { StatusBadge } from "@/components/shared/status-badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { formatDay, formatTime } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import type { Page } from "@/types/api";
import { type Appointment, addDays, todayIST } from "@/types/booking";

function Row({ a, onCancel, onResched, onPay }: { a: Appointment; onCancel?: () => void; onResched?: () => void; onPay?: () => void }) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
      <div className="min-w-0">
        <p className="font-medium">{formatDay(a.appointment_date, { weekday: "short", day: "numeric", month: "short", year: "numeric" })} · {formatTime(a.start_time)}</p>
        <p className="text-sm text-muted-foreground">{a.doctor_name} · {a.department_name}</p>
        <p className="text-xs text-muted-foreground">{a.appointment_number}{a.appointment_type === "online" ? " · Online" : ""}</p>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge status={a.status} awaitingPayment={a.payment_status === "awaiting_payment"} />
        {onPay && a.payment_status === "awaiting_payment" && <Button size="sm" onClick={onPay}>Pay advance</Button>}
        {onResched && a.can_reschedule && <Button size="sm" variant="outline" onClick={onResched}>Reschedule</Button>}
        {onCancel && a.can_cancel && <Button size="sm" variant="outline" onClick={onCancel}>Cancel</Button>}
      </div>
    </li>
  );
}

/** The patient's own bookings: upcoming ones with cancel/reschedule, then recent history. */
export function MyAppointments({ showHistory = true }: { showHistory?: boolean }) {
  const upcoming = useApi<Page<Appointment>>("/appointments", { upcoming: true, page_size: 20 });
  const past = useApi<Page<Appointment>>(showHistory ? "/appointments" : null, { to: addDays(todayIST(), -1), page_size: 10 });
  const [cancelling, setCancelling] = useState<Appointment | null>(null);
  const [rescheduling, setRescheduling] = useState<Appointment | null>(null);
  const [paying, setPaying] = useState<Appointment | null>(null);
  const reload = () => { upcoming.reload(); past.reload(); };

  return (
    <div className="grid gap-6">
      <Card>
        <CardHeader className="flex-row items-start justify-between gap-3">
          <div>
            <CardTitle className="font-heading">Upcoming appointments</CardTitle>
            <CardDescription>Cancel or move a visit up to a few hours before it starts.</CardDescription>
          </div>
          <Link href="/portal/book" className={buttonVariants({ size: "sm" })}><CalendarPlus aria-hidden /> Book</Link>
        </CardHeader>
        <CardContent>
          {upcoming.error ? <p role="alert" className="text-sm text-destructive">{upcoming.error.message}</p> : !upcoming.data ? <Skeleton className="h-20" /> : upcoming.data.items.length === 0 ? (
            <EmptyState icon={CalendarDays} title="No upcoming appointments" description="Book a visit with one of our doctors." />
          ) : (
            <ul className="grid gap-2">{upcoming.data.items.map((a) => <Row key={a.id} a={a} onCancel={() => setCancelling(a)} onResched={() => setRescheduling(a)} onPay={() => setPaying(a)} />)}</ul>
          )}
        </CardContent>
      </Card>
      {showHistory && past.data && past.data.items.length > 0 && (
        <Card>
          <CardHeader><CardTitle className="font-heading">Recent visits</CardTitle></CardHeader>
          <CardContent><ul className="grid gap-2">{past.data.items.map((a) => <Row key={a.id} a={a} />)}</ul></CardContent>
        </Card>
      )}
      {cancelling && <CancelDialog appointment={cancelling} onClose={() => setCancelling(null)} onDone={() => { setCancelling(null); reload(); }} />}
      {paying && <PayHeldBooking appointment={paying} onClose={() => { setPaying(null); reload(); }} />}
      {rescheduling && <RescheduleDialog appointment={rescheduling} onClose={() => setRescheduling(null)} onDone={() => { setRescheduling(null); reload(); }} />}
    </div>
  );
}
