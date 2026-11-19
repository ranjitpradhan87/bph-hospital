import { CalendarDays, CalendarPlus, Loader2, MoreHorizontal, Search } from "lucide-react";
import Link from "next/link";
import { useDeferredValue, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { SlotPicker } from "@/components/shared/slot-picker";
import { StatusBadge } from "@/components/shared/status-badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { formatDay, formatTime } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import { type Appointment, addDays, todayIST } from "@/types/booking";
import type { DoctorAdmin, PatientListItem } from "@/types/management";

const PAGE_SIZE = 20;

export function AppointmentsPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.some((p) => p === "appointments.read" || p === "appointments.read_own")}>
      <AppointmentsView />
    </RequireAuth>
  );
}

type Range = "today" | "upcoming" | "past" | "all";

const NEXT_ACTION: Record<string, { to: string; label: string; permission: string } | undefined> = {
  pending: { to: "confirmed", label: "Confirm", permission: "appointments.update_status" },
  confirmed: { to: "checked_in", label: "Check in", permission: "appointments.check_in" },
  checked_in: { to: "in_consultation", label: "Start", permission: "appointments.update_status" },
  in_consultation: { to: "completed", label: "Complete", permission: "appointments.update_status" },
};

function AppointmentsView() {
  const { can, user } = useAuth();
  const isDoctorOnly = !can("appointments.read") && !!user?.doctor_id;
  const [range, setRange] = useState<Range>("today");
  const [date, setDate] = useState("");
  const [doctor, setDoctor] = useState("");
  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const q = useDeferredValue(search.trim());
  const [page, setPage] = useState(1);
  const today = todayIST();

  const bounds = date
    ? { from: date, to: date }
    : range === "today" ? { from: today, to: today }
    : range === "upcoming" ? { from: today, to: undefined }
    : range === "past" ? { from: undefined, to: addDays(today, -1) }
    : { from: undefined, to: undefined };

  const doctors = useApi<Page<DoctorAdmin>>(can("doctors.read") ? "/doctors" : null, { page_size: 100 });
  const list = useApi<Page<Appointment>>("/appointments", { ...bounds, doctor_id: doctor || undefined, status: status || undefined, q: q || undefined, upcoming: range === "upcoming" || range === "today" ? true : undefined, page, page_size: PAGE_SIZE });
  const [booking, setBooking] = useState(false);
  const [cancelling, setCancelling] = useState<Appointment | null>(null);
  const [rescheduling, setRescheduling] = useState<Appointment | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const data = list.data;
  const reset = <T,>(fn: (v: T) => void) => (v: T) => { fn(v); setPage(1); };

  async function move(a: Appointment, to: string) {
    setBusyId(a.id);
    try {
      await api(`/appointments/${a.id}/status`, { method: "POST", body: { status: to } });
      toast.success(`${a.patient_name}: ${to.replace("_", " ")}`);
      list.reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update the appointment");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title={isDoctorOnly ? "My appointments" : "Appointments"}
        description={isDoctorOnly ? "Your patients for the day. Start and complete visits here." : "Bookings, the front-desk queue and visit progress."}
        actions={can("appointments.create") && can("patients.read") && <Button onClick={() => setBooking(true)}><CalendarPlus aria-hidden /> Book appointment</Button>}
      />
      <Card className="gap-0 p-0">
        <div className="flex flex-col gap-2 border-b p-3 lg:flex-row">
          <div className="flex gap-1" role="group" aria-label="Period">
            {(["today", "upcoming", "past", "all"] as Range[]).map((r) => (
              <Button key={r} size="sm" variant={range === r && !date ? "default" : "outline"} onClick={() => { setRange(r); setDate(""); setPage(1); }} className="capitalize">{r}</Button>
            ))}
          </div>
          <Input type="date" aria-label="Pick a date" className="lg:w-40" value={date} onChange={(e) => reset(setDate)(e.target.value)} />
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input type="search" aria-label="Search appointments" placeholder="Patient name, ID, mobile or appointment no." className="pl-8" value={search} onChange={(e) => reset(setSearch)(e.target.value)} />
          </div>
          {!isDoctorOnly && doctors.data && (
            <NativeSelect aria-label="Doctor" value={doctor} onChange={(e) => reset(setDoctor)(e.target.value)} className="lg:w-44">
              <option value="">All doctors</option>
              {doctors.data.items.map((d) => <option key={d.id} value={d.id}>{d.full_name}</option>)}
            </NativeSelect>
          )}
          <NativeSelect aria-label="Status" value={status} onChange={(e) => reset(setStatus)(e.target.value)} className="lg:w-40">
            <option value="">Any status</option>
            {["pending", "confirmed", "checked_in", "in_consultation", "completed", "cancelled", "no_show"].map((s) => <option key={s} value={s}>{s.replace("_", " ")}</option>)}
          </NativeSelect>
        </div>
        {list.error ? (
          <p role="alert" className="p-6 text-sm text-destructive">{list.error.message}</p>
        ) : !data ? (
          <div className="grid gap-3 p-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-14" />)}</div>
        ) : data.items.length === 0 ? (
          <EmptyState icon={CalendarDays} title="No appointments" description={range === "today" && !date ? "Nothing booked for today yet." : "Try another period or filter."} />
        ) : (
          <div className={cn("transition-opacity", list.loading && "opacity-60")}>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">When</TableHead>
                  <TableHead>Patient</TableHead>
                  {!isDoctorOnly && <TableHead className="hidden md:table-cell">Doctor</TableHead>}
                  <TableHead>Status</TableHead>
                  <TableHead className="pr-4 text-right"><span className="sr-only">Actions</span></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.items.map((a) => {
                  const next = NEXT_ACTION[a.status];
                  const allowed = next && can(next.permission);
                  const canNoShow = ["pending", "confirmed", "checked_in"].includes(a.status) && can("appointments.update_status");
                  const hasMenu = a.can_cancel || a.can_reschedule || canNoShow;
                  return (
                    <TableRow key={a.id}>
                      <TableCell className="pl-4">
                        <span className="block font-medium">{formatDay(a.appointment_date)} · {formatTime(a.start_time)}</span>
                        <span className="text-xs text-muted-foreground">{a.appointment_number}{a.queue_number ? ` · Queue #${a.queue_number}` : ""}</span>
                      </TableCell>
                      <TableCell>
                        {can("patients.read") ? <Link href={`/admin/patients/${a.patient_id}`} className="hover:text-primary">{a.patient_name}</Link> : a.patient_name}
                        <span className="block text-xs text-muted-foreground">{a.patient_code}{a.patient_mobile ? ` · ${a.patient_mobile}` : ""}</span>
                      </TableCell>
                      {!isDoctorOnly && <TableCell className="hidden md:table-cell">{a.doctor_name}<span className="block text-xs text-muted-foreground">{a.department_name}</span></TableCell>}
                      <TableCell><StatusBadge status={a.status} awaitingPayment={a.payment_status === "awaiting_payment"} /></TableCell>
                      <TableCell className="pr-4">
                        <div className="flex justify-end gap-1">
                          {allowed && (
                            <Button size="sm" variant="outline" disabled={busyId === a.id} onClick={() => move(a, next.to)}>
                              {busyId === a.id && <Loader2 className="animate-spin" aria-hidden />}{next.label}
                            </Button>
                          )}
                          {hasMenu && (
                            <DropdownMenu>
                              <DropdownMenuTrigger render={<Button variant="ghost" size="icon-sm" aria-label={`More actions for ${a.patient_name}`} />}><MoreHorizontal /></DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                {a.can_reschedule && <DropdownMenuItem onClick={() => setRescheduling(a)}>Reschedule</DropdownMenuItem>}
                                {canNoShow && <DropdownMenuItem onClick={() => move(a, "no_show")}>Mark no-show</DropdownMenuItem>}
                                {a.can_cancel && <DropdownMenuItem variant="destructive" onClick={() => setCancelling(a)}>Cancel appointment</DropdownMenuItem>}
                              </DropdownMenuContent>
                            </DropdownMenu>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
            <Pagination page={page} total={data.total} pageSize={PAGE_SIZE} onPage={setPage} noun="appointment" />
          </div>
        )}
      </Card>
      {booking && <BookDialog doctors={doctors.data?.items ?? []} onClose={() => setBooking(false)} onDone={() => { setBooking(false); list.reload(); }} />}
      {cancelling && <CancelDialog appointment={cancelling} onClose={() => setCancelling(null)} onDone={() => { setCancelling(null); list.reload(); }} />}
      {rescheduling && <RescheduleDialog appointment={rescheduling} onClose={() => setRescheduling(null)} onDone={() => { setRescheduling(null); list.reload(); }} />}
    </div>
  );
}

function BookDialog({ doctors, onClose, onDone }: { doctors: DoctorAdmin[]; onClose: () => void; onDone: () => void }) {
  const [term, setTerm] = useState("");
  const deferred = useDeferredValue(term.trim());
  const patients = useApi<Page<PatientListItem>>(deferred.length >= 2 ? "/patients" : null, { search: deferred, page_size: 6 });
  const [patient, setPatient] = useState<PatientListItem | null>(null);
  const [doctorId, setDoctorId] = useState<number | null>(null);
  const [slot, setSlot] = useState<number | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const active = doctors.filter((d) => d.status === "active");

  async function submit() {
    if (!patient || !slot) return;
    setBusy(true);
    setError("");
    try {
      const a = await api<Appointment>("/appointments", { method: "POST", body: { slot_id: slot, patient_id: patient.id, reason: reason || null } });
      toast.success(`Booked ${a.patient_name} for ${formatDay(a.appointment_date)} at ${formatTime(a.start_time)}`);
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not book the appointment");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>Book an appointment</DialogTitle>
          <DialogDescription>Find the patient, choose a doctor and an open time.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-1.5">
            <Label htmlFor="bk-patient">Patient</Label>
            {patient ? (
              <div className="flex items-center justify-between rounded-lg border p-2 text-sm">
                <span><b>{patient.full_name}</b> <span className="text-muted-foreground">{patient.patient_code} · {patient.mobile}</span></span>
                <Button size="sm" variant="ghost" onClick={() => setPatient(null)}>Change</Button>
              </div>
            ) : (
              <>
                <Input id="bk-patient" placeholder="Search name, ID or mobile" value={term} onChange={(e) => setTerm(e.target.value)} autoComplete="off" />
                {patients.data && patients.data.items.length > 0 && (
                  <ul className="grid gap-1 rounded-lg border p-1">
                    {patients.data.items.map((p) => (
                      <li key={p.id}><button type="button" className="w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted" onClick={() => setPatient(p)}>
                        <b>{p.full_name}</b> <span className="text-muted-foreground">{p.patient_code} · {p.mobile}</span>
                      </button></li>
                    ))}
                  </ul>
                )}
                {patients.data && patients.data.items.length === 0 && <p className="text-xs text-muted-foreground">No match. <Link href="/admin/patients" className="text-primary underline">Register the patient first</Link>.</p>}
              </>
            )}
          </div>
          <div className="grid gap-1.5">
            <Label htmlFor="bk-doctor">Doctor</Label>
            <NativeSelect id="bk-doctor" value={doctorId ?? ""} onChange={(e) => { setDoctorId(e.target.value ? Number(e.target.value) : null); setSlot(null); }}>
              <option value="">Choose a doctor</option>
              {active.map((d) => <option key={d.id} value={d.id}>{d.full_name} — {d.specialization}</option>)}
            </NativeSelect>
          </div>
          {doctorId && <SlotPicker key={doctorId} doctorId={doctorId} value={slot} onChange={(id) => setSlot(id)} />}
          <div className="grid gap-1.5">
            <Label htmlFor="bk-reason">Reason for visit <span className="font-normal text-muted-foreground">(optional)</span></Label>
            <Textarea id="bk-reason" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={submit} disabled={busy || !patient || !slot}>{busy && <Loader2 className="animate-spin" aria-hidden />} Book</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function CancelDialog({ appointment, onClose, onDone }: { appointment: Appointment; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function submit() {
    setBusy(true);
    try {
      await api(`/appointments/${appointment.id}/cancel`, { method: "POST", body: { reason: reason || null } });
      toast.success("Appointment cancelled — the slot is open again");
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not cancel");
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Cancel this appointment?</DialogTitle>
          <DialogDescription>{appointment.patient_name} with {appointment.doctor_name}, {formatDay(appointment.appointment_date)} at {formatTime(appointment.start_time)}. The patient is notified by email.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-1.5">
          <Label htmlFor="cancel-reason">Reason <span className="font-normal text-muted-foreground">(optional)</span></Label>
          <Textarea id="cancel-reason" rows={2} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Keep it</Button>
          <Button variant="destructive" onClick={submit} disabled={busy}>{busy && <Loader2 className="animate-spin" aria-hidden />} Cancel appointment</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function RescheduleDialog({ appointment, onClose, onDone }: { appointment: Appointment; onClose: () => void; onDone: () => void }) {
  const [slot, setSlot] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function submit() {
    if (!slot) return;
    setBusy(true);
    try {
      const a = await api<Appointment>(`/appointments/${appointment.id}/reschedule`, { method: "POST", body: { slot_id: slot } });
      toast.success(`Moved to ${formatDay(a.appointment_date)} at ${formatTime(a.start_time)}`);
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not reschedule");
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Reschedule appointment</DialogTitle>
          <DialogDescription>Now: {formatDay(appointment.appointment_date)} at {formatTime(appointment.start_time)} with {appointment.doctor_name}. Choose a new time with the same doctor.</DialogDescription>
        </DialogHeader>
        <SlotPicker doctorId={appointment.doctor_id} value={slot} onChange={(id) => setSlot(id)} id="rs-date" />
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={submit} disabled={busy || !slot}>{busy && <Loader2 className="animate-spin" aria-hidden />} Move appointment</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
