import { CalendarDays, ChevronLeft, ChevronRight, ClipboardList, Download, Loader2, Plus, Printer, Search, Stethoscope, Trash2 } from "lucide-react";
import { Fragment, useDeferredValue, useMemo, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { printOpdSlip, printPrescription } from "@/lib/print-report";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import type { Page } from "@/types/api";
import { todayIST } from "@/types/booking";
import { type DateRange, addMonths, isFullMonth, monthRange, rangeLabel, shiftDays } from "@/types/ipd";
import { cn } from "@/lib/utils";
import type { LabLookups } from "@/types/lab";
import { type Medicine, type OpdDoctor, type OpdSummary, type OpdVisit, type OpdVisitRow, VISIT_STATUS_LABEL, type VisitStatus } from "@/types/opd";
import type { PatientListItem } from "@/types/management";

const PAGE_SIZE = 100;
const inr = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 0 });
const METHODS = [["cash", "Cash"], ["upi", "UPI"], ["card", "Card"], ["cheque", "Cheque"], ["other", "Other"]] as const;

export function OpdPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("opd.read")}>
      <OpdView />
    </RequireAuth>
  );
}

const statusVariant: Record<VisitStatus, "default" | "secondary" | "outline" | "destructive"> = { waiting: "secondary", in_consultation: "default", completed: "outline", cancelled: "destructive" };

const longDay = (d: string) => formatDay(d, { weekday: "long", day: "numeric", month: "long", year: "numeric" });

function stepRange(r: DateRange, dir: 1 | -1): DateRange {
  if (isFullMonth(r)) return monthRange(addMonths(r.from.slice(0, 7), dir));
  const len = Math.round((Date.parse(r.to) - Date.parse(r.from)) / 86_400_000) + 1;
  return { from: shiftDays(r.from, dir * len), to: shiftDays(r.to, dir * len) };
}

function OpdView() {
  const { can } = useAuth();
  const [range, setRange] = useState<DateRange>(monthRange(todayIST().slice(0, 7)));
  const [status, setStatus] = useState("");
  const [doctorId, setDoctorId] = useState("");
  const [search, setSearch] = useState("");
  const q = useDeferredValue(search.trim());
  const [page, setPage] = useState(1);
  const [adding, setAdding] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const filters = { from: range.from, to: range.to, status: status || undefined, doctor_id: doctorId || undefined, q: q || undefined };
  const list = useApi<Page<OpdVisitRow>>("/opd/visits", { ...filters, page, page_size: PAGE_SIZE });
  const summary = useApi<OpdSummary>("/opd/summary", filters);
  const doctors = useApi<OpdDoctor[]>("/opd/doctors");
  const reload = () => { list.reload(); summary.reload(); };
  const data = list.data;
  const s = summary.data;
  const fullMonth = isFullMonth(range);
  const move = (r: DateRange) => { setRange(r); setPage(1); };

  // Visits grouped under their date, newest day first.
  const groups = useMemo(() => {
    const m = new Map<string, OpdVisitRow[]>();
    for (const v of data?.items ?? []) m.set(v.visit_date, [...(m.get(v.visit_date) ?? []), v]);
    return [...m.entries()];
  }, [data]);

  async function exportCsv() {
    try {
      const qs = new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString();
      const res = await fetch(`/api/v1/opd/export?${qs}`, { credentials: "same-origin" });
      if (!res.ok) throw new Error();
      const url = URL.createObjectURL(await res.blob());
      const a = document.createElement("a");
      a.href = url; a.download = `opd-register-${range.from}-to-${range.to}.csv`; a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast.error("Could not download the register");
    }
  }

  const feeCell = (v: OpdVisitRow) => (v.bill ? <>{inr.format(v.bill.paid)} <span className="text-muted-foreground">/ {inr.format(v.bill.total)}</span></> : "—");
  const loadingBlock = <div className="grid gap-3 p-4">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>;
  const empty = <EmptyState icon={Stethoscope} title="No OPD visits" description={`Nothing registered for ${rangeLabel(range)}.`} />;

  return (
    <div>
      <PageHeader title="OPD register" description="Walk-ins and booked patients, day by day: token, consultation, fee and lab tests."
        actions={<>
          <Button variant="outline" onClick={exportCsv}><Download aria-hidden /> Export</Button>
          {can("opd.manage") && <Button onClick={() => setAdding(true)}><Plus aria-hidden /> Register visit</Button>}
        </>} />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Button size="icon-sm" variant="outline" aria-label={fullMonth ? "Previous month" : "Previous period"} onClick={() => move(stepRange(range, -1))}><ChevronLeft /></Button>
        <h2 className="min-w-40 text-center font-heading text-lg font-semibold">{rangeLabel(range)}</h2>
        <Button size="icon-sm" variant="outline" aria-label={fullMonth ? "Next month" : "Next period"} onClick={() => move(stepRange(range, 1))}><ChevronRight /></Button>
        <Button size="sm" variant="ghost" onClick={() => move(monthRange(todayIST().slice(0, 7)))}>This month</Button>
        <Button size="sm" variant="ghost" onClick={() => move({ from: todayIST(), to: todayIST() })}>Today</Button>
        <Button size="sm" variant="ghost" onClick={() => move({ from: shiftDays(todayIST(), -6), to: todayIST() })}>Last 7 days</Button>
        <div className="flex flex-wrap items-end gap-2 sm:ml-auto" role="group" aria-label="Date filter">
          <div className="grid gap-1"><Label htmlFor="opd-from" className="text-xs">From</Label>
            <Input id="opd-from" type="date" className="h-9 w-40" value={range.from} max={range.to}
                   onChange={(e) => e.target.value && move({ from: e.target.value, to: e.target.value > range.to ? e.target.value : range.to })} /></div>
          <div className="grid gap-1"><Label htmlFor="opd-to" className="text-xs">To</Label>
            <Input id="opd-to" type="date" className="h-9 w-40" value={range.to} min={range.from}
                   onChange={(e) => e.target.value && move({ from: e.target.value < range.from ? e.target.value : range.from, to: e.target.value })} /></div>
        </div>
      </div>

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
        {[["Patients", s ? String(s.visits) : null, s ? `${s.waiting} waiting · ${s.in_consultation} with doctor` : ""], ["Seen", s ? String(s.completed) : null, ""],
          ["Fee billed", s ? inr.format(s.billed) : null, ""], ["Collected", s ? inr.format(s.collected) : null, s ? `${inr.format(s.billed - s.collected)} pending` : ""], ["Lab orders", s ? String(s.lab_orders) : null, ""]].map(([label, value, hint]) => (
          <Card key={label} className="gap-1 p-4">
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            {value === null ? <Skeleton className="h-7 w-20" /> : <p className="text-xl font-semibold tabular-nums">{value}</p>}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
          </Card>
        ))}
      </div>

      <Tabs defaultValue="days" className="gap-3">
        <TabsList>
          <TabsTrigger value="days"><CalendarDays className="size-4" aria-hidden /> Day-wise</TabsTrigger>
          <TabsTrigger value="register"><ClipboardList className="size-4" aria-hidden /> Register</TabsTrigger>
        </TabsList>

        <div className="flex flex-col gap-2 sm:flex-row">
          <div className="relative flex-1">
            <Search className="absolute left-2.5 top-2.5 size-4 text-muted-foreground" aria-hidden />
            <Input type="search" aria-label="Search visits" placeholder="Patient, ID, mobile or doctor" className="pl-8" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
          </div>
          <NativeSelect aria-label="Doctor" className="sm:w-52" value={doctorId} onChange={(e) => { setDoctorId(e.target.value); setPage(1); }}>
            <option value="">All doctors</option>
            {(doctors.data ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </NativeSelect>
          <NativeSelect aria-label="Status" className="sm:w-40" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">All statuses</option>
            {(Object.keys(VISIT_STATUS_LABEL) as VisitStatus[]).map((k) => <option key={k} value={k}>{VISIT_STATUS_LABEL[k]}</option>)}
          </NativeSelect>
        </div>

        {/* ---- day by day ---- */}
        <TabsContent value="days">
          {!data ? <Card>{loadingBlock}</Card> : data.items.length === 0 ? <Card>{empty}</Card> : (
            <div className={cn("grid gap-3 transition-opacity", list.loading && "opacity-60")}>
              {groups.map(([date, rows]) => {
                const billed = rows.reduce((t, v) => t + (v.bill?.total ?? 0), 0);
                const paid = rows.reduce((t, v) => t + (v.bill?.paid ?? 0), 0);
                return (
                  <Card key={date} className="gap-0 p-0">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/50 px-4 py-2.5">
                      <h3 className="font-heading font-semibold">{longDay(date)}</h3>
                      <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm tabular-nums">
                        <span>Patients <b>{rows.length}</b></span>
                        <span>Billed <b>{inr.format(billed)}</b></span>
                        <span>Collected <b>{inr.format(paid)}</b></span>
                      </div>
                    </div>
                    <ul className="grid gap-1 p-2">
                      {rows.map((v) => (
                        <li key={v.id}>
                          <button type="button" className="flex w-full items-center justify-between gap-3 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted" onClick={() => setOpen(v.id)}>
                            <span><b className="font-medium">#{v.token_number} {v.patient_name}</b>
                              <span className="block text-xs text-muted-foreground">{v.doctor_name}{v.department ? ` · ${v.department}` : ""}{v.source === "appointment" ? " · Booked" : ""}{v.lab_count ? ` · ${v.lab_count} lab` : ""}</span></span>
                            <span className="flex items-center gap-3"><span className="hidden tabular-nums sm:inline">{feeCell(v)}</span><Badge variant={statusVariant[v.status]}>{VISIT_STATUS_LABEL[v.status]}</Badge></span>
                          </button>
                        </li>
                      ))}
                    </ul>
                  </Card>
                );
              })}
              <Pagination page={page} total={data.total} pageSize={PAGE_SIZE} onPage={setPage} noun="visit" />
            </div>
          )}
        </TabsContent>

        {/* ---- table register ---- */}
        <TabsContent value="register">
          <Card className="p-0">
            {!data ? loadingBlock : data.items.length === 0 ? empty : (
              <>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="pl-4">Token</TableHead><TableHead>Patient</TableHead><TableHead className="hidden md:table-cell">Doctor</TableHead>
                      <TableHead>Status</TableHead><TableHead className="hidden text-right sm:table-cell">Fee</TableHead><TableHead className="pr-4 text-right">Lab</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {groups.map(([date, rows]) => (
                      <Fragment key={date}>
                        <TableRow className="bg-muted/60 hover:bg-muted/60">
                          <TableCell colSpan={6} className="py-1.5 pl-4 text-xs font-semibold tracking-wide uppercase">
                            {longDay(date)} <span className="font-normal text-muted-foreground normal-case">· {rows.length} visit{rows.length === 1 ? "" : "s"}</span>
                          </TableCell>
                        </TableRow>
                        {rows.map((v) => (
                          <TableRow key={v.id} className="cursor-pointer" onClick={() => setOpen(v.id)}>
                            <TableCell className="pl-4 font-semibold tabular-nums">#{v.token_number}</TableCell>
                            <TableCell>{v.patient_name}<span className="block text-xs text-muted-foreground">{v.patient_code}{v.patient_mobile ? ` · ${v.patient_mobile}` : ""}{v.source === "appointment" ? " · Booked" : ""}</span></TableCell>
                            <TableCell className="hidden md:table-cell">{v.doctor_name}<span className="block text-xs text-muted-foreground">{v.department}</span></TableCell>
                            <TableCell><Badge variant={statusVariant[v.status]}>{VISIT_STATUS_LABEL[v.status]}</Badge></TableCell>
                            <TableCell className="hidden text-right tabular-nums sm:table-cell">{feeCell(v)}</TableCell>
                            <TableCell className="pr-4 text-right tabular-nums">{v.lab_count || "—"}</TableCell>
                          </TableRow>
                        ))}
                      </Fragment>
                    ))}
                  </TableBody>
                  {s && (
                    <TableFooter>
                      <TableRow>
                        <TableCell colSpan={4} className="pl-4 font-semibold">Total · {s.visits} visits</TableCell>
                        <TableCell className="hidden text-right font-semibold tabular-nums sm:table-cell">{inr.format(s.collected)} <span className="font-normal text-muted-foreground">/ {inr.format(s.billed)}</span></TableCell>
                        <TableCell className="pr-4 text-right font-semibold tabular-nums">{s.lab_orders}</TableCell>
                      </TableRow>
                    </TableFooter>
                  )}
                </Table>
                <Pagination page={page} total={data.total} pageSize={PAGE_SIZE} onPage={setPage} noun="visit" />
              </>
            )}
          </Card>
        </TabsContent>
      </Tabs>

      {adding && <RegisterDialog doctors={doctors.data ?? []} onClose={() => setAdding(false)} onDone={(id) => { setAdding(false); reload(); setOpen(id); }} />}
      {open !== null && <VisitDialog id={open} onClose={() => { setOpen(null); reload(); }} />}
    </div>
  );
}

// ---------- register ----------

export function RegisterDialog({ doctors, appointmentId, onClose, onDone }: { doctors: OpdDoctor[]; appointmentId?: number; onClose: () => void; onDone: (id: number) => void }) {
  const [term, setTerm] = useState("");
  const deferred = useDeferredValue(term.trim());
  const patients = useApi<Page<PatientListItem>>(!appointmentId && deferred.length >= 2 ? "/patients" : null, { search: deferred, page_size: 5 });
  const [patient, setPatient] = useState<PatientListItem | null>(null);
  const [name, setName] = useState("");
  const [mobile, setMobile] = useState("");
  const [doctorId, setDoctorId] = useState("");
  const [reason, setReason] = useState("");
  const [discount, setDiscount] = useState("");
  const [pay, setPay] = useState("");
  const [method, setMethod] = useState("cash");
  const { can } = useAuth();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const doctor = doctors.find((d) => String(d.id) === doctorId);
  const due = Math.max(0, (doctor?.fee ?? 0) - (Number(discount) || 0));

  async function submit() {
    setBusy(true);
    setError("");
    try {
      const body: Record<string, unknown> = { reason: reason || null, discount: Number(discount) || undefined };
      if (appointmentId) body.appointment_id = appointmentId;
      else Object.assign(body, { doctor_id: Number(doctorId), ...(patient ? { patient_id: patient.id } : { patient_name: name, patient_mobile: mobile || null }) });
      if (Number(pay) > 0) body.payment = { amount: Number(pay), method };
      const v = await api<OpdVisit>("/opd/visits", { method: "POST", body });
      toast.success(`Token ${v.token_number} for ${v.patient_name}`);
      onDone(v.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not register the visit");
      setBusy(false);
    }
  }
  const ready = appointmentId ? true : !!doctorId && (!!patient || name.trim().length >= 2);

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{appointmentId ? "Start OPD visit" : "Register an OPD visit"}</DialogTitle>
          <DialogDescription>{appointmentId ? "The booking is checked in and the patient gets a token." : "For a patient who came without an appointment."}</DialogDescription>
        </DialogHeader>
        <div className="grid gap-3">
          {!appointmentId && (
            <>
              <div className="grid gap-1.5">
                <Label htmlFor="opd-patient">Patient</Label>
                {patient ? (
                  <div className="flex items-center justify-between rounded-lg border p-2 text-sm">
                    <span><b>{patient.full_name}</b> <span className="text-muted-foreground">{patient.patient_code} · {patient.mobile}</span></span>
                    <Button size="sm" variant="ghost" onClick={() => setPatient(null)}>Change</Button>
                  </div>
                ) : (
                  <>
                    <Input id="opd-patient" placeholder="Search a registered patient: name, ID or mobile" value={term} onChange={(e) => setTerm(e.target.value)} autoComplete="off" />
                    {patients.data && patients.data.items.length > 0 && (
                      <ul className="grid gap-1 rounded-lg border p-1">
                        {patients.data.items.map((p) => <li key={p.id}><button type="button" className="w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted" onClick={() => setPatient(p)}><b>{p.full_name}</b> <span className="text-muted-foreground">{p.patient_code} · {p.mobile}</span></button></li>)}
                      </ul>
                    )}
                    <p className="text-xs text-muted-foreground">Or a new patient — enter the name and mobile and they are registered now:</p>
                    <div className="grid grid-cols-2 gap-2">
                      <Input aria-label="New patient name" placeholder="Full name" value={name} onChange={(e) => setName(e.target.value)} />
                      <Input aria-label="New patient mobile" placeholder="Mobile (optional)" inputMode="numeric" value={mobile} onChange={(e) => setMobile(e.target.value)} />
                    </div>
                  </>
                )}
              </div>
              <div className="grid gap-1.5">
                <Label htmlFor="opd-doctor">Doctor</Label>
                <NativeSelect id="opd-doctor" value={doctorId} onChange={(e) => { setDoctorId(e.target.value); setPay(""); }}>
                  <option value="">Choose a doctor</option>
                  {doctors.map((d) => <option key={d.id} value={d.id}>{d.name} — {d.specialization} ({inr.format(d.fee)})</option>)}
                </NativeSelect>
              </div>
            </>
          )}
          <div className="grid gap-1.5">
            <Label htmlFor="opd-reason">Reason for visit <span className="font-normal text-muted-foreground">(optional)</span></Label>
            <Textarea id="opd-reason" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
          </div>
          {can("payments.collect") && (
            <div className="grid grid-cols-3 gap-2">
              <div className="grid gap-1.5"><Label htmlFor="opd-discount">Discount (₹)</Label><Input id="opd-discount" type="number" min={0} value={discount} onChange={(e) => setDiscount(e.target.value)} /></div>
              <div className="grid gap-1.5"><Label htmlFor="opd-pay">Collect now (₹)</Label><Input id="opd-pay" type="number" min={0} placeholder={doctor ? String(due) : ""} value={pay} onChange={(e) => setPay(e.target.value)} /></div>
              <div className="grid gap-1.5"><Label htmlFor="opd-method">Method</Label><NativeSelect id="opd-method" value={method} onChange={(e) => setMethod(e.target.value)}>{METHODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</NativeSelect></div>
            </div>
          )}
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={submit} disabled={busy || !ready}>{busy && <Loader2 className="animate-spin" aria-hidden />} {appointmentId ? "Start visit" : "Register and give token"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------- the visit ----------

const VITAL_FIELDS: [string, string, string][] = [["bp", "BP", "120/80"], ["pulse", "Pulse", "/min"], ["temperature", "Temp °C", ""], ["spo2", "SpO₂ %", ""], ["weight", "Weight kg", ""], ["height", "Height cm", ""]];
const blankMed = (): Medicine => ({ medicine_name: "", dosage: "", frequency: "", duration: "", route: null, instructions: null });

function VisitDialog({ id, onClose }: { id: number; onClose: () => void }) {
  const { can, user } = useAuth();
  const detail = useApi<OpdVisit>(`/opd/visits/${id}`);
  const v = detail.data;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [vitals, setVitals] = useState<Record<string, string> | null>(null);
  const [sheet, setSheet] = useState<{ chief_complaint: string; diagnosis: string; notes: string; advice: string; follow_up_date: string } | null>(null);
  const [meds, setMeds] = useState<Medicine[] | null>(null);
  const [payAmt, setPayAmt] = useState("");
  const [payMethod, setPayMethod] = useState("cash");
  const [ordering, setOrdering] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState("");

  const canVitals = can("observations.create") || can("consultations.manage");
  const live = v && v.status !== "cancelled";
  const vForm = vitals ?? Object.fromEntries(VITAL_FIELDS.map(([k]) => [k, String(v?.consultation?.vitals?.[k as keyof NonNullable<OpdVisit["consultation"]>["vitals"] & string] ?? "")]));
  const cs = sheet ?? { chief_complaint: v?.consultation?.chief_complaint ?? "", diagnosis: v?.consultation?.diagnosis ?? "", notes: v?.consultation?.notes ?? "", advice: v?.consultation?.advice ?? "", follow_up_date: v?.consultation?.follow_up_date ?? "" };
  const mForm = meds ?? v?.prescriptions ?? [];

  async function run(fn: () => Promise<unknown>, ok: string) {
    setBusy(true);
    setError("");
    try { await fn(); toast.success(ok); detail.reload(); setVitals(null); setSheet(null); setMeds(null); } catch (err) { setError(err instanceof ApiError ? err.message : "Something went wrong"); } finally { setBusy(false); }
  }
  const post = (path: string, body: unknown, ok: string, method: "POST" | "PUT" = "POST") => run(() => api(`/opd/visits/${id}${path}`, { method, body }), ok);

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{v ? <>Token #{v.token_number} · {v.patient_name}</> : "OPD visit"}</DialogTitle>
          <DialogDescription>{v ? `${v.doctor_name} · ${formatDay(v.visit_date)} · ${v.patient_code}${v.patient_mobile ? ` · ${v.patient_mobile}` : ""}${v.appointment_number ? ` · Booking ${v.appointment_number}` : ""}` : "Loading…"}</DialogDescription>
        </DialogHeader>
        {!v ? <Skeleton className="h-64" /> : (
          <div className="grid gap-5 text-sm">
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant={statusVariant[v.status]}>{VISIT_STATUS_LABEL[v.status]}</Badge>
              {v.reason && <span className="text-muted-foreground">Reason: {v.reason}</span>}
              {v.cancel_reason && <span className="text-destructive">Cancelled: {v.cancel_reason}</span>}
              <span className="ml-auto flex gap-1">
                <Button size="sm" variant="outline" onClick={() => printOpdSlip(v, user?.full_name) || toast.error("Allow pop-ups to print")}><Printer aria-hidden /> Slip</Button>
                <Button size="sm" variant="outline" onClick={() => printPrescription(v) || toast.error("Allow pop-ups to print")}><Printer aria-hidden /> Prescription</Button>
              </span>
            </div>

            <section aria-label="Fee">
              <h3 className="mb-1.5 font-semibold">Consultation fee</h3>
              {v.bill ? (
                <div className="rounded-lg border p-3">
                  <p>Bill <b>{v.bill.number}</b>: {inr.format(v.bill.total)} · paid {inr.format(v.bill.paid)} · <b className={v.bill.balance > 0 ? "text-destructive" : "text-success"}>{v.bill.balance > 0 ? `${inr.format(v.bill.balance)} due` : "settled"}</b></p>
                  {v.payments.length > 0 && <ul className="mt-1 text-xs text-muted-foreground">{v.payments.map((p) => <li key={p.receipt_number}>{p.receipt_number} · {p.method.toUpperCase()} · {inr.format(p.amount)}</li>)}</ul>}
                  {live && v.bill.balance > 0 && can("payments.collect") && (
                    <div className="mt-2 flex flex-wrap items-end gap-2">
                      <Input aria-label="Amount" type="number" min={1} max={v.bill.balance} className="w-28" placeholder={String(v.bill.balance)} value={payAmt} onChange={(e) => setPayAmt(e.target.value)} />
                      <NativeSelect aria-label="Method" className="w-28" value={payMethod} onChange={(e) => setPayMethod(e.target.value)}>{METHODS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</NativeSelect>
                      <Button size="sm" disabled={busy} onClick={() => post("/payments", { amount: Number(payAmt) || v.bill!.balance, method: payMethod }, "Payment recorded").then(() => setPayAmt(""))}>Collect</Button>
                    </div>
                  )}
                </div>
              ) : <p className="text-muted-foreground">No fee for this visit.</p>}
            </section>

            {live && canVitals && (
              <section aria-label="Vitals">
                <h3 className="mb-1.5 font-semibold">Vitals</h3>
                <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                  {VITAL_FIELDS.map(([k, label, ph]) => (
                    <div key={k} className="grid gap-1"><Label htmlFor={`vt-${k}`} className="text-xs">{label}</Label><Input id={`vt-${k}`} placeholder={ph} value={vForm[k]} onChange={(e) => setVitals({ ...vForm, [k]: e.target.value })} /></div>
                  ))}
                </div>
                <Button size="sm" variant="outline" className="mt-2" disabled={busy || vitals === null}
                  onClick={() => post("/vitals", { bp: vForm.bp || null, pulse: vForm.pulse ? Number(vForm.pulse) : null, temperature: vForm.temperature ? Number(vForm.temperature) : null, spo2: vForm.spo2 ? Number(vForm.spo2) : null, weight: vForm.weight ? Number(vForm.weight) : null, height: vForm.height ? Number(vForm.height) : null }, "Vitals saved", "PUT")}>Save vitals</Button>
              </section>
            )}

            {(can("consultations.manage") || v.consultation) && (
              <section aria-label="Consultation">
                <h3 className="mb-1.5 font-semibold">Consultation</h3>
                {can("consultations.manage") && live ? (
                  <div className="grid gap-2">
                    <div className="grid gap-1"><Label htmlFor="cs-c">Complaint</Label><Textarea id="cs-c" rows={2} value={cs.chief_complaint} onChange={(e) => setSheet({ ...cs, chief_complaint: e.target.value })} /></div>
                    <div className="grid gap-1"><Label htmlFor="cs-d">Diagnosis</Label><Textarea id="cs-d" rows={2} value={cs.diagnosis} onChange={(e) => setSheet({ ...cs, diagnosis: e.target.value })} /></div>
                    <div className="grid gap-1.5">
                      <Label>Medicines</Label>
                      {mForm.map((m, i) => (
                        <div key={i} className="grid grid-cols-[1fr_5rem_5rem_5rem_auto] gap-1.5">
                          <Input aria-label="Medicine" placeholder="Medicine" value={m.medicine_name} onChange={(e) => setMeds(mForm.map((x, j) => j === i ? { ...x, medicine_name: e.target.value } : x))} />
                          <Input aria-label="Dose" placeholder="Dose" value={m.dosage ?? ""} onChange={(e) => setMeds(mForm.map((x, j) => j === i ? { ...x, dosage: e.target.value } : x))} />
                          <Input aria-label="Frequency" placeholder="Freq." value={m.frequency ?? ""} onChange={(e) => setMeds(mForm.map((x, j) => j === i ? { ...x, frequency: e.target.value } : x))} />
                          <Input aria-label="Duration" placeholder="Days" value={m.duration ?? ""} onChange={(e) => setMeds(mForm.map((x, j) => j === i ? { ...x, duration: e.target.value } : x))} />
                          <Button size="icon-sm" variant="ghost" aria-label="Remove medicine" onClick={() => setMeds(mForm.filter((_, j) => j !== i))}><Trash2 /></Button>
                        </div>
                      ))}
                      <Button size="sm" variant="outline" className="w-fit" onClick={() => setMeds([...mForm, blankMed()])}><Plus aria-hidden /> Add medicine</Button>
                    </div>
                    <div className="grid gap-1"><Label htmlFor="cs-a">Advice</Label><Textarea id="cs-a" rows={2} value={cs.advice} onChange={(e) => setSheet({ ...cs, advice: e.target.value })} /></div>
                    <div className="grid gap-1 sm:w-48"><Label htmlFor="cs-f">Follow-up date</Label><Input id="cs-f" type="date" value={cs.follow_up_date} onChange={(e) => setSheet({ ...cs, follow_up_date: e.target.value })} /></div>
                    <div className="grid gap-1"><Label htmlFor="cs-n">Private notes <span className="font-normal text-muted-foreground">(not printed)</span></Label><Textarea id="cs-n" rows={2} value={cs.notes} onChange={(e) => setSheet({ ...cs, notes: e.target.value })} /></div>
                    <Button className="w-fit" disabled={busy} onClick={() => post("/consultation", {
                      chief_complaint: cs.chief_complaint || null, diagnosis: cs.diagnosis || null, notes: cs.notes || null, advice: cs.advice || null, follow_up_date: cs.follow_up_date || null,
                      prescriptions: mForm.filter((m) => m.medicine_name.trim().length >= 2).map((m) => ({ medicine_name: m.medicine_name, dosage: m.dosage || null, frequency: m.frequency || null, duration: m.duration || null, route: m.route, instructions: m.instructions })),
                    }, "Consultation saved", "PUT")}>Save consultation</Button>
                  </div>
                ) : v.consultation ? (
                  <div className="grid gap-1 rounded-lg border p-3">
                    {v.consultation.chief_complaint && <p><b>Complaint:</b> {v.consultation.chief_complaint}</p>}
                    {v.consultation.diagnosis && <p><b>Diagnosis:</b> {v.consultation.diagnosis}</p>}
                    {v.prescriptions.map((m) => <p key={m.id}>• {m.medicine_name} {m.dosage} {m.frequency} {m.duration}</p>)}
                    {v.consultation.advice && <p><b>Advice:</b> {v.consultation.advice}</p>}
                  </div>
                ) : <p className="text-muted-foreground">Not recorded yet.</p>}
              </section>
            )}

            <section aria-label="Lab tests">
              <div className="mb-1.5 flex items-center justify-between">
                <h3 className="font-semibold">Lab tests</h3>
                {live && (can("lab.manage") || can("consultations.manage")) && <Button size="sm" variant="outline" onClick={() => setOrdering(true)}><Plus aria-hidden /> Order tests</Button>}
              </div>
              {v.lab_orders.length === 0 ? <p className="text-muted-foreground">No tests ordered.</p> : (
                <ul className="grid gap-1">{v.lab_orders.map((o) => <li key={o.id} className="flex flex-wrap justify-between gap-2 rounded-lg border p-2"><span><b>{o.order_number}</b> · {o.tests}</span><span className="text-muted-foreground">{o.status.replace("_", " ")} · {inr.format(o.paid)} / {inr.format(o.total)}</span></li>)}</ul>
              )}
              {v.lab_orders.length > 0 && can("lab.read") && <p className="mt-1 text-xs text-muted-foreground">Results and payment for lab tests are handled in the Lab section.</p>}
            </section>

            {error && <p role="alert" className="text-destructive">{error}</p>}
            {cancelling && (
              <div className="grid gap-2 rounded-lg border border-destructive/40 p-3">
                <Label htmlFor="cx-r">Reason for cancelling</Label>
                <Input id="cx-r" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} />
                <div className="flex gap-2"><Button variant="destructive" size="sm" disabled={busy || reason.trim().length < 3} onClick={() => post("/cancel", { reason }, "Visit cancelled").then(() => setCancelling(false))}>Cancel visit</Button><Button size="sm" variant="ghost" onClick={() => setCancelling(false)}>Keep</Button></div>
              </div>
            )}
          </div>
        )}
        <DialogFooter>
          {v && live && can("opd.manage") && !cancelling && v.status !== "completed" && <Button variant="ghost" className="text-destructive" onClick={() => setCancelling(true)}>Cancel visit</Button>}
          {v && v.status === "waiting" && (can("opd.manage") || can("consultations.manage")) && <Button variant="outline" disabled={busy} onClick={() => post("/status", { status: "in_consultation" }, "With the doctor")}>Send in</Button>}
          {v && (v.status === "waiting" || v.status === "in_consultation") && (can("opd.manage") || can("consultations.manage")) && <Button variant="outline" disabled={busy} onClick={() => post("/status", { status: "completed" }, "Visit completed")}>Mark done</Button>}
          <Button onClick={onClose}>Close</Button>
        </DialogFooter>
      </DialogContent>
      {ordering && v && <OrderTestsDialog visit={v} onClose={() => setOrdering(false)} onDone={() => { setOrdering(false); detail.reload(); }} />}
    </Dialog>
  );
}

function OrderTestsDialog({ visit, onClose, onDone }: { visit: OpdVisit; onClose: () => void; onDone: () => void }) {
  const lookups = useApi<LabLookups>("/lab/lookups");
  const [picked, setPicked] = useState<number[]>([]);
  const [term, setTerm] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const tests = (lookups.data?.tests ?? []).filter((t) => t.is_active && (!term || t.name.toLowerCase().includes(term.toLowerCase())));
  const total = (lookups.data?.tests ?? []).filter((t) => picked.includes(t.id)).reduce((s, t) => s + t.price, 0);

  async function submit() {
    setBusy(true);
    setError("");
    try {
      await api("/lab/orders", { method: "POST", body: { opd_visit_id: visit.id, test_ids: picked } });
      toast.success("Tests ordered — the patient pays at the lab desk");
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not order the tests");
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader><DialogTitle>Order lab tests</DialogTitle><DialogDescription>For {visit.patient_name}, token #{visit.token_number}.</DialogDescription></DialogHeader>
        <Input type="search" aria-label="Search tests" placeholder="Search tests" value={term} onChange={(e) => setTerm(e.target.value)} />
        <ul className="grid max-h-72 gap-0.5 overflow-y-auto rounded-lg border p-1">
          {!lookups.data ? <Skeleton className="h-32" /> : tests.map((t) => (
            <li key={t.id}>
              <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted">
                <input type="checkbox" checked={picked.includes(t.id)} onChange={() => setPicked(picked.includes(t.id) ? picked.filter((x) => x !== t.id) : [...picked, t.id])} />
                <span className="flex-1">{t.name}</span><span className="tabular-nums text-muted-foreground">{inr.format(t.price)}</span>
              </label>
            </li>
          ))}
        </ul>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={submit} disabled={busy || picked.length === 0}>{busy && <Loader2 className="animate-spin" aria-hidden />} Order {picked.length || ""} {picked.length ? `(${inr.format(total)})` : ""}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
