import { FlaskConical, Loader2, Pencil, Plus, Printer, Search } from "lucide-react";
import Link from "next/link";
import { Fragment, useDeferredValue, useMemo, useState } from "react";
import { toast } from "sonner";

import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { inr } from "@/lib/forms";
import { formatDay } from "@/lib/public-format";
import { printBill, printReport } from "@/lib/print-report";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import type { PatientListItem } from "@/types/management";
import { LAB_STATUS_LABEL, PAY_METHODS, type LabFlag, type LabLookups, type LabOrder, type LabOrderRow, type LabStatus, type LabTest, type PayMethod } from "@/types/lab";

const PAGE_SIZE = 20;

export function LabPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("lab.read")}>
      <LabView />
    </RequireAuth>
  );
}

const STATUS_STYLE: Record<LabStatus, string> = {
  ordered: "bg-muted text-muted-foreground ring-border",
  sample_collected: "bg-info/12 text-info ring-info/30",
  processing: "bg-warning/15 text-amber-700 ring-warning/40 dark:text-warning",
  completed: "bg-success/12 text-success ring-success/30",
  cancelled: "bg-slot-cancelled/15 text-muted-foreground ring-slot-cancelled/40 line-through",
};

export function LabStatusBadge({ status }: { status: LabStatus }) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset", STATUS_STYLE[status])}>
      <span aria-hidden className="size-1.5 rounded-full bg-current" />{LAB_STATUS_LABEL[status]}
    </span>
  );
}

/** Balance due is orange, nothing due is green, and a cancelled bill stays plain. */
export function BalanceText({ order }: { order: Pick<LabOrderRow, "status" | "bill"> }) {
  if (order.status === "cancelled") return <span className="text-muted-foreground">Cancelled</span>;
  if (order.bill.balance > 0) return <span className="font-medium text-orange-600 dark:text-orange-400">{inr.format(order.bill.balance)} due</span>;
  return <span className="font-medium text-success">Paid</span>;
}

const dateOf = (iso: string) => formatDay(iso.slice(0, 10), { day: "numeric", month: "short", year: "numeric" });

function LabView() {
  const { can } = useAuth();
  return (
    <div className="mx-auto max-w-6xl">
      <Tabs defaultValue="orders" className="gap-4">
        {can("lab.catalogue") && <TabsList><TabsTrigger value="orders">Orders</TabsTrigger><TabsTrigger value="tests">Test catalogue</TabsTrigger></TabsList>}
        <TabsContent value="orders"><OrdersTab /></TabsContent>
        {can("lab.catalogue") && <TabsContent value="tests"><CatalogueTab /></TabsContent>}
      </Tabs>
    </div>
  );
}

// ---------- orders ----------

function OrdersTab() {
  const { can } = useAuth();
  const [search, setSearch] = useState("");
  const q = useDeferredValue(search.trim());
  const [status, setStatus] = useState("");
  const [unpaid, setUnpaid] = useState(false);
  const [visit, setVisit] = useState("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const list = useApi<Page<LabOrderRow>>("/lab/orders", { q: q || undefined, status: status || undefined, visit: visit || undefined, unpaid: unpaid ? 1 : undefined, page, page_size: PAGE_SIZE });
  const reset = () => setPage(1);

  return (
    <>
      <PageHeader title="Lab" description="Test orders, results and payments."
        actions={can("lab.manage") && <Button onClick={() => setCreating(true)}><Plus aria-hidden /> New lab order</Button>} />
      <Card className="gap-0 p-0">
        <div className="flex flex-col gap-2 border-b p-3 sm:flex-row sm:items-center">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input type="search" aria-label="Search lab orders" placeholder="Order no., patient, mobile or test" className="pl-8" value={search} onChange={(e) => { setSearch(e.target.value); reset(); }} />
          </div>
          <NativeSelect aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); reset(); }} className="sm:w-48">
            <option value="">All statuses</option>
            {(Object.keys(LAB_STATUS_LABEL) as LabStatus[]).map((s) => <option key={s} value={s}>{LAB_STATUS_LABEL[s]}</option>)}
          </NativeSelect>
          <NativeSelect aria-label="Visit type" value={visit} onChange={(e) => { setVisit(e.target.value); reset(); }} className="sm:w-44">
            <option value="">All patients</option>
            <option value="ipd">In-patients (IPD)</option>
            <option value="walk_in">Lab only</option>
          </NativeSelect>
          <label className="flex cursor-pointer items-center gap-2 text-sm text-muted-foreground">
            <input type="checkbox" className="size-4 accent-primary" checked={unpaid} onChange={(e) => { setUnpaid(e.target.checked); reset(); }} /> Unpaid only
          </label>
        </div>
        {list.error ? <p role="alert" className="p-6 text-sm text-destructive">{list.error.message}</p>
        : !list.data ? <div className="grid gap-3 p-4">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
        : list.data.items.length === 0 ? <EmptyState icon={FlaskConical} title={q || status || unpaid ? "No matching orders" : "No lab orders yet"} description={can("lab.manage") ? "Create the first order for a patient." : undefined} />
        : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Order</TableHead>
                  <TableHead>Patient</TableHead>
                  <TableHead className="hidden md:table-cell">Tests</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="pr-4 text-right">Bill</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {list.data.items.map((o) => (
                  <TableRow key={o.id} className="cursor-pointer" onClick={() => setOpen(o.id)}>
                    <TableCell className="pl-4"><span className="font-mono text-xs font-semibold text-primary">{o.order_number}</span><span className="block text-xs text-muted-foreground">{dateOf(o.created_at)}</span></TableCell>
                    <TableCell>{o.patient_name}<span className="block text-xs text-muted-foreground"><span className="font-mono">{o.patient_code}</span> · {o.admission_id ? <span className="font-medium text-primary">IPD{o.ipd_bed ? ` · Bed ${o.ipd_bed}` : ""}</span> : "Lab only"}</span></TableCell>
                    <TableCell className="hidden max-w-64 truncate text-xs text-muted-foreground md:table-cell" title={o.tests ?? ""}>{o.tests}</TableCell>
                    <TableCell><LabStatusBadge status={o.status} /></TableCell>
                    <TableCell className="pr-4 text-right tabular-nums">{inr.format(o.bill.total)}<span className="block text-xs"><BalanceText order={o} /></span></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <Pagination page={page} total={list.data.total} pageSize={PAGE_SIZE} onPage={setPage} noun="order" />
          </>
        )}
      </Card>
      {creating && <NewOrderDialog onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); list.reload(); setOpen(id); }} />}
      {open !== null && <OrderDialog id={open} onClose={() => { setOpen(null); list.reload(); }} />}
    </>
  );
}

// ---------- new order ----------

function NewOrderDialog({ onClose, onCreated, patient }: { onClose: () => void; onCreated: (id: number) => void; patient?: { id: number; name: string } }) {
  const { can } = useAuth();
  const lookups = useApi<LabLookups>("/lab/lookups");
  const [linked, setLinked] = useState<{ id: number; name: string; code: string | null } | null>(patient ? { id: patient.id, name: patient.name, code: null } : null);
  const [term, setTerm] = useState("");
  const deferred = useDeferredValue(term.trim());
  const found = useApi<Page<PatientListItem>>(can("patients.read") && deferred.length >= 2 && !linked ? "/patients" : null, { search: deferred, page_size: 5 });
  const [name, setName] = useState("");
  const [mobile, setMobile] = useState("");
  const [doctorId, setDoctorId] = useState("");
  const [picked, setPicked] = useState<number[]>([]);
  const [filter, setFilter] = useState("");
  const [discount, setDiscount] = useState("");
  const [notes, setNotes] = useState("");
  const [payNow, setPayNow] = useState("");
  const [method, setMethod] = useState<PayMethod>("cash");
  const [ref, setRef] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  // Is the chosen patient in hospital now? Their tests then go to the IPD register unless staff say otherwise.
  const stay = useApi<{ admission: { id: number; bed: string | null; case_title: string | null } | null }>(linked ? `/lab/admission-for/${linked.id}` : null);
  const [toIpd, setToIpd] = useState(true);
  const admitted = stay.data?.admission ?? null;

  const tests = lookups.data?.tests ?? [];
  const visible = useMemo(() => tests.filter((t) => !filter.trim() || `${t.name} ${t.category}`.toLowerCase().includes(filter.trim().toLowerCase())), [tests, filter]);
  const grouped = useMemo(() => {
    const m = new Map<string, LabTest[]>();
    visible.forEach((t) => m.set(t.category, [...(m.get(t.category) ?? []), t]));
    return [...m.entries()];
  }, [visible]);
  const subtotal = tests.filter((t) => picked.includes(t.id)).reduce((s, t) => s + t.price, 0);
  const disc = Math.min(Math.max(Number(discount) || 0, 0), subtotal);
  const total = subtotal - disc;
  const paying = Number(payNow) || 0;
  const ready = picked.length > 0 && (linked || name.trim().length >= 2) && paying >= 0 && paying <= total && (!mobile || /^[6-9]\d{9}$/.test(mobile));

  async function submit() {
    setBusy(true);
    setError("");
    try {
      const body = {
        patient_id: linked?.id ?? null, patient_name: linked ? null : name.trim(), patient_mobile: linked ? null : mobile || null, doctor_id: doctorId ? Number(doctorId) : null,
        bill_to_admission: admitted ? toIpd : null, test_ids: picked, discount: disc || null, notes: notes || null, payment: paying > 0 ? { amount: paying, method, reference_no: ref || null } : null,
      };
      const o = await api<LabOrder>("/lab/orders", { method: "POST", body });
      toast.success(`Order ${o.order_number} created`);
      onCreated(o.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not create the order");
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader><DialogTitle>New lab order</DialogTitle><DialogDescription>Choose the patient and tests, and take payment now or later.</DialogDescription></DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="lo-patient">Patient</Label>
            {linked ? (
              <div className="flex items-center justify-between rounded-lg border bg-muted/40 p-2 text-sm">
                <span><b>{linked.name}</b> {linked.code && <span className="font-mono text-xs text-muted-foreground">{linked.code}</span>}</span>
                {!patient && <Button size="sm" variant="ghost" onClick={() => setLinked(null)}>Choose another</Button>}
              </div>
            ) : null}
            {linked && admitted && (
              <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-primary/30 bg-primary/5 p-2 text-sm">
                <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={toIpd} onChange={(e) => setToIpd(e.target.checked)} />
                <span><b>Admitted{admitted.bed ? ` · Bed ${admitted.bed}` : ""}.</b> Add the cost of these tests to their IPD register.
                  <span className="block text-xs text-muted-foreground">Untick if they are being tested as an outpatient.</span></span>
              </label>
            )}
            {linked ? null : (
              <>
                {can("patients.read") && <Input id="lo-patient" placeholder="Search registered patients by name, ID or mobile" value={term} onChange={(e) => setTerm(e.target.value)} autoComplete="off" />}
                {found.data && found.data.items.length > 0 && (
                  <ul className="grid gap-1 rounded-lg border p-1">
                    {found.data.items.map((p) => (
                      <li key={p.id}><button type="button" className="w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted" onClick={() => { setLinked({ id: p.id, name: p.full_name, code: p.patient_code }); setTerm(""); }}>
                        <b>{p.full_name}</b> <span className="text-muted-foreground">{p.patient_code} · {p.mobile ?? "no mobile"}</span></button></li>
                    ))}
                  </ul>
                )}
                <p className="text-xs text-muted-foreground">Coming for tests only and not registered? Enter their name and mobile. They are registered as a patient and the order is marked “Lab only”.</p>
                <div className="grid gap-2 sm:grid-cols-2">
                  <Input aria-label="New patient's name" placeholder="Full name" maxLength={150} value={name} onChange={(e) => setName(e.target.value)} />
                  <Input aria-label="New patient's mobile" type="tel" inputMode="numeric" placeholder="Mobile (optional)" maxLength={14} value={mobile} onChange={(e) => setMobile(e.target.value.replace(/\D/g, "").slice(-10))} />
                </div>
              </>
            )}
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="lo-doc">Referred by <span className="font-normal text-muted-foreground">(optional)</span></Label>
            <NativeSelect id="lo-doc" value={doctorId} onChange={(e) => setDoctorId(e.target.value)}>
              <option value="">No referring doctor</option>
              {(lookups.data?.doctors ?? []).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </NativeSelect>
          </div>

          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm leading-none font-medium">Tests</legend>
            <Input aria-label="Filter tests" type="search" placeholder="Filter tests" value={filter} onChange={(e) => setFilter(e.target.value)} />
            {!lookups.data ? <Skeleton className="h-32" /> : (
              <div className="grid max-h-60 gap-2 overflow-y-auto rounded-lg border p-2">
                {grouped.length === 0 && <p className="p-2 text-sm text-muted-foreground">No tests match.</p>}
                {grouped.map(([cat, list]) => (
                  <div key={cat}>
                    <p className="px-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">{cat}</p>
                    {list.map((t) => (
                      <label key={t.id} className="flex cursor-pointer items-center justify-between gap-3 rounded-md px-1.5 py-1 text-sm hover:bg-muted">
                        <span className="flex items-center gap-2"><input type="checkbox" className="size-4 accent-primary" checked={picked.includes(t.id)} onChange={(e) => setPicked((c) => (e.target.checked ? [...c, t.id] : c.filter((x) => x !== t.id)))} />{t.name}</span>
                        <span className="tabular-nums text-muted-foreground">{inr.format(t.price)}</span>
                      </label>
                    ))}
                  </div>
                ))}
              </div>
            )}
          </fieldset>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5"><Label htmlFor="lo-disc">Discount (₹)</Label><Input id="lo-disc" type="number" min={0} value={discount} onChange={(e) => setDiscount(e.target.value)} /></div>
            <div className="grid gap-1.5"><Label htmlFor="lo-notes">Note (optional)</Label><Input id="lo-notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} /></div>
          </div>

          <div className="rounded-lg bg-muted p-3 text-sm">
            <p className="flex justify-between"><span>{picked.length} test{picked.length === 1 ? "" : "s"}</span><span className="tabular-nums">{inr.format(subtotal)}</span></p>
            {disc > 0 && <p className="flex justify-between text-muted-foreground"><span>Discount</span><span className="tabular-nums">− {inr.format(disc)}</span></p>}
            <p className="mt-1 flex justify-between text-base font-semibold"><span>Total</span><span className="tabular-nums">{inr.format(total)}</span></p>
          </div>

          {can("lab.manage") && total > 0 && (
            <div className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_9rem_1fr]">
              <div className="grid gap-1.5"><Label htmlFor="lo-pay">Pay now (₹)</Label>
                <div className="flex gap-2"><Input id="lo-pay" type="number" min={0} max={total} value={payNow} onChange={(e) => setPayNow(e.target.value)} />
                  <Button type="button" variant="outline" size="sm" onClick={() => setPayNow(String(total))}>Full</Button></div></div>
              <div className="grid gap-1.5"><Label htmlFor="lo-method">Paid by</Label>
                <NativeSelect id="lo-method" value={method} onChange={(e) => setMethod(e.target.value as PayMethod)}>{PAY_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}</NativeSelect></div>
              <div className="grid gap-1.5"><Label htmlFor="lo-ref">Reference (optional)</Label><Input id="lo-ref" maxLength={100} value={ref} onChange={(e) => setRef(e.target.value)} /></div>
              <p className="text-xs text-muted-foreground sm:col-span-3">{paying > total ? "The payment is more than the total." : paying > 0 ? `Balance after this payment: ${inr.format(total - paying)}` : "Leave blank to bill now and collect later."}</p>
            </div>
          )}
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button onClick={submit} disabled={busy || !ready}>{busy && <Loader2 className="animate-spin" aria-hidden />} Create order</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------- one order ----------

type ResultDraft = { result_value: string; result_unit: string; reference_range: string; flag: LabFlag | ""; result_notes: string };

export function OrderDialog({ id, onClose }: { id: number; onClose: () => void }) {
  const { can } = useAuth();
  const detail = useApi<LabOrder>(`/lab/orders/${id}`);
  const o = detail.data;
  const [drafts, setDrafts] = useState<Record<number, ResultDraft>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState("");
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState<PayMethod>("cash");
  const [ref, setRef] = useState("");

  const canResults = can("lab.results") && !!o && ["sample_collected", "processing", "completed"].includes(o.status);
  const draftOf = (i: LabOrder["items"][number]): ResultDraft => drafts[i.id] ?? { result_value: i.result_value ?? "", result_unit: i.result_unit ?? "", reference_range: i.reference_range ?? "", flag: i.flag ?? "", result_notes: i.result_notes ?? "" };
  const setDraft = (i: LabOrder["items"][number], patch: Partial<ResultDraft>) => setDrafts((d) => ({ ...d, [i.id]: { ...draftOf(i), ...patch } }));

  async function act(fn: () => Promise<unknown>, ok?: string) {
    setBusy(true);
    setError("");
    try {
      await fn();
      ok && toast.success(ok);
      setDrafts({});
      detail.reload();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong");
    } finally {
      setBusy(false);
    }
  }
  const status = (s: string) => act(() => api(`/lab/orders/${id}/status`, { method: "POST", body: { status: s } }), s === "completed" ? "Report released" : "Updated");
  const saveResults = () => o && act(() => api(`/lab/orders/${id}/results`, { method: "PUT", body: { results: o.items.map((i) => ({ item_id: i.id, ...draftOf(i), flag: draftOf(i).flag || null })) } }), "Results saved");
  const pay = () => act(async () => { await api(`/lab/orders/${id}/payments`, { method: "POST", body: { amount: Number(amount), method, reference_no: ref || null } }); setAmount(""); setRef(""); }, "Payment recorded");
  const allFilled = !!o && o.items.every((i) => (draftOf(i).result_value ?? "").trim() !== "");
  const dirty = Object.keys(drafts).length > 0;

  return (
    <>
      <Dialog open onOpenChange={(v) => !v && !busy && onClose()}>
        <DialogContent className="max-h-[94vh] overflow-y-auto sm:max-w-3xl">
          <DialogHeader>
            <DialogTitle className="flex flex-wrap items-center gap-2">{o?.order_number ?? "Lab order"} {o && <LabStatusBadge status={o.status} />}</DialogTitle>
            <DialogDescription>
              {o ? <>
                {can("patients.read") ? <Link href={`/admin/patients/${o.patient_id}`} className="font-medium text-primary hover:underline">{o.patient_name}</Link> : o.patient_name} <span className="font-mono text-xs">{o.patient_code}</span>
                {o.doctor_name && <> · Referred by {o.doctor_name}</>} · {dateOf(o.created_at)}
                {o.status === "cancelled" && o.cancel_reason && <><br />Cancelled: {o.cancel_reason}</>}
              </> : "Loading…"}
            </DialogDescription>
          </DialogHeader>

          {!o ? <Skeleton className="h-48" /> : (
            <div className="grid gap-5">
              <div>
                <h3 className="mb-2 text-sm font-semibold">Tests{canResults ? " and results" : ""}</h3>
                <div className="overflow-x-auto rounded-lg border">
                  <Table>
                    <TableHeader><TableRow><TableHead className="pl-3">Test</TableHead>{(canResults || o.status === "completed") && <><TableHead>Result</TableHead><TableHead className="hidden sm:table-cell">Unit / range</TableHead></>}<TableHead className="pr-3 text-right">Price</TableHead></TableRow></TableHeader>
                    <TableBody>
                      {o.items.map((i) => {
                        const d = draftOf(i);
                        return (
                          <Fragment key={i.id}>
                            <TableRow>
                              <TableCell className="pl-3 font-medium">{i.test_name}</TableCell>
                              {canResults ? (
                                <>
                                  <TableCell className="min-w-44">
                                    <Input aria-label={`Result for ${i.test_name}`} value={d.result_value} onChange={(e) => setDraft(i, { result_value: e.target.value })} className="h-8" />
                                    <NativeSelect aria-label={`Flag for ${i.test_name}`} value={d.flag} onChange={(e) => setDraft(i, { flag: e.target.value as LabFlag | "" })} className="mt-1 h-8 text-xs">
                                      <option value="">No flag</option><option value="normal">Normal</option><option value="high">High</option><option value="low">Low</option><option value="critical">Critical</option>
                                    </NativeSelect>
                                  </TableCell>
                                  <TableCell className="hidden min-w-36 sm:table-cell">
                                    <Input aria-label={`Unit for ${i.test_name}`} placeholder="Unit" value={d.result_unit} onChange={(e) => setDraft(i, { result_unit: e.target.value })} className="h-8" />
                                    <Input aria-label={`Reference range for ${i.test_name}`} placeholder="Range" value={d.reference_range} onChange={(e) => setDraft(i, { reference_range: e.target.value })} className="mt-1 h-8" />
                                  </TableCell>
                                </>
                              ) : o.status === "completed" ? (
                                <>
                                  <TableCell className={cn(i.flag === "critical" && "font-semibold text-destructive", (i.flag === "high" || i.flag === "low") && "font-semibold")}>{i.result_value}{i.flag && i.flag !== "normal" && <span className="ml-1 text-xs uppercase">{i.flag}</span>}</TableCell>
                                  <TableCell className="hidden text-xs text-muted-foreground sm:table-cell">{[i.result_unit, i.reference_range].filter(Boolean).join(" · ")}</TableCell>
                                </>
                              ) : null}
                              <TableCell className="pr-3 text-right tabular-nums">{inr.format(i.price)}</TableCell>
                            </TableRow>
                          </Fragment>
                        );
                      })}
                    </TableBody>
                  </Table>
                </div>
              </div>

              <div className="flex flex-wrap gap-2">
                {o.status === "ordered" && (can("lab.results") || can("lab.manage")) && <Button disabled={busy} onClick={() => status("sample_collected")}>Mark sample collected</Button>}
                {canResults && <Button variant="outline" disabled={busy || !dirty} onClick={saveResults}>Save results</Button>}
                {o.status === "processing" && can("lab.results") && <Button disabled={busy || dirty || !allFilled} onClick={() => status("completed")} title={dirty ? "Save the results first" : !allFilled ? "Enter every result first" : ""}>Release report</Button>}
                {o.status === "completed" && <Button variant="outline" onClick={() => printReport(o)}><Printer aria-hidden /> Print report</Button>}
                {o.status !== "cancelled" && <Button variant="outline" onClick={() => printBill(o)}><Printer aria-hidden /> Print bill</Button>}
                {["ordered", "sample_collected"].includes(o.status) && can("lab.manage") && <Button variant="ghost" className="text-destructive" onClick={() => setCancelling(true)}>Cancel order</Button>}
              </div>

              <div className="grid gap-3 rounded-lg border p-3">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <h3 className="text-sm font-semibold">Payment <span className="font-normal text-muted-foreground">{o.bill.number}</span></h3>
                  <BalanceText order={o} />
                </div>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                  {[["Subtotal", o.bill.subtotal], ["Discount", o.bill.discount], ["Total", o.bill.total], ["Paid", o.bill.paid]].map(([k, v]) => (
                    <div key={k as string}><dt className="text-xs text-muted-foreground">{k}</dt><dd className="font-medium tabular-nums">{inr.format(v as number)}</dd></div>
                  ))}
                </dl>
                {o.payments.length > 0 && (
                  <ul className="divide-y rounded-md border text-sm">
                    {o.payments.map((p) => (
                      <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-1.5">
                        <span><span className="font-mono text-xs">{p.receipt_number}</span> · {formatDay(p.paid_at.slice(0, 10), { day: "numeric", month: "short" })} · <span className="capitalize">{p.method}</span>{p.reference_no ? ` · ${p.reference_no}` : ""}</span>
                        <span className={cn("font-medium tabular-nums", p.amount < 0 && "text-destructive")}>{inr.format(p.amount)}</span>
                      </li>
                    ))}
                  </ul>
                )}
                {can("lab.manage") && o.status !== "cancelled" && o.bill.balance > 0 && (
                  <form className="grid gap-2 sm:grid-cols-[1fr_8rem_1fr_auto] sm:items-end" onSubmit={(e) => { e.preventDefault(); pay(); }}>
                    <div className="grid gap-1"><Label htmlFor="op-amt" className="text-xs">Receive (₹)</Label>
                      <div className="flex gap-1"><Input id="op-amt" type="number" min={0} max={o.bill.balance} value={amount} onChange={(e) => setAmount(e.target.value)} /><Button type="button" variant="outline" size="sm" onClick={() => setAmount(String(o.bill.balance))}>Full</Button></div></div>
                    <div className="grid gap-1"><Label htmlFor="op-method" className="text-xs">Paid by</Label><NativeSelect id="op-method" value={method} onChange={(e) => setMethod(e.target.value as PayMethod)}>{PAY_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}</NativeSelect></div>
                    <div className="grid gap-1"><Label htmlFor="op-ref" className="text-xs">Reference</Label><Input id="op-ref" maxLength={100} value={ref} onChange={(e) => setRef(e.target.value)} /></div>
                    <Button type="submit" disabled={busy || !amount || Number(amount) <= 0 || Number(amount) > o.bill.balance}>Record payment</Button>
                  </form>
                )}
              </div>
              {o.notes && <p className="text-sm text-muted-foreground">Note: {o.notes}</p>}
              {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            </div>
          )}
          <DialogFooter><Button onClick={onClose}>Done</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={cancelling} onOpenChange={(v) => { if (!busy) { setCancelling(v); !v && setReason(""); } }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader><DialogTitle>Cancel this lab order?</DialogTitle>
            <DialogDescription>{o && o.bill.paid > 0 ? `The ${inr.format(o.bill.paid)} already paid is refunded on record.` : "Nothing has been paid yet."}</DialogDescription></DialogHeader>
          <div className="grid gap-1.5"><Label htmlFor="co-reason">Reason</Label><Input id="co-reason" maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus /></div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancelling(false)} disabled={busy}>Keep order</Button>
            <Button variant="destructive" disabled={busy || reason.trim().length < 3}
              onClick={async () => { await act(() => api(`/lab/orders/${id}/cancel`, { method: "POST", body: { reason: reason.trim() } }), "Order cancelled"); setCancelling(false); setReason(""); }}>
              {busy && <Loader2 className="animate-spin" aria-hidden />} Cancel order
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

// ---------- catalogue ----------

function CatalogueTab() {
  const tests = useApi<LabTest[]>("/lab/tests", { all: 1 });
  const [editing, setEditing] = useState<LabTest | "new" | null>(null);
  const grouped = useMemo(() => {
    const m = new Map<string, LabTest[]>();
    (tests.data ?? []).forEach((t) => m.set(t.category, [...(m.get(t.category) ?? []), t]));
    return [...m.entries()];
  }, [tests.data]);

  return (
    <>
      <PageHeader title="Test catalogue" description="The tests the lab offers and their prices. Prices from the starter list are placeholders: set your own."
        actions={<Button onClick={() => setEditing("new")}><Plus aria-hidden /> Add test</Button>} />
      <Card className="gap-0 p-0">
        {!tests.data ? <div className="grid gap-3 p-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-10" />)}</div> : (
          <Table>
            <TableHeader><TableRow><TableHead className="pl-4">Test</TableHead><TableHead className="hidden sm:table-cell">Sample</TableHead><TableHead className="hidden md:table-cell">Reference range</TableHead><TableHead className="text-right">Price</TableHead><TableHead className="w-12 pr-4" /></TableRow></TableHeader>
            <TableBody>
              {grouped.map(([cat, list]) => (
                <Fragment key={cat}>
                  <TableRow className="bg-muted/60 hover:bg-muted/60"><TableCell colSpan={5} className="py-1.5 pl-4 text-xs font-semibold tracking-wide uppercase">{cat}</TableCell></TableRow>
                  {list.map((t) => (
                    <TableRow key={t.id} className={cn(!t.is_active && "opacity-60")}>
                      <TableCell className="pl-4">{t.name}{!t.is_active && <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-[11px]">Off</span>}<span className="block font-mono text-xs text-muted-foreground">{t.code}</span></TableCell>
                      <TableCell className="hidden text-sm sm:table-cell">{t.sample_type ?? "—"}{t.turnaround_hours ? <span className="block text-xs text-muted-foreground">{t.turnaround_hours} h</span> : null}</TableCell>
                      <TableCell className="hidden text-xs text-muted-foreground md:table-cell">{[t.reference_range, t.unit].filter(Boolean).join(" ") || "—"}</TableCell>
                      <TableCell className="text-right tabular-nums">{inr.format(t.price)}</TableCell>
                      <TableCell className="pr-4"><Button size="icon-sm" variant="ghost" aria-label={`Edit ${t.name}`} onClick={() => setEditing(t)}><Pencil /></Button></TableCell>
                    </TableRow>
                  ))}
                </Fragment>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>
      {editing && <TestDialog test={editing === "new" ? null : editing} categories={grouped.map(([c]) => c)} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); tests.reload(); }} />}
    </>
  );
}

function TestDialog({ test, categories, onClose, onSaved }: { test: LabTest | null; categories: string[]; onClose: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ name: test?.name ?? "", category: test?.category ?? "", sample_type: test?.sample_type ?? "", price: String(test?.price ?? ""), turnaround_hours: test?.turnaround_hours == null ? "" : String(test.turnaround_hours),
    unit: test?.unit ?? "", reference_range: test?.reference_range ?? "", is_active: test?.is_active ?? true });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const set = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => setF((p) => ({ ...p, [k]: v }));

  async function save() {
    setBusy(true);
    setError("");
    try {
      const body = { name: f.name.trim(), category: f.category.trim(), sample_type: f.sample_type || null, price: Number(f.price), turnaround_hours: f.turnaround_hours === "" ? null : Number(f.turnaround_hours), unit: f.unit || null, reference_range: f.reference_range || null, is_active: f.is_active };
      await api(test ? `/lab/tests/${test.id}` : "/lab/tests", { method: test ? "PUT" : "POST", body });
      toast.success(test ? "Test updated" : "Test added");
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save");
      setBusy(false);
    }
  }
  const field = (id: string, label: string, control: React.ReactNode, wide = false) => <div className={cn("grid gap-1.5", wide && "sm:col-span-2")}><Label htmlFor={id}>{label}</Label>{control}</div>;

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader><DialogTitle>{test ? "Edit test" : "Add test"}</DialogTitle><DialogDescription>Changing a price affects new orders only; existing bills keep the price they were made with.</DialogDescription></DialogHeader>
        <div className="grid gap-4 sm:grid-cols-2">
          {field("lt-name", "Test name", <Input id="lt-name" maxLength={150} value={f.name} onChange={(e) => set("name", e.target.value)} autoFocus />, true)}
          {field("lt-cat", "Category", <><Input id="lt-cat" list="lt-cats" maxLength={60} placeholder="e.g. Biochemistry" value={f.category} onChange={(e) => set("category", e.target.value)} /><datalist id="lt-cats">{categories.map((c) => <option key={c} value={c} />)}</datalist></>)}
          {field("lt-price", "Price (₹)", <Input id="lt-price" type="number" min={0} step="10" value={f.price} onChange={(e) => set("price", e.target.value)} />)}
          {field("lt-sample", "Sample", <Input id="lt-sample" maxLength={40} placeholder="Blood, urine…" value={f.sample_type} onChange={(e) => set("sample_type", e.target.value)} />)}
          {field("lt-hours", "Report time (hours)", <Input id="lt-hours" type="number" min={0} value={f.turnaround_hours} onChange={(e) => set("turnaround_hours", e.target.value)} />)}
          {field("lt-unit", "Unit", <Input id="lt-unit" maxLength={30} placeholder="g/dL" value={f.unit} onChange={(e) => set("unit", e.target.value)} />)}
          {field("lt-range", "Reference range", <Input id="lt-range" maxLength={150} placeholder="12 - 16" value={f.reference_range} onChange={(e) => set("reference_range", e.target.value)} />)}
          <label className="flex cursor-pointer items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" className="size-4 accent-primary" checked={f.is_active} onChange={(e) => set("is_active", e.target.checked)} /> Offered to patients (switch off to hide it from new orders)</label>
        </div>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button onClick={save} disabled={busy || f.name.trim().length < 2 || f.category.trim().length < 2 || f.price === "" || Number(f.price) < 0}>{busy && <Loader2 className="animate-spin" aria-hidden />} Save</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------- on a patient's record ----------

export function PatientLab({ patientId, patientName }: { patientId: number; patientName: string }) {
  const { can } = useAuth();
  const list = useApi<Page<LabOrderRow>>("/lab/orders", { patient_id: patientId, page_size: 50 });
  const [open, setOpen] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  if (list.error) return <p role="alert" className="text-sm text-destructive">{list.error.message}</p>;
  if (!list.data) return <Skeleton className="h-24" />;
  return (
    <div className="grid gap-3">
      {can("lab.manage") && <div><Button size="sm" onClick={() => setCreating(true)}><Plus aria-hidden /> New lab order</Button></div>}
      {list.data.items.length === 0 ? <EmptyState icon={FlaskConical} title="No lab tests" description="Lab orders for this patient appear here." /> : (
        <ul className="grid gap-2">
          {list.data.items.map((o) => (
            <li key={o.id}>
              <button type="button" onClick={() => setOpen(o.id)} className="flex w-full flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-left text-sm hover:bg-muted">
                <span><span className="font-mono text-xs font-semibold text-primary">{o.order_number}</span> · {dateOf(o.created_at)}<span className="block text-xs text-muted-foreground">{o.tests}</span></span>
                <span className="flex items-center gap-3"><LabStatusBadge status={o.status} /><span className="text-right tabular-nums">{inr.format(o.bill.total)}<span className="block text-xs"><BalanceText order={o} /></span></span></span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {open !== null && <OrderDialog id={open} onClose={() => { setOpen(null); list.reload(); }} />}
      {creating && <NewOrderDialog patient={{ id: patientId, name: patientName }} onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); list.reload(); setOpen(id); }} />}
    </div>
  );
}
