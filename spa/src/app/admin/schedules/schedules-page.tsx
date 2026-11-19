import { CalendarClock, CalendarOff, ChevronLeft, ChevronRight, Loader2, Pencil, Plus, Trash2, Wand2 } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { DAY_NAMES, formatDay, formatTime } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import { type Leave, type Schedule, type SlotRow, addDays, todayIST } from "@/types/booking";
import type { DoctorAdmin } from "@/types/management";

export function SchedulesPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("slots.read")}>
      <SchedulesView />
    </RequireAuth>
  );
}

function SchedulesView() {
  const { user } = useAuth();
  const doctors = useApi<Page<DoctorAdmin>>("/doctors", { page_size: 100 });
  const [doctorId, setDoctorId] = useState<number | null>(user?.doctor_id ?? null);
  useEffect(() => {
    if (doctorId === null && doctors.data?.items.length) setDoctorId(doctors.data.items[0].id);
  }, [doctorId, doctors.data]);
  const doctor = doctors.data?.items.find((d) => d.id === doctorId) ?? null;

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader title="Schedules & slots" description="Weekly sessions, leave, and the time slots patients can book." />
      <div className="mb-4 flex items-center gap-3">
        <Label htmlFor="sched-doctor" className="shrink-0">Doctor</Label>
        <NativeSelect id="sched-doctor" value={doctorId ?? ""} onChange={(e) => setDoctorId(Number(e.target.value))} className="max-w-sm">
          {doctors.data?.items.map((d) => <option key={d.id} value={d.id}>{d.full_name} — {d.specialization}</option>)}
        </NativeSelect>
      </div>
      {!doctor ? (
        doctors.data ? <EmptyState icon={CalendarClock} title="No doctors yet" description="Add a doctor first, then set up their schedule." /> : <Skeleton className="h-40" />
      ) : (
        <Tabs defaultValue="weekly" className="gap-4">
          <TabsList>
            <TabsTrigger value="weekly">Weekly schedule</TabsTrigger>
            <TabsTrigger value="slots">Slots</TabsTrigger>
            <TabsTrigger value="leave">Leave</TabsTrigger>
          </TabsList>
          <TabsContent value="weekly"><WeeklyTab doctor={doctor} /></TabsContent>
          <TabsContent value="slots"><SlotsTab doctor={doctor} /></TabsContent>
          <TabsContent value="leave"><LeaveTab doctor={doctor} /></TabsContent>
        </Tabs>
      )}
    </div>
  );
}

// ---------- weekly schedule ----------

function WeeklyTab({ doctor }: { doctor: DoctorAdmin }) {
  const { can } = useAuth();
  const manage = can("slots.manage");
  const list = useApi<Schedule[]>(`/doctors/${doctor.id}/schedules`);
  const [editing, setEditing] = useState<Schedule | "new" | null>(null);
  const [deleting, setDeleting] = useState<Schedule | null>(null);
  const [generating, setGenerating] = useState(false);
  const byDay = useMemo(() => DAY_NAMES.map((_, i) => (list.data ?? []).filter((s) => s.day_of_week === i)), [list.data]);
  const deptName = (id: number) => doctor.departments.find((d) => d.id === id)?.name ?? "";

  return (
    <Card>
      <CardContent className="grid gap-4">
        {manage && (
          <div className="flex flex-wrap gap-2">
            <Button onClick={() => setEditing("new")}><Plus aria-hidden /> Add session</Button>
            <Button variant="outline" onClick={() => setGenerating(true)} disabled={!list.data?.length}><Wand2 aria-hidden /> Generate slots</Button>
          </div>
        )}
        {!list.data ? <Skeleton className="h-40" /> : list.data.length === 0 ? (
          <EmptyState icon={CalendarClock} title="No weekly sessions" description="Add the days and hours this doctor sees patients, then generate slots." />
        ) : (
          <div className="grid gap-3">
            {byDay.map((items, day) => items.length > 0 && (
              <div key={day} className="grid gap-2 sm:grid-cols-[8rem_1fr]">
                <h3 className="pt-2 text-sm font-semibold">{DAY_NAMES[day]}</h3>
                <div className="grid gap-2">
                  {items.map((s) => (
                    <div key={s.id} className={cn("flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3", !s.is_active && "opacity-60")}>
                      <div className="text-sm">
                        <p className="font-medium">{formatTime(s.start_time)} – {formatTime(s.end_time)} <span className="font-normal text-muted-foreground">· {s.slot_duration_min} min slots · {s.capacity_per_slot} per slot · {s.appointment_type === "online" ? "Online" : "In person"}</span></p>
                        <p className="text-xs text-muted-foreground">
                          {deptName(s.department_id)}
                          {s.breaks.length > 0 && ` · Break ${s.breaks.map((b) => `${formatTime(b.start_time)}–${formatTime(b.end_time)}`).join(", ")}`}
                          {s.effective_to && ` · until ${formatDay(s.effective_to, { day: "numeric", month: "short", year: "numeric" })}`}
                          {!s.is_active && " · paused"}
                        </p>
                      </div>
                      {manage && (
                        <div className="flex gap-1">
                          <Button size="icon-sm" variant="ghost" aria-label="Edit session" onClick={() => setEditing(s)}><Pencil /></Button>
                          <Button size="icon-sm" variant="ghost" aria-label="Delete session" onClick={() => setDeleting(s)}><Trash2 /></Button>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
      {editing && <ScheduleDialog doctor={doctor} schedule={editing === "new" ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); list.reload(); }} />}
      {generating && <GenerateDialog doctor={doctor} onClose={() => setGenerating(false)} />}
      <ConfirmDialog
        open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)} destructive confirmLabel="Delete session" title="Delete this session?"
        description="Slots already generated from it stay (and keep their bookings). Future slots will no longer be created from it."
        onConfirm={async () => {
          if (!deleting) return;
          try { await api(`/schedules/${deleting.id}`, { method: "DELETE" }); toast.success("Session deleted"); list.reload(); }
          catch (err) { toast.error(err instanceof ApiError ? err.message : "Could not delete"); }
        }}
      />
    </Card>
  );
}

function ScheduleDialog({ doctor, schedule, onClose, onSaved }: { doctor: DoctorAdmin; schedule: Schedule | null; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({
    department_id: schedule?.department_id ?? doctor.departments.find((d) => d.is_primary)?.id ?? doctor.departments[0]?.id ?? 0,
    day_of_week: schedule?.day_of_week ?? 0,
    start_time: schedule?.start_time ?? "09:00",
    end_time: schedule?.end_time ?? "13:00",
    slot_duration_min: schedule?.slot_duration_min ?? 15,
    capacity_per_slot: schedule?.capacity_per_slot ?? 1,
    consultation_fee: schedule?.consultation_fee ?? "",
    appointment_type: schedule?.appointment_type ?? "in_person",
    effective_from: schedule?.effective_from ?? todayIST(),
    effective_to: schedule?.effective_to ?? "",
    is_active: schedule?.is_active ?? true,
  });
  const [breaks, setBreaks] = useState(schedule?.breaks.map((b) => ({ start_time: b.start_time, end_time: b.end_time, reason: b.reason ?? "" })) ?? []);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const set = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => setF((p) => ({ ...p, [k]: v }));

  async function save() {
    setBusy(true);
    setError("");
    const body = { ...f, consultation_fee: f.consultation_fee === "" ? null : Number(f.consultation_fee), effective_to: f.effective_to || null, breaks: breaks.map((b) => ({ ...b, reason: b.reason || null })) };
    try {
      if (schedule) await api(`/schedules/${schedule.id}`, { method: "PUT", body });
      else await api(`/doctors/${doctor.id}/schedules`, { method: "POST", body });
      toast.success("Schedule saved");
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save");
    } finally {
      setBusy(false);
    }
  }

  const field = (id: string, label: string, control: React.ReactNode) => <div className="grid gap-1.5"><Label htmlFor={id}>{label}</Label>{control}</div>;
  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{schedule ? "Edit session" : "Add a weekly session"}</DialogTitle>
          <DialogDescription>Repeats every week. Slots are created when you generate them.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 sm:grid-cols-2">
          {field("sc-day", "Day", <NativeSelect id="sc-day" value={f.day_of_week} onChange={(e) => set("day_of_week", Number(e.target.value))}>{DAY_NAMES.map((n, i) => <option key={n} value={i}>{n}</option>)}</NativeSelect>)}
          {field("sc-dept", "Department", <NativeSelect id="sc-dept" value={f.department_id} onChange={(e) => set("department_id", Number(e.target.value))}>{doctor.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</NativeSelect>)}
          {field("sc-start", "Starts", <Input id="sc-start" type="time" value={f.start_time} onChange={(e) => set("start_time", e.target.value)} />)}
          {field("sc-end", "Ends", <Input id="sc-end" type="time" value={f.end_time} onChange={(e) => set("end_time", e.target.value)} />)}
          {field("sc-dur", "Slot length", <NativeSelect id="sc-dur" value={f.slot_duration_min} onChange={(e) => set("slot_duration_min", Number(e.target.value))}>{[10, 15, 20, 30, 45, 60].map((m) => <option key={m} value={m}>{m} minutes</option>)}</NativeSelect>)}
          {field("sc-cap", "Patients per slot", <Input id="sc-cap" type="number" min={1} max={50} value={f.capacity_per_slot} onChange={(e) => set("capacity_per_slot", Number(e.target.value))} />)}
          {field("sc-type", "Visit type", <NativeSelect id="sc-type" value={f.appointment_type} onChange={(e) => set("appointment_type", e.target.value as "in_person" | "online")}><option value="in_person">In person</option><option value="online">Online</option></NativeSelect>)}
          {field("sc-fee", "Fee for these slots (₹)", <Input id="sc-fee" type="number" min={0} placeholder={`Default ${Number(doctor.consultation_fee)}`} value={f.consultation_fee} onChange={(e) => set("consultation_fee", e.target.value)} />)}
          {field("sc-from", "Starts on", <Input id="sc-from" type="date" value={f.effective_from} onChange={(e) => set("effective_from", e.target.value)} />)}
          {field("sc-to", "Ends on (optional)", <Input id="sc-to" type="date" value={f.effective_to} onChange={(e) => set("effective_to", e.target.value)} />)}
        </div>
        <div className="grid gap-2">
          <div className="flex items-center justify-between">
            <Label>Breaks</Label>
            {breaks.length < 4 && <Button size="sm" variant="outline" onClick={() => setBreaks([...breaks, { start_time: "11:00", end_time: "11:15", reason: "" }])}><Plus aria-hidden /> Add break</Button>}
          </div>
          {breaks.map((b, i) => (
            <div key={i} className="flex flex-wrap items-center gap-2">
              <Input aria-label="Break starts" type="time" className="w-32" value={b.start_time} onChange={(e) => setBreaks(breaks.map((x, j) => j === i ? { ...x, start_time: e.target.value } : x))} />
              <span aria-hidden>to</span>
              <Input aria-label="Break ends" type="time" className="w-32" value={b.end_time} onChange={(e) => setBreaks(breaks.map((x, j) => j === i ? { ...x, end_time: e.target.value } : x))} />
              <Input aria-label="Break reason" placeholder="Reason (optional)" className="min-w-32 flex-1" value={b.reason} onChange={(e) => setBreaks(breaks.map((x, j) => j === i ? { ...x, reason: e.target.value } : x))} />
              <Button size="icon-sm" variant="ghost" aria-label="Remove break" onClick={() => setBreaks(breaks.filter((_, j) => j !== i))}><Trash2 /></Button>
            </div>
          ))}
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.is_active} onChange={(e) => set("is_active", e.target.checked)} /> Active (uncheck to pause this session)</label>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={save} disabled={busy}>{busy && <Loader2 className="animate-spin" aria-hidden />} Save</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function GenerateDialog({ doctor, onClose }: { doctor: DoctorAdmin; onClose: () => void }) {
  const [from, setFrom] = useState(todayIST());
  const [to, setTo] = useState(addDays(todayIST(), 27));
  const [publish, setPublish] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function run() {
    setBusy(true);
    setError("");
    try {
      const r = await api<{ created: number; skipped: number; days_closed: string[] }>("/slots/generate", { method: "POST", body: { doctor_id: doctor.id, from, to, publish } });
      toast.success(`${r.created} slot${r.created === 1 ? "" : "s"} created${r.skipped ? `, ${r.skipped} already existed` : ""}${r.days_closed.length ? `, ${r.days_closed.length} day(s) skipped for holidays or leave` : ""}`);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not generate slots");
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Generate slots</DialogTitle>
          <DialogDescription>Creates bookable slots for {doctor.full_name} from the weekly sessions. Existing slots are never changed or duplicated; holidays and leave days are skipped.</DialogDescription>
        </DialogHeader>
        <div className="grid grid-cols-2 gap-4">
          <div className="grid gap-1.5"><Label htmlFor="gn-from">From</Label><Input id="gn-from" type="date" min={todayIST()} value={from} onChange={(e) => setFrom(e.target.value)} /></div>
          <div className="grid gap-1.5"><Label htmlFor="gn-to">To</Label><Input id="gn-to" type="date" min={from} max={addDays(from, 92)} value={to} onChange={(e) => setTo(e.target.value)} /></div>
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={publish} onChange={(e) => setPublish(e.target.checked)} /> Open for booking straight away</label>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={run} disabled={busy || !from || !to}>{busy && <Loader2 className="animate-spin" aria-hidden />} Generate</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------- slots ----------

const BULK = [
  { action: "block", label: "Close" },
  { action: "unblock", label: "Reopen" },
  { action: "publish", label: "Publish" },
  { action: "unpublish", label: "Hide" },
] as const;

function SlotsTab({ doctor }: { doctor: DoctorAdmin }) {
  const { can } = useAuth();
  const manage = can("slots.manage");
  const [from, setFrom] = useState(todayIST());
  const to = addDays(from, 6);
  const list = useApi<Page<SlotRow>>("/slots", { doctor_id: doctor.id, from, to, page_size: 100 });
  const [selected, setSelected] = useState<number[]>([]);
  const [busy, setBusy] = useState(false);
  const byDay = useMemo(() => {
    const m = new Map<string, SlotRow[]>();
    for (const s of list.data?.items ?? []) m.set(s.slot_date, [...(m.get(s.slot_date) ?? []), s]);
    return [...m.entries()];
  }, [list.data]);

  async function bulk(action: string) {
    setBusy(true);
    try {
      const r = await api<{ changed: number }>("/slots/bulk", { method: "POST", body: { action, slot_ids: selected } });
      toast.success(`${r.changed} slot${r.changed === 1 ? "" : "s"} updated`);
      setSelected([]);
      list.reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update slots");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardContent className="grid gap-4">
        <div className="flex flex-wrap items-center gap-2">
          <Button size="icon-sm" variant="outline" aria-label="Previous week" onClick={() => { setFrom(addDays(from, -7)); setSelected([]); }}><ChevronLeft /></Button>
          <span className="min-w-44 text-center text-sm font-medium">{formatDay(from)} – {formatDay(to)}</span>
          <Button size="icon-sm" variant="outline" aria-label="Next week" onClick={() => { setFrom(addDays(from, 7)); setSelected([]); }}><ChevronRight /></Button>
          <Button size="sm" variant="ghost" onClick={() => { setFrom(todayIST()); setSelected([]); }}>This week</Button>
          {manage && selected.length > 0 && (
            <div className="ml-auto flex flex-wrap items-center gap-1" role="group" aria-label="Actions for selected slots">
              <span className="mr-1 text-sm text-muted-foreground">{selected.length} selected</span>
              {BULK.map((b) => <Button key={b.action} size="sm" variant="outline" disabled={busy} onClick={() => bulk(b.action)}>{b.label}</Button>)}
            </div>
          )}
        </div>
        {!list.data ? <Skeleton className="h-40" /> : byDay.length === 0 ? (
          <EmptyState icon={CalendarOff} title="No slots this week" description="Generate slots from the weekly schedule." />
        ) : byDay.map(([day, slots]) => (
          <div key={day}>
            <h3 className="mb-2 text-sm font-semibold">{formatDay(day, { weekday: "long", day: "numeric", month: "long" })}</h3>
            <ul className="flex flex-wrap gap-2">
              {slots.map((s) => {
                const on = selected.includes(s.id);
                return (
                  <li key={s.id}>
                    <button
                      type="button" disabled={!manage} aria-pressed={on}
                      title={`${s.booked_count}/${s.capacity} booked${s.block_reason ? ` · ${s.block_reason}` : ""}${s.is_published ? "" : " · hidden"}`}
                      onClick={() => setSelected(on ? selected.filter((x) => x !== s.id) : [...selected, s.id])}
                      className={cn("flex flex-col items-start rounded-lg border px-3 py-1.5 text-left text-sm", on && "ring-2 ring-primary", !s.is_published && "border-dashed")}
                    >
                      <span className="font-medium tabular-nums">{formatTime(s.start_time)}</span>
                      <StatusBadge status={s.display_status} className="mt-0.5 px-1.5 py-0 text-[10px]" />
                      <span className="text-[10px] text-muted-foreground">{s.booked_count}/{s.capacity}{s.is_published ? "" : " · hidden"}</span>
                    </button>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
        {list.data && list.data.total > 100 && <p className="text-xs text-muted-foreground">Showing the first 100 slots. Narrow the week to see more.</p>}
      </CardContent>
    </Card>
  );
}

// ---------- leave ----------

function LeaveTab({ doctor }: { doctor: DoctorAdmin }) {
  const { can } = useAuth();
  const manage = can("slots.manage");
  const leaves = useApi<Leave[]>(`/doctors/${doctor.id}/leaves`);
  const [start, setStart] = useState(todayIST());
  const [end, setEnd] = useState(todayIST());
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState<Leave | null>(null);

  async function add() {
    setBusy(true);
    try {
      const r = await api<{ slots_closed: number; bookings_affected: number }>(`/doctors/${doctor.id}/leaves`, { method: "POST", body: { start_date: start, end_date: end, reason: reason || null } });
      toast.success(`Leave added — ${r.slots_closed} open slot(s) closed.${r.bookings_affected ? ` ${r.bookings_affected} existing booking(s) need rescheduling.` : ""}`);
      setReason("");
      leaves.reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not add leave");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
      <Card>
        <CardContent className="grid gap-2">
          {!leaves.data ? <Skeleton className="h-24" /> : leaves.data.length === 0 ? (
            <EmptyState icon={CalendarOff} title="No upcoming leave" description="Add dates when this doctor is away to stop bookings." />
          ) : leaves.data.map((l) => (
            <div key={l.id} className="flex items-center justify-between rounded-lg border p-3 text-sm">
              <div>
                <p className="font-medium">{formatDay(l.start_date)}{l.end_date !== l.start_date && ` – ${formatDay(l.end_date)}`}</p>
                {l.reason && <p className="text-xs text-muted-foreground">{l.reason}</p>}
              </div>
              {manage && <Button size="icon-sm" variant="ghost" aria-label="Remove leave" onClick={() => setDeleting(l)}><Trash2 /></Button>}
            </div>
          ))}
        </CardContent>
      </Card>
      {manage && (
        <Card>
          <CardContent className="grid gap-3">
            <h3 className="font-heading font-semibold">Add leave</h3>
            <div className="grid gap-1.5"><Label htmlFor="lv-start">From</Label><Input id="lv-start" type="date" min={todayIST()} value={start} onChange={(e) => { setStart(e.target.value); if (e.target.value > end) setEnd(e.target.value); }} /></div>
            <div className="grid gap-1.5"><Label htmlFor="lv-end">To</Label><Input id="lv-end" type="date" min={start} value={end} onChange={(e) => setEnd(e.target.value)} /></div>
            <div className="grid gap-1.5"><Label htmlFor="lv-reason">Reason (optional)</Label><Input id="lv-reason" value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} /></div>
            <Button onClick={add} disabled={busy || !start || !end}>{busy && <Loader2 className="animate-spin" aria-hidden />} Add leave</Button>
          </CardContent>
        </Card>
      )}
      <ConfirmDialog
        open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)} confirmLabel="Remove leave" title="Remove this leave?"
        description="Slots that were closed for this leave open again."
        onConfirm={async () => {
          if (!deleting) return;
          try { await api(`/leaves/${deleting.id}`, { method: "DELETE" }); toast.success("Leave removed"); leaves.reload(); }
          catch (err) { toast.error(err instanceof ApiError ? err.message : "Could not remove leave"); }
        }}
      />
    </div>
  );
}
