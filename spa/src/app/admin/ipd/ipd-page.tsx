import { BedDouble, CalendarDays, ChevronLeft, ChevronRight, Download, ExternalLink, Link2, Loader2, Plus, Search, Settings2, Trash2, Unlink } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Fragment, useDeferredValue, useMemo, useState } from "react";
import { toast } from "sonner";

import { AdmissionMeta, DoctorLink, DoctorNames } from "@/app/admin/ipd/ipd-links";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
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
import { downloadFile } from "@/lib/download";
import { inr } from "@/lib/forms";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import { todayIST } from "@/types/booking";
import {
  type DateRange, HEAD_TYPE_LABEL, type HeadType, type IpdAdmission, type IpdBed, type IpdDay, type IpdHead, type IpdLookups, type IpdSummary, PAYMENT_MODES, type PaymentMode, addMonths, isFullMonth, monthRange, rangeLabel, shiftDays, shortDate,
} from "@/types/ipd";

/** Previous/next: a whole month moves by month; a custom range moves by its own length. */
/** Cash and UPI are money in hand, so their amounts are green. */
const isPayHead = (h: IpdHead) => h.code === "cash" || h.code === "upi";
/** Balance is orange, and red when it is below zero. */
const balanceTone = (n: number) => (n < 0 ? "text-destructive" : "text-orange-600 dark:text-orange-400");

function headCell(a: IpdAdmission, h: IpdHead) {
  const items = a.entries.filter((e) => e.head_id === h.id);
  return (
    <TableCell key={h.id} className="text-right tabular-nums">
      {items.length > 0 && (
        <>
          <span className={cn("font-medium", isPayHead(h) && "text-success")}>{money(a.totals[h.id] ?? 0)}</span>
          <span className="block text-[10px] leading-tight text-muted-foreground">{items.map((e) => shortDate(e.entry_date)).join(", ")}</span>
        </>
      )}
    </TableCell>
  );
}

function step(r: DateRange, dir: 1 | -1): DateRange {
  if (isFullMonth(r)) return monthRange(addMonths(r.from.slice(0, 7), dir));
  const len = Math.round((Date.parse(r.to) - Date.parse(r.from)) / 86_400_000) + 1;
  return { from: shiftDays(r.from, dir * len), to: shiftDays(r.to, dir * len) };
}
import type { PatientListItem } from "@/types/management";

export function IpdPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("ipd.read")}>
      <IpdView />
    </RequireAuth>
  );
}

const money = (n: number) => (n ? inr.format(n) : "");
const longDay = (d: string) => formatDay(d, { weekday: "long", day: "numeric", month: "long", year: "numeric" });

/** The patient's name; a registered patient also gets a link to their record. */
function PatientName({ name, patientId, className }: { name: string; patientId: number | null; className?: string }) {
  const { can } = useAuth();
  return (
    <span className={className}>
      {name}
      {patientId && can("patients.read") && (
        <Link href={`/admin/patients/${patientId}`} onClick={(e) => e.stopPropagation()} aria-label={`Open patient record for ${name}`} title="Open patient record" className="ml-1.5 inline-block align-middle text-primary hover:text-primary/70">
          <ExternalLink className="size-3.5" aria-hidden />
        </Link>
      )}
    </span>
  );
}

function IpdView() {
  const { can } = useAuth();
  const manage = can("ipd.manage");
  const initialMonth = useSearchParams().get("month");
  const [range, setRange] = useState<DateRange>(monthRange(initialMonth && /^\d{4}-\d{2}$/.test(initialMonth) ? initialMonth : todayIST().slice(0, 7)));
  const fullMonth = isFullMonth(range);
  const dates = { from: range.from, to: range.to };
  const [search, setSearch] = useState("");
  const q = useDeferredValue(search.trim());
  const [status, setStatus] = useState("");
  const heads = useApi<IpdHead[]>("/ipd/heads");
  const summary = useApi<IpdSummary>("/ipd/summary", dates);
  const days = useApi<{ month: string; days: IpdDay[] }>("/ipd/days", dates);
  const list = useApi<{ month: string; items: IpdAdmission[] }>("/ipd/admissions", { ...dates, q: q || undefined, status: status || undefined });
  const [adding, setAdding] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const [columns, setColumns] = useState(false);
  const [beds, setBeds] = useState(false);
  const [exporting, setExporting] = useState(false);
  const activeHeads = useMemo(() => (heads.data ?? []).filter((h) => h.is_active), [heads.data]);
  // The register shows Cash and UPI right after the patient; every other column follows the details.
  const payHeads = useMemo(() => activeHeads.filter((h) => h.code === "cash" || h.code === "upi").sort((a, b) => (a.code === "cash" ? -1 : 1) - (b.code === "cash" ? -1 : 1)), [activeHeads]);
  const otherHeads = useMemo(() => activeHeads.filter((h) => !payHeads.includes(h)), [activeHeads, payHeads]);
  const reload = () => { list.reload(); summary.reload(); days.reload(); };
  const s = summary.data;

  async function exportCsv() {
    setExporting(true);
    try { await downloadFile(`/ipd/export?from=${range.from}&to=${range.to}`, `ipd-register-${range.from}_${range.to}.csv`); }
    catch (err) { toast.error(err instanceof ApiError ? err.message : "Could not export"); }
    finally { setExporting(false); }
  }

  // Register rows grouped under their admission date, as in the paper register.
  const groups = useMemo(() => {
    const m = new Map<string, IpdAdmission[]>();
    for (const a of list.data?.items ?? []) m.set(a.admission_date, [...(m.get(a.admission_date) ?? []), a]);
    return [...m.entries()];
  }, [list.data]);

  return (
    <div className="mx-auto max-w-[110rem]">
      <PageHeader
        title="IPD register"
        description="Admitted patients and every payment, fee and expense, date by date."
        actions={
          <>
            <Button variant="outline" onClick={exportCsv} disabled={exporting}>{exporting ? <Loader2 className="animate-spin" aria-hidden /> : <Download aria-hidden />} Export to Excel</Button>
            {manage && <Button variant="outline" onClick={() => setBeds(true)}><BedDouble aria-hidden /> Beds</Button>}
            {manage && <Button variant="outline" onClick={() => setColumns(true)}><Settings2 aria-hidden /> Columns</Button>}
            {manage && <Button onClick={() => setAdding(true)}><Plus aria-hidden /> Add admission</Button>}
          </>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Button size="icon-sm" variant="outline" aria-label={fullMonth ? "Previous month" : "Previous period"} onClick={() => setRange(step(range, -1))}><ChevronLeft /></Button>
        <h2 className="min-w-40 text-center font-heading text-lg font-semibold">{rangeLabel(range)}</h2>
        <Button size="icon-sm" variant="outline" aria-label={fullMonth ? "Next month" : "Next period"} onClick={() => setRange(step(range, 1))}><ChevronRight /></Button>
        <Button size="sm" variant="ghost" onClick={() => setRange(monthRange(todayIST().slice(0, 7)))}>This month</Button>
        <Button size="sm" variant="ghost" onClick={() => setRange({ from: todayIST(), to: todayIST() })}>Today</Button>
        <Button size="sm" variant="ghost" onClick={() => setRange({ from: shiftDays(todayIST(), -6), to: todayIST() })}>Last 7 days</Button>
        <Button size="sm" variant="ghost" onClick={() => setRange({ from: shiftDays(todayIST(), -29), to: todayIST() })}>Last 30 days</Button>
        <div className="flex flex-wrap items-end gap-2 sm:ml-auto" role="group" aria-label="Date filter">
          <div className="grid gap-1"><Label htmlFor="ipd-from" className="text-xs">From</Label>
            <Input id="ipd-from" type="date" className="h-9 w-40" value={range.from} max={range.to}
                   onChange={(e) => e.target.value && setRange({ from: e.target.value, to: e.target.value > range.to ? e.target.value : range.to })} /></div>
          <div className="grid gap-1"><Label htmlFor="ipd-to" className="text-xs">To</Label>
            <Input id="ipd-to" type="date" className="h-9 w-40" value={range.to} min={range.from}
                   onChange={(e) => e.target.value && setRange({ from: e.target.value < range.from ? e.target.value : range.from, to: e.target.value })} /></div>
        </div>
      </div>

      <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {[
          { label: "Admissions", value: s ? String(s.admissions_in_month) : null, hint: `${s?.currently_admitted ?? 0} currently admitted` },
          { label: "Received", value: s ? inr.format(s.received) : null, hint: "Cash, UPI, card, collection" },
          { label: "Paid out", value: s ? inr.format(s.payouts) : null, hint: "Doctor fees, anaesthesia, expenses" },
          { label: "Received − paid out", value: s ? inr.format(s.net) : null, hint: "Before billed services" },
          { label: "Billed services", value: s ? inr.format(s.charges) : null, hint: "Implant, X-ray, USG, ECG, extras" },
        ].map((c) => (
          <Card key={c.label} className="gap-1 p-4">
            <p className="text-xs font-medium text-muted-foreground">{c.label}</p>
            {c.value === null ? <Skeleton className="h-7 w-24" /> : <p className="font-heading text-xl font-bold tabular-nums">{c.value}</p>}
            <p className="text-xs text-muted-foreground">{c.hint}</p>
          </Card>
        ))}
      </div>

      <Tabs defaultValue="days" className="gap-3">
        <TabsList>
          <TabsTrigger value="days"><CalendarDays className="size-4" aria-hidden /> Day-wise</TabsTrigger>
          <TabsTrigger value="register"><BedDouble className="size-4" aria-hidden /> Register</TabsTrigger>
        </TabsList>

        {/* ---- day by day ---- */}
        <TabsContent value="days">
          {days.error ? <p role="alert" className="text-sm text-destructive">{days.error.message}</p>
          : !days.data ? <div className="grid gap-3">{Array.from({ length: 3 }, (_, i) => <Skeleton key={i} className="h-28" />)}</div>
          : days.data.days.length === 0 ? <Card><EmptyState icon={CalendarDays} title="Nothing recorded for these dates" description={manage ? "Add an admission to start the register." : undefined} /></Card>
          : (
            <div className={cn("grid gap-3 transition-opacity", days.loading && "opacity-60")}>
              {days.data.days.map((d) => (
                <Card key={d.date} className="gap-0 p-0">
                  <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/50 px-4 py-2.5">
                    <h3 className="font-heading font-semibold">{longDay(d.date)}</h3>
                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm tabular-nums">
                      <span title="Money received on this date">Received <b>{inr.format(d.received)}</b></span>
                      <span title="Paid out on this date">Paid out <b>{inr.format(d.payouts)}</b></span>
                      {d.charges > 0 && <span title="Billed services on this date">Services <b>{inr.format(d.charges)}</b></span>}
                    </div>
                  </div>
                  <div className="grid gap-4 p-4 lg:grid-cols-2">
                    <div>
                      <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Admitted</p>
                      {d.admissions.length === 0 ? <p className="text-sm text-muted-foreground">No admissions.</p> : (
                        <ul className="grid gap-1">
                          {d.admissions.map((a) => (
                            <li key={a.id}>
                              <button type="button" className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted" onClick={() => setOpen(a.id)}>
                                <span><PatientName name={a.patient_name} patientId={a.patient_id} className="font-medium" /><span className="block text-xs text-muted-foreground"><AdmissionMeta a={a} /></span></span>
                                <span className={cn("rounded-full px-2 py-0.5 text-[11px]", a.status === "admitted" ? "bg-primary/10 text-primary" : "bg-muted text-muted-foreground")}>{a.status === "admitted" ? "Admitted" : "Discharged"}</span>
                              </button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                    <div>
                      <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Money entries</p>
                      {d.entries.length === 0 ? <p className="text-sm text-muted-foreground">No entries.</p> : (
                        <ul className="grid gap-1">
                          {d.entries.map((e) => (
                            <li key={e.id}>
                              <button type="button" className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted" onClick={() => setOpen(e.admission_id)}>
                                <span><b className="font-medium">{e.patient_name}</b> <span className="text-muted-foreground">· {e.head_name}{e.note ? ` · ${e.note}` : ""}</span></span>
                                <span className={cn("font-medium tabular-nums", e.head_type === "payout" && "text-destructive", e.head_type === "receipt" && "text-success")}>{e.head_type === "payout" ? "−" : e.head_type === "receipt" ? "+" : ""}{inr.format(e.amount)}</span>
                              </button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </div>
                </Card>
              ))}
            </div>
          )}
        </TabsContent>

        {/* ---- spreadsheet-style register ---- */}
        <TabsContent value="register">
          <Card className="gap-0 p-0">
            <div className="flex flex-col gap-2 border-b p-3 sm:flex-row">
              <div className="relative flex-1">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                <Input type="search" aria-label="Search the register" placeholder="Patient, case, doctor or bed" className="pl-8" value={search} onChange={(e) => setSearch(e.target.value)} />
              </div>
              <NativeSelect aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className="sm:w-44">
                <option value="">All patients</option><option value="admitted">Currently admitted</option><option value="discharged">Discharged</option>
              </NativeSelect>
            </div>
            {list.error ? <p role="alert" className="p-6 text-sm text-destructive">{list.error.message}</p>
            : !list.data || !heads.data ? <div className="grid gap-3 p-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
            : list.data.items.length === 0 ? <EmptyState icon={BedDouble} title="No admissions for these dates" description={manage ? "Add the first admission to start the register." : "Nothing recorded for these dates."} />
            : (
              <div className={cn("overflow-x-auto transition-opacity", list.loading && "opacity-60")}>
                <Table className="min-w-[80rem]">
                  <TableHeader>
                    <TableRow>
                      <TableHead className="sticky left-0 z-10 bg-card pl-4">Patient</TableHead>
                      {payHeads.map((h) => <TableHead key={h.id} className="text-right" title={HEAD_TYPE_LABEL[h.type]}>{h.name}</TableHead>)}
                      <TableHead>Bed</TableHead>
                      <TableHead>Department</TableHead>
                      <TableHead>Case</TableHead>
                      <TableHead>Doctors</TableHead>
                      <TableHead>Anaesthetist</TableHead>
                      {otherHeads.map((h) => <TableHead key={h.id} className="text-right" title={HEAD_TYPE_LABEL[h.type]}>{h.name}</TableHead>)}
                      <TableHead className="text-right" title="Received minus paid out">Received</TableHead>
                      <TableHead className="pr-4 text-right" title="Received minus paid out">Balance</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {groups.map(([date, rows]) => (
                      <Fragment key={date}>
                        <TableRow className="bg-muted/60 hover:bg-muted/60">
                          <TableCell colSpan={8 + activeHeads.length} className="sticky left-0 py-1.5 pl-4 text-xs font-semibold tracking-wide uppercase">
                            {longDay(date)} <span className="font-normal text-muted-foreground normal-case">· {rows.length} admission{rows.length === 1 ? "" : "s"}</span>
                          </TableCell>
                        </TableRow>
                        {rows.map((a) => (
                          <TableRow key={a.id} className="cursor-pointer" onClick={() => setOpen(a.id)}>
                            <TableCell className="sticky left-0 z-10 bg-card pl-4">
                              <button type="button" className="text-left font-medium hover:text-primary" onClick={(e) => { e.stopPropagation(); setOpen(a.id); }}>{a.patient_name}</button>
                              {a.patient_id && <PatientName name="" patientId={a.patient_id} />}
                              <span className="block text-xs text-muted-foreground">{a.status === "discharged" ? "Discharged" : "Admitted"}{a.patient_code ? ` · ${a.patient_code}` : ""}</span>
                            </TableCell>
                            {payHeads.map((h) => headCell(a, h))}
                            <TableCell className="whitespace-nowrap">{a.bed}</TableCell>
                            <TableCell className="text-xs">{a.department}</TableCell>
                            <TableCell>{a.case_title}</TableCell>
                            <TableCell className="max-w-48 text-xs"><DoctorNames list={a.doctor_list} other={a.other_doctors} /></TableCell>
                            <TableCell className="max-w-40 text-xs">{a.anaesthetist && <DoctorLink id={a.anaesthetist_id} name={a.anaesthetist} />}</TableCell>
                            {otherHeads.map((h) => headCell(a, h))}
                            <TableCell className="text-right tabular-nums">{money(a.received)}</TableCell>
                            <TableCell className={cn("pr-4 text-right font-semibold tabular-nums", balanceTone(a.balance))}>{money(a.balance)}</TableCell>
                          </TableRow>
                        ))}
                      </Fragment>
                    ))}
                  </TableBody>
                  {s && (
                    <TableFooter>
                      <TableRow>
                        <TableCell className="sticky left-0 bg-muted pl-4 font-semibold">Total <span className="block text-xs font-normal text-muted-foreground">by date of entry</span></TableCell>
                        {payHeads.map((h) => <TableCell key={h.id} className="text-right font-semibold text-success tabular-nums">{money(s.heads.find((x) => x.id === h.id)?.total ?? 0)}</TableCell>)}
                        <TableCell colSpan={5} />
                        {otherHeads.map((h) => <TableCell key={h.id} className="text-right font-semibold tabular-nums">{money(s.heads.find((x) => x.id === h.id)?.total ?? 0)}</TableCell>)}
                        <TableCell className="text-right font-semibold tabular-nums">{money(s.received)}</TableCell>
                        <TableCell className={cn("pr-4 text-right font-semibold tabular-nums", balanceTone(s.net))}>{money(s.net)}</TableCell>
                      </TableRow>
                    </TableFooter>
                  )}
                </Table>
              </div>
            )}
          </Card>
        </TabsContent>
      </Tabs>

      {adding && <AdmissionDialog admission={null} onClose={() => setAdding(false)} onSaved={(id) => { setAdding(false); reload(); setOpen(id); }} />}
      {open !== null && heads.data && <CaseDialog id={open} heads={activeHeads} manage={manage} onClose={() => { setOpen(null); reload(); }} />}
      {beds && <BedsDialog onClose={() => { setBeds(false); reload(); }} />}
      {columns && heads.data && <ColumnsDialog heads={heads.data} onClose={() => { setColumns(false); heads.reload(); reload(); }} />}
    </div>
  );
}

// ---------- add / edit an admission ----------

function AdmissionDialog({ admission, onClose, onSaved }: { admission: IpdAdmission | null; onClose: () => void; onSaved: (id: number) => void }) {
  const { can } = useAuth();
  const lookups = useApi<IpdLookups>("/ipd/lookups");
  const [doctorIds, setDoctorIds] = useState<number[]>(admission?.doctor_ids ?? []);
  const [f, setF] = useState({
    admission_date: admission?.admission_date ?? todayIST(), patient_name: admission?.patient_name ?? "", patient_mobile: "", bed_id: admission?.bed_id ? String(admission.bed_id) : "", department_id: admission?.department_id ? String(admission.department_id) : "",
    case_title: admission?.case_title ?? "", doctors: admission?.other_doctors ?? "", anaesthetist_id: admission?.anaesthetist_id ? String(admission.anaesthetist_id) : "", anaesthetist: admission?.anaesthetist_id ? "" : admission?.anaesthetist ?? "", notes: admission?.notes ?? "", status: admission?.status ?? "admitted", discharge_date: admission?.discharge_date ?? "",
  });
  const [linked, setLinked] = useState<{ id: number; code: string | null } | null>(admission?.patient_id ? { id: admission.patient_id, code: admission.patient_code } : null);
  const [term, setTerm] = useState("");
  const deferred = useDeferredValue(term.trim());
  const found = useApi<Page<PatientListItem>>(can("patients.read") && deferred.length >= 2 && !linked ? "/patients" : null, { search: deferred, page_size: 5 });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const set = (k: keyof typeof f, v: string) => setF((p) => ({ ...p, [k]: v }));

  async function save() {
    setBusy(true);
    setError("");
    const body = { ...f, patient_mobile: linked || admission?.patient_id ? null : f.patient_mobile || null, patient_id: linked?.id ?? null, bed_id: f.bed_id ? Number(f.bed_id) : null, department_id: f.department_id ? Number(f.department_id) : null, doctor_ids: doctorIds, anaesthetist_id: f.anaesthetist_id ? Number(f.anaesthetist_id) : null, case_title: f.case_title || null, doctors: f.doctors || null, anaesthetist: f.anaesthetist_id ? null : f.anaesthetist || null, notes: f.notes || null, discharge_date: f.status === "discharged" ? f.discharge_date || null : null };
    try {
      const r = admission ? await api<IpdAdmission>(`/ipd/admissions/${admission.id}`, { method: "PUT", body }) : await api<IpdAdmission>("/ipd/admissions", { method: "POST", body });
      toast.success(admission ? "Admission updated" : "Admission added");
      onSaved(r.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save");
    } finally {
      setBusy(false);
    }
  }
  // A bed is offered when it is switched on and nobody else is in it (the patient's own bed always stays).
  const freeBeds = (lookups.data?.beds ?? []).filter((b) => (b.is_active && !b.occupied_by) || b.occupied_by?.admission_id === admission?.id || b.id === admission?.bed_id);
  const activeDoctors = (lookups.data?.doctors ?? []).filter((d) => d.is_active || doctorIds.includes(d.id) || String(d.id) === f.anaesthetist_id);
  const field = (id: string, label: string, control: React.ReactNode, wide = false) => <div className={cn("grid gap-1.5", wide && "sm:col-span-2")}><Label htmlFor={id}>{label}</Label>{control}</div>;

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader><DialogTitle>{admission ? "Edit admission" : "Add admission"}</DialogTitle><DialogDescription>Patient, bed, case and the doctors treating them.</DialogDescription></DialogHeader>
        <div className="grid gap-4 sm:grid-cols-2">
          {field("ad-date", "Admission date", <Input id="ad-date" type="date" value={f.admission_date} onChange={(e) => set("admission_date", e.target.value)} />)}
          {field("ad-bed", "Bed", <NativeSelect id="ad-bed" value={f.bed_id} onChange={(e) => {
              const bed = freeBeds.find((b) => String(b.id) === e.target.value);
              setF((p) => ({ ...p, bed_id: e.target.value, department_id: p.department_id || (bed?.department_id ? String(bed.department_id) : "") }));
            }}>
              <option value="">No bed yet</option>
              {freeBeds.map((b) => <option key={b.id} value={b.id}>{b.code}{b.ward ? ` · ${b.ward}` : ""}</option>)}
            </NativeSelect>)}
          {can("patients.read") && (
            <div className="grid gap-1.5 sm:col-span-2">
              <Label htmlFor="ad-link">Registered patient <span className="font-normal text-muted-foreground">(search first, so the same person is not registered twice)</span></Label>
              {linked ? (
                <div className="flex items-center justify-between rounded-lg border bg-muted/40 p-2 text-sm">
                  <span className="flex items-center gap-2"><Link2 className="size-4 text-primary" aria-hidden /> Linked to <b>{linked.code ?? `patient #${linked.id}`}</b></span>
                  <Button size="sm" variant="ghost" onClick={() => setLinked(null)}><Unlink aria-hidden /> Choose another</Button>
                </div>
              ) : (
                <>
                  <Input id="ad-link" placeholder="Search by name, patient ID or mobile" value={term} onChange={(e) => setTerm(e.target.value)} autoComplete="off" />
                  {found.data && found.data.items.length > 0 && (
                    <ul className="grid gap-1 rounded-lg border p-1">
                      {found.data.items.map((p) => (
                        <li key={p.id}><button type="button" className="w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted"
                          onClick={() => { setLinked({ id: p.id, code: p.patient_code }); setF((x) => ({ ...x, patient_name: x.patient_name || p.full_name })); setTerm(""); }}>
                          <b>{p.full_name}</b> <span className="text-muted-foreground">{p.patient_code} · {p.mobile}</span>
                        </button></li>
                      ))}
                    </ul>
                  )}
                  {found.data && found.data.items.length === 0 && <p className="text-xs text-muted-foreground">No registered patient found. Type the name below and they will be registered as a new patient.</p>}
                </>
              )}
            </div>
          )}
          {!linked && field("ad-name", "Patient name", <Input id="ad-name" maxLength={150} value={f.patient_name} onChange={(e) => set("patient_name", e.target.value)} autoFocus />, true)}
          {!linked && !admission?.patient_id && field("ad-pmobile", "Mobile number (optional, for a new patient)", <Input id="ad-pmobile" type="tel" inputMode="numeric" maxLength={14} placeholder="10-digit mobile" value={f.patient_mobile} onChange={(e) => set("patient_mobile", e.target.value.replace(/\D/g, "").slice(-10))} />, true)}
          {field("ad-case", "Case", <Input id="ad-case" placeholder="e.g. LSCS, ORTHO, NVD" maxLength={200} value={f.case_title} onChange={(e) => set("case_title", e.target.value)} />, true)}
          {field("ad-dept", "Department", <NativeSelect id="ad-dept" value={f.department_id} onChange={(e) => set("department_id", e.target.value)}>
              <option value="">Not set</option>
              {(lookups.data?.departments ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </NativeSelect>)}
          <fieldset className="grid gap-1.5 sm:col-span-2">
            <legend className="mb-1.5 text-sm leading-none font-medium">Doctors <span className="font-normal text-muted-foreground">(choose from registered doctors)</span></legend>
            {!lookups.data ? <Skeleton className="h-16" /> : activeDoctors.length === 0 ? <p className="text-xs text-muted-foreground">No doctors are registered yet.</p> : (
              <div className="grid max-h-36 gap-1 overflow-y-auto rounded-lg border p-2 sm:grid-cols-2">
                {activeDoctors.map((d) => (
                  <label key={d.id} className="flex cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 text-sm hover:bg-muted">
                    <input type="checkbox" className="size-4 accent-primary" checked={doctorIds.includes(d.id)}
                           onChange={(e) => setDoctorIds((cur) => (e.target.checked ? [...cur, d.id] : cur.filter((x) => x !== d.id)))} />
                    <span>{d.name} <span className="text-xs text-muted-foreground">{d.specialization}</span></span>
                  </label>
                ))}
              </div>
            )}
          </fieldset>
          {field("ad-docs", "Other doctors (not registered)", <Input id="ad-docs" placeholder="e.g. a visiting surgeon" maxLength={255} value={f.doctors} onChange={(e) => set("doctors", e.target.value)} />, true)}
          {field("ad-anaes", "Anaesthesia doctor", <NativeSelect id="ad-anaes" value={f.anaesthetist_id} onChange={(e) => set("anaesthetist_id", e.target.value)}>
              <option value="">{f.anaesthetist ? `Typed: ${f.anaesthetist}` : "Not set"}</option>
              {activeDoctors.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </NativeSelect>, true)}
          {!f.anaesthetist_id && field("ad-anaes-t", "Or type the anaesthesia doctor's name", <Input id="ad-anaes-t" maxLength={150} value={f.anaesthetist} onChange={(e) => set("anaesthetist", e.target.value)} />, true)}
          {field("ad-status", "Status", <NativeSelect id="ad-status" value={f.status} onChange={(e) => set("status", e.target.value)}><option value="admitted">Admitted</option><option value="discharged">Discharged</option></NativeSelect>)}
          {f.status === "discharged" && field("ad-dis", "Discharge date", <Input id="ad-dis" type="date" min={f.admission_date} value={f.discharge_date || todayIST()} onChange={(e) => set("discharge_date", e.target.value)} />)}
          {field("ad-notes", "Notes (optional)", <Textarea id="ad-notes" rows={2} maxLength={2000} value={f.notes} onChange={(e) => set("notes", e.target.value)} />, true)}
        </div>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={save} disabled={busy || (f.patient_name.trim().length < 2 && !linked)}>{busy && <Loader2 className="animate-spin" aria-hidden />} Save</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------- one admission: details and dated entries ----------

function CaseDialog({ id, heads, manage, onClose }: { id: number; heads: IpdHead[]; manage: boolean; onClose: () => void }) {
  const { can } = useAuth();
  const detail = useApi<IpdAdmission>(`/ipd/admissions/${id}`);
  const a = detail.data;
  const [head, setHead] = useState("");
  const [date, setDate] = useState(todayIST());
  const [amount, setAmount] = useState("");
  const [note, setNote] = useState("");
  const [mode, setMode] = useState<PaymentMode | "">("");
  const [receipt, setReceipt] = useState("");
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState(false);
  const [removing, setRemoving] = useState(false);
  const [error, setError] = useState("");
  const headName = (hid: number) => heads.find((h) => h.id === hid)?.name ?? "Column removed";
  const isReceipt = heads.find((h) => String(h.id) === head)?.type === "receipt";

  async function add() {
    setBusy(true);
    setError("");
    try {
      await api(`/ipd/admissions/${id}/entries`, { method: "POST", body: { head_id: Number(head), entry_date: date, amount: Number(amount), payment_mode: isReceipt ? mode || null : null, receipt_no: isReceipt ? receipt || null : null, note: note || null } });
      setAmount("");
      setNote("");
      setReceipt("");
      detail.reload();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not add");
    } finally {
      setBusy(false);
    }
  }
  async function del(entryId: number) {
    try { await api(`/ipd/entries/${entryId}`, { method: "DELETE" }); detail.reload(); }
    catch (err) { toast.error(err instanceof ApiError ? err.message : "Could not delete"); }
  }

  return (
    <>
      <Dialog open onOpenChange={(o) => !o && onClose()}>
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{a?.patient_name ?? "Admission"}</DialogTitle>
            <DialogDescription>
              {a ? <><AdmissionMeta a={a} /><br />Admitted {formatDay(a.admission_date, { day: "numeric", month: "short", year: "numeric" })}{a.status === "discharged" && a.discharge_date ? `, discharged ${formatDay(a.discharge_date, { day: "numeric", month: "short" })}` : " · currently admitted"}
                {a.patient_id && can("patients.read") && <><br /><Link href={`/admin/patients/${a.patient_id}`} className="inline-flex items-center gap-1 text-primary hover:underline"><Link2 className="size-3.5" aria-hidden /> Patient record {a.patient_code}</Link></>}</> : "Loading…"}
            </DialogDescription>
          </DialogHeader>
          {!a ? <Skeleton className="h-40" /> : (
            <div className="grid gap-4">
              <div className="grid grid-cols-3 gap-2 text-center">
                {[["Received", a.received], ["Paid out", a.payouts], ["Billed services", a.charges]].map(([label, v]) => (
                  <div key={label as string} className="rounded-lg bg-muted p-2"><p className="text-xs text-muted-foreground">{label}</p><p className="font-semibold tabular-nums">{inr.format(v as number)}</p></div>
                ))}
              </div>
              {a.entries.length === 0 ? <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">No entries yet.</p> : (
                <ul className="divide-y rounded-lg border">
                  {a.entries.map((e) => (
                    <li key={e.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                      <span><b>{headName(e.head_id)}</b> <span className="text-muted-foreground">· {formatDay(e.entry_date, { day: "numeric", month: "short" })}{e.payment_mode ? ` · ${PAYMENT_MODES.find((m) => m.value === e.payment_mode)?.label}` : ""}{e.receipt_no ? ` · Receipt ${e.receipt_no}` : ""}{e.note ? ` · ${e.note}` : ""}</span></span>
                      <span className="flex items-center gap-1"><span className="font-medium tabular-nums">{inr.format(e.amount)}</span>
                        {manage && (e.lab_order_id ? <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground" title="Cancel the lab order to remove this charge">From Lab</span>
                          : <Button size="icon-sm" variant="ghost" aria-label="Delete entry" onClick={() => del(e.id)}><Trash2 /></Button>)}</span>
                    </li>
                  ))}
                </ul>
              )}
              {manage && (
                <form className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_9rem_8rem]" onSubmit={(e) => { e.preventDefault(); add(); }}>
                  <div className="grid gap-1.5"><Label htmlFor="en-head">Column</Label>
                    <NativeSelect id="en-head" value={head} onChange={(e) => setHead(e.target.value)}>
                      <option value="">Choose…</option>
                      {(["receipt", "payout", "charge"] as HeadType[]).map((t) => <optgroup key={t} label={HEAD_TYPE_LABEL[t]}>{heads.filter((h) => h.type === t).map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}</optgroup>)}
                    </NativeSelect></div>
                  <div className="grid gap-1.5"><Label htmlFor="en-date">Date</Label><Input id="en-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} /></div>
                  <div className="grid gap-1.5"><Label htmlFor="en-amt">Amount (₹)</Label><Input id="en-amt" type="number" min="0" step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></div>
                  {isReceipt && (
                    <>
                      <div className="grid gap-1.5"><Label htmlFor="en-mode">Paid by</Label>
                        <NativeSelect id="en-mode" value={mode} onChange={(e) => setMode(e.target.value as PaymentMode | "")}>
                          <option value="">As per column</option>
                          {PAYMENT_MODES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                        </NativeSelect></div>
                      <div className="grid gap-1.5 sm:col-span-2"><Label htmlFor="en-rcpt">Receipt no. (optional)</Label><Input id="en-rcpt" maxLength={40} value={receipt} onChange={(e) => setReceipt(e.target.value)} /></div>
                    </>
                  )}
                  <div className="grid gap-1.5 sm:col-span-2"><Label htmlFor="en-note">Note (optional)</Label><Input id="en-note" maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} /></div>
                  <div className="flex items-end"><Button type="submit" className="w-full" disabled={busy || !head || !amount || Number(amount) <= 0}>{busy && <Loader2 className="animate-spin" aria-hidden />} Add entry</Button></div>
                  {error && <p role="alert" className="text-sm text-destructive sm:col-span-3">{error}</p>}
                </form>
              )}
            </div>
          )}
          <DialogFooter>
            {manage && a && <><Button variant="ghost" className="text-destructive" onClick={() => setRemoving(true)}>Delete admission</Button><Button variant="outline" onClick={() => setEditing(true)}>Edit details</Button></>}
            <Button onClick={onClose}>Done</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      {editing && a && <AdmissionDialog admission={a} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); detail.reload(); }} />}
      <ConfirmDialog open={removing} onOpenChange={setRemoving} destructive confirmLabel="Delete admission" title="Delete this admission?"
        description="It disappears from the register. Its entries stay on record and the deletion is logged."
        onConfirm={async () => {
          try { await api(`/ipd/admissions/${id}`, { method: "DELETE" }); toast.success("Admission deleted"); onClose(); }
          catch (err) { toast.error(err instanceof ApiError ? err.message : "Could not delete"); }
        }} />
    </>
  );
}

// ---------- columns (heads) ----------

function ColumnsDialog({ heads, onClose }: { heads: IpdHead[]; onClose: () => void }) {
  const [name, setName] = useState("");
  const [type, setType] = useState<HeadType>("charge");
  const [busy, setBusy] = useState(false);
  const [rows, setRows] = useState(heads);

  async function save(h: IpdHead, patch: Partial<IpdHead>) {
    const next = { ...h, ...patch };
    setRows((r) => r.map((x) => (x.id === h.id ? next : x)));
    try { await api(`/ipd/heads/${h.id}`, { method: "PUT", body: { name: next.name, type: next.type, is_active: next.is_active, display_order: next.display_order } }); }
    catch (err) { setRows((r) => r.map((x) => (x.id === h.id ? h : x))); toast.error(err instanceof ApiError ? err.message : "Could not save"); }
  }
  async function add() {
    setBusy(true);
    try { const h = await api<IpdHead>("/ipd/heads", { method: "POST", body: { name, type } }); setRows((r) => [...r, h]); setName(""); toast.success(`${h.name} added`); }
    catch (err) { toast.error(err instanceof ApiError ? err.message : "Could not add"); }
    finally { setBusy(false); }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader><DialogTitle>Register columns</DialogTitle>
          <DialogDescription>The money columns shown in the register. “Money received” counts as income, “Paid out” as expenses, “Billed service” as charges to the patient. Hide a column to stop using it; past entries are kept.</DialogDescription></DialogHeader>
        <ul className="grid gap-2">
          {rows.map((h) => (
            <li key={h.id} className={cn("flex flex-wrap items-center gap-2 rounded-lg border p-2", !h.is_active && "opacity-60")}>
              <Input aria-label={`Name of ${h.name}`} defaultValue={h.name} className="min-w-32 flex-1" onBlur={(e) => e.target.value.trim() !== h.name && e.target.value.trim().length >= 2 && save(h, { name: e.target.value.trim() })} />
              <NativeSelect aria-label={`Type of ${h.name}`} value={h.type} onChange={(e) => save(h, { type: e.target.value as HeadType })} className="w-40">
                {(Object.keys(HEAD_TYPE_LABEL) as HeadType[]).map((t) => <option key={t} value={t}>{HEAD_TYPE_LABEL[t]}</option>)}
              </NativeSelect>
              <label className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={h.is_active} onChange={(e) => save(h, { is_active: e.target.checked })} /> Shown</label>
            </li>
          ))}
        </ul>
        <form className="flex flex-wrap items-end gap-2 border-t pt-3" onSubmit={(e) => { e.preventDefault(); add(); }}>
          <div className="grid flex-1 gap-1.5"><Label htmlFor="nh-name">New column</Label><Input id="nh-name" placeholder="e.g. Medicine" maxLength={60} value={name} onChange={(e) => setName(e.target.value)} /></div>
          <NativeSelect aria-label="Type" value={type} onChange={(e) => setType(e.target.value as HeadType)} className="w-40">{(Object.keys(HEAD_TYPE_LABEL) as HeadType[]).map((t) => <option key={t} value={t}>{HEAD_TYPE_LABEL[t]}</option>)}</NativeSelect>
          <Button type="submit" disabled={busy || name.trim().length < 2}>{busy && <Loader2 className="animate-spin" aria-hidden />} Add</Button>
        </form>
        <DialogFooter><Button onClick={onClose}>Done</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------- beds ----------

function BedsDialog({ onClose }: { onClose: () => void }) {
  const beds = useApi<IpdBed[]>("/ipd/beds");
  const lookups = useApi<IpdLookups>("/ipd/lookups");
  const [code, setCode] = useState("");
  const [ward, setWard] = useState("");
  const [dept, setDept] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function add() {
    setBusy(true);
    setError("");
    try {
      await api("/ipd/beds", { method: "POST", body: { code, ward: ward || null, department_id: dept ? Number(dept) : null } });
      setCode("");
      beds.reload();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not add");
    } finally {
      setBusy(false);
    }
  }
  async function toggle(b: IpdBed) {
    try {
      await api(`/ipd/beds/${b.id}`, { method: "PUT", body: { code: b.code, ward: b.ward, department_id: b.department_id, display_order: b.display_order, is_active: !b.is_active } });
      beds.reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update");
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader><DialogTitle>Beds</DialogTitle><DialogDescription>The beds patients can be placed in. A bed holds one admitted patient and is free again once they are discharged.</DialogDescription></DialogHeader>
        {!beds.data ? <Skeleton className="h-32" /> : beds.data.length === 0 ? <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">No beds yet. Add the first one below.</p> : (
          <ul className="divide-y rounded-lg border">
            {beds.data.map((b) => (
              <li key={b.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                <span className={cn(!b.is_active && "text-muted-foreground line-through")}>
                  <b>{b.code}</b>{b.ward ? ` · ${b.ward}` : ""}{b.department ? ` · ${b.department}` : ""}
                  <span className="block text-xs no-underline">{b.occupied_by ? `Occupied by ${b.occupied_by.patient_name}` : b.is_active ? "Free" : "Switched off"}</span>
                </span>
                <Button size="sm" variant="ghost" onClick={() => toggle(b)}>{b.is_active ? "Switch off" : "Switch on"}</Button>
              </li>
            ))}
          </ul>
        )}
        <form className="grid gap-3 rounded-lg border p-3 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); add(); }}>
          <div className="grid gap-1.5"><Label htmlFor="bd-code">Bed no.</Label><Input id="bd-code" maxLength={20} placeholder="e.g. G-6" value={code} onChange={(e) => setCode(e.target.value)} /></div>
          <div className="grid gap-1.5"><Label htmlFor="bd-ward">Ward</Label><Input id="bd-ward" maxLength={60} placeholder="e.g. General" value={ward} onChange={(e) => setWard(e.target.value)} /></div>
          <div className="grid gap-1.5"><Label htmlFor="bd-dept">Department</Label>
            <NativeSelect id="bd-dept" value={dept} onChange={(e) => setDept(e.target.value)}>
              <option value="">Any</option>
              {(lookups.data?.departments ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </NativeSelect></div>
          <div className="sm:col-span-3"><Button type="submit" disabled={busy || !code.trim()}>{busy && <Loader2 className="animate-spin" aria-hidden />} Add bed</Button></div>
          {error && <p role="alert" className="text-sm text-destructive sm:col-span-3">{error}</p>}
        </form>
        <DialogFooter><Button onClick={onClose}>Done</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
