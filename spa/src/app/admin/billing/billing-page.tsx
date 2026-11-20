import { Loader2, Plus, Printer, ReceiptIndianRupee, Search, Trash2 } from "lucide-react";
import Link from "next/link";
import { useDeferredValue, useState } from "react";
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
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { inr } from "@/lib/forms";
import { printInvoice } from "@/lib/print-report";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import { BILL_STATUS_LABEL, type BillDetail, type BillList, type BillRow, type BillStatus } from "@/types/billing";
import { PAY_METHODS, type PayMethod } from "@/types/lab";
import type { PatientListItem } from "@/types/management";

const PAGE_SIZE = 20;
const day = (d: string) => formatDay(d.slice(0, 10), { day: "numeric", month: "short", year: "numeric" });

const STATUS_STYLE: Record<BillStatus, string> = {
  pending: "bg-orange-500/12 text-orange-700 ring-orange-500/30 dark:text-orange-400",
  partially_paid: "bg-warning/15 text-amber-700 ring-warning/40 dark:text-warning",
  paid: "bg-success/12 text-success ring-success/30",
  cancelled: "bg-slot-cancelled/15 text-muted-foreground ring-slot-cancelled/40 line-through",
};

export function BillStatusBadge({ status }: { status: BillStatus }) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset", STATUS_STYLE[status])}>
      <span aria-hidden className="size-1.5 rounded-full bg-current" />{BILL_STATUS_LABEL[status]}
    </span>
  );
}

export function BillingPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("billing.read")}>
      <BillingView />
    </RequireAuth>
  );
}

function Stat({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <Card className="gap-1 p-4">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={cn("text-xl font-semibold tabular-nums", tone)}>{value}</p>
    </Card>
  );
}

function BillingView() {
  const { can } = useAuth();
  const [search, setSearch] = useState("");
  const q = useDeferredValue(search.trim());
  const [status, setStatus] = useState("");
  const [source, setSource] = useState("");
  const [unpaid, setUnpaid] = useState(false);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [open, setOpen] = useState<number | null>(null);
  const list = useApi<BillList>("/billing/bills", { q: q || undefined, status: status || undefined, source: source || undefined, unpaid: unpaid ? 1 : undefined, from: from || undefined, to: to || undefined, page, page_size: PAGE_SIZE });
  const reset = () => setPage(1);
  const s = list.data?.summary;
  const filtered = Boolean(q || status || source || unpaid || from || to);

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader title="Billing" description="Bills and payments for consultations, procedures and lab tests."
        actions={can("billing.manage") && <Button onClick={() => setCreating(true)}><Plus aria-hidden /> New bill</Button>} />
      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <Stat label={filtered ? "Billed (this list)" : "Billed"} value={s ? inr.format(s.billed) : "—"} />
        <Stat label="Collected" value={s ? inr.format(s.collected) : "—"} tone="text-success" />
        <Stat label="Outstanding" value={s ? inr.format(s.outstanding) : "—"} tone={s && s.outstanding > 0 ? "text-orange-600 dark:text-orange-400" : undefined} />
      </div>
      <Card className="gap-0 p-0">
        <div className="grid gap-2 border-b p-3 sm:grid-cols-2 lg:grid-cols-[1fr_9rem_9rem_9rem_9rem_auto] lg:items-center">
          <div className="relative sm:col-span-2 lg:col-span-1">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input type="search" aria-label="Search bills" placeholder="Bill no., patient, ID or mobile" className="pl-8" value={search} onChange={(e) => { setSearch(e.target.value); reset(); }} />
          </div>
          <NativeSelect aria-label="Status" value={status} onChange={(e) => { setStatus(e.target.value); reset(); }}>
            <option value="">All statuses</option>
            {(Object.keys(BILL_STATUS_LABEL) as BillStatus[]).map((k) => <option key={k} value={k}>{BILL_STATUS_LABEL[k]}</option>)}
          </NativeSelect>
          <NativeSelect aria-label="Bill type" value={source} onChange={(e) => { setSource(e.target.value); reset(); }}>
            <option value="">All bills</option>
            <option value="counter">Counter bills</option>
            <option value="lab">Lab bills</option>
          </NativeSelect>
          <Input type="date" aria-label="From date" value={from} max={to || undefined} onChange={(e) => { setFrom(e.target.value); reset(); }} />
          <Input type="date" aria-label="To date" value={to} min={from || undefined} onChange={(e) => { setTo(e.target.value); reset(); }} />
          <label className="flex cursor-pointer items-center gap-2 text-sm whitespace-nowrap text-muted-foreground">
            <input type="checkbox" className="size-4 accent-primary" checked={unpaid} onChange={(e) => { setUnpaid(e.target.checked); reset(); }} /> Unpaid only
          </label>
        </div>
        {list.error ? <p role="alert" className="p-6 text-sm text-destructive">{list.error.message}</p>
        : !list.data ? <div className="grid gap-3 p-4">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
        : list.data.items.length === 0 ? <EmptyState icon={ReceiptIndianRupee} title={filtered ? "No matching bills" : "No bills yet"} description={!filtered && can("billing.manage") ? "Create a bill for a consultation or procedure. Lab tests are billed from the Lab section." : undefined} />
        : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Bill</TableHead>
                  <TableHead>Patient</TableHead>
                  <TableHead className="hidden sm:table-cell">Type</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="pr-4 text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {list.data.items.map((b) => <BillRowView key={b.id} b={b} onOpen={() => setOpen(b.id)} />)}
              </TableBody>
            </Table>
            <Pagination page={page} total={list.data.total} pageSize={PAGE_SIZE} onPage={setPage} noun="bill" />
          </>
        )}
      </Card>
      {creating && <NewBillDialog onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); list.reload(); setOpen(id); }} />}
      {open !== null && <BillDialog id={open} onClose={() => { setOpen(null); list.reload(); }} />}
    </div>
  );
}

function BillRowView({ b, onOpen }: { b: BillRow; onOpen: () => void }) {
  return (
    <TableRow className="cursor-pointer" onClick={onOpen}>
      <TableCell className="pl-4"><span className="font-mono text-xs font-semibold text-primary">{b.bill_number}</span><span className="block text-xs text-muted-foreground">{day(b.bill_date)}</span></TableCell>
      <TableCell>{b.patient_name}<span className="block font-mono text-xs text-muted-foreground">{b.patient_code}</span></TableCell>
      <TableCell className="hidden text-xs text-muted-foreground sm:table-cell">{b.source === "lab" ? `Lab · ${b.lab_order_number ?? ""}` : "Counter"}</TableCell>
      <TableCell><BillStatusBadge status={b.status} /></TableCell>
      <TableCell className="pr-4 text-right tabular-nums">{inr.format(b.total)}
        {b.status !== "cancelled" && b.balance > 0 && <span className="block text-xs font-medium text-orange-600 dark:text-orange-400">{inr.format(b.balance)} due</span>}</TableCell>
    </TableRow>
  );
}

// ---------- one bill ----------

function BillDialog({ id, onClose }: { id: number; onClose: () => void }) {
  const { can } = useAuth();
  const detail = useApi<BillDetail>(`/billing/bills/${id}`);
  const b = detail.data;
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState<PayMethod>("cash");
  const [ref, setRef] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState("");

  async function run(fn: () => Promise<unknown>, ok: string) {
    setBusy(true);
    setError("");
    try { await fn(); toast.success(ok); detail.reload(); return true; }
    catch (err) { setError(err instanceof ApiError ? err.message : "That did not work"); return false; }
    finally { setBusy(false); }
  }
  const collect = async (full?: number) => {
    const value = full ?? Number(amount);
    if (await run(() => api(`/billing/bills/${id}/payments`, { method: "POST", body: { amount: value, method, reference_no: ref || null } }), "Payment recorded")) { setAmount(""); setRef(""); }
  };

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{b ? `Bill ${b.bill_number}` : "Bill"}</DialogTitle>
          <DialogDescription>{b ? <>{day(b.bill_date)} · {can("patients.read") ? <Link href={`/admin/patients/${b.patient_id}`} className="text-primary hover:underline">{b.patient_name}</Link> : b.patient_name} <span className="font-mono text-xs">{b.patient_code}</span></> : "Loading…"}</DialogDescription>
        </DialogHeader>
        {!b ? <Skeleton className="h-40" /> : (
          <div className="grid gap-4">
            <div className="flex items-center justify-between gap-2">
              <BillStatusBadge status={b.status} />
              {b.source === "lab" && <span className="text-xs text-muted-foreground">Lab order {b.lab_order_number}. Manage it in the Lab section.</span>}
            </div>
            <Table>
              <TableHeader><TableRow><TableHead>Item</TableHead><TableHead className="text-right">Qty</TableHead><TableHead className="text-right">Rate</TableHead><TableHead className="text-right">Amount</TableHead></TableRow></TableHeader>
              <TableBody>
                {b.items.map((i) => <TableRow key={i.id}><TableCell>{i.description}</TableCell><TableCell className="text-right tabular-nums">{i.quantity}</TableCell><TableCell className="text-right tabular-nums">{inr.format(i.unit_price)}</TableCell><TableCell className="text-right tabular-nums">{inr.format(i.line_total)}</TableCell></TableRow>)}
              </TableBody>
            </Table>
            <dl className="ml-auto grid w-full max-w-xs grid-cols-2 gap-x-4 gap-y-1 text-sm">
              <dt className="text-muted-foreground">Subtotal</dt><dd className="text-right tabular-nums">{inr.format(b.subtotal)}</dd>
              {b.discount > 0 && <><dt className="text-muted-foreground">Discount</dt><dd className="text-right tabular-nums">− {inr.format(b.discount)}</dd></>}
              <dt className="font-medium">Total</dt><dd className="text-right font-semibold tabular-nums">{inr.format(b.total)}</dd>
              <dt className="text-muted-foreground">Paid</dt><dd className="text-right text-success tabular-nums">{inr.format(b.paid)}</dd>
              {b.status !== "cancelled" && <><dt className="font-medium">Balance</dt><dd className={cn("text-right font-semibold tabular-nums", b.balance > 0 && "text-orange-600 dark:text-orange-400")}>{inr.format(b.balance)}</dd></>}
            </dl>
            {b.status === "cancelled" && <p className="rounded-lg bg-muted p-2 text-sm text-muted-foreground">Cancelled: {b.cancel_reason}</p>}
            {b.notes && <p className="text-sm text-muted-foreground">{b.notes}</p>}

            {b.payments.length > 0 && (
              <div>
                <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">Payments</p>
                <ul className="divide-y rounded-lg border text-sm">
                  {b.payments.map((p) => (
                    <li key={p.id} className="flex items-center justify-between gap-3 px-3 py-2">
                      <span><span className="font-mono text-xs">{p.receipt_number}</span> <span className="text-muted-foreground">· {day(p.paid_at)} · {p.method}{p.reference_no ? ` · ${p.reference_no}` : ""}{p.received_by ? ` · ${p.received_by}` : ""}</span></span>
                      <span className={cn("font-medium tabular-nums", p.amount < 0 && "text-destructive")}>{inr.format(p.amount)}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {can("payments.collect") && b.status !== "cancelled" && b.balance > 0 && (
              <form className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[8rem_9rem_1fr_auto]" onSubmit={(e) => { e.preventDefault(); void collect(); }}>
                <div className="grid gap-1.5"><Label htmlFor="bp-amt">Amount (₹)</Label><Input id="bp-amt" type="number" min="0" step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={String(b.balance)} /></div>
                <div className="grid gap-1.5"><Label htmlFor="bp-method">Paid by</Label>
                  <NativeSelect id="bp-method" value={method} onChange={(e) => setMethod(e.target.value as PayMethod)}>{PAY_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}</NativeSelect></div>
                <div className="grid gap-1.5"><Label htmlFor="bp-ref">Reference <span className="font-normal text-muted-foreground">(optional)</span></Label><Input id="bp-ref" maxLength={100} value={ref} onChange={(e) => setRef(e.target.value)} placeholder="UPI / cheque no." /></div>
                <div className="flex items-end gap-2">
                  <Button type="submit" disabled={busy || !(Number(amount) > 0) || Number(amount) > b.balance}>{busy && <Loader2 className="animate-spin" aria-hidden />} Collect</Button>
                  <Button type="button" variant="outline" disabled={busy} onClick={() => void collect(b.balance)}>Full {inr.format(b.balance)}</Button>
                </div>
              </form>
            )}

            {cancelling && (
              <div className="grid gap-2 rounded-lg border border-destructive/40 p-3">
                <Label htmlFor="bc-reason">Why is this bill being cancelled?{b.paid > 0 && ` The ${inr.format(b.paid)} paid is recorded as refunded.`}</Label>
                <Textarea id="bc-reason" rows={2} maxLength={255} value={reason} onChange={(e) => setReason(e.target.value)} />
                <div className="flex gap-2">
                  <Button variant="destructive" disabled={busy || reason.trim().length < 3} onClick={async () => { if (await run(() => api(`/billing/bills/${id}/cancel`, { method: "POST", body: { reason: reason.trim() } }), "Bill cancelled")) setCancelling(false); }}>Cancel bill</Button>
                  <Button variant="ghost" onClick={() => setCancelling(false)}>Keep it</Button>
                </div>
              </div>
            )}
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          </div>
        )}
        <DialogFooter className="gap-2 sm:justify-between">
          <span>{b && b.source === "counter" && b.status !== "cancelled" && can("billing.manage") && !cancelling && <Button variant="ghost" className="text-destructive" onClick={() => setCancelling(true)}>Cancel bill</Button>}</span>
          <span className="flex gap-2">
            {b && <Button variant="outline" onClick={() => printInvoice(b) || toast.error("Allow pop-ups to print")}><Printer aria-hidden /> Print</Button>}
            <Button variant="outline" onClick={onClose}>Close</Button>
          </span>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ---------- new bill ----------

interface Line { description: string; quantity: string; unit_price: string }
const blankLine = (): Line => ({ description: "", quantity: "1", unit_price: "" });

function NewBillDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (id: number) => void }) {
  const { can } = useAuth();
  const [linked, setLinked] = useState<{ id: number; name: string; code: string } | null>(null);
  const [term, setTerm] = useState("");
  const deferred = useDeferredValue(term.trim());
  const found = useApi<Page<PatientListItem>>(can("patients.read") && deferred.length >= 2 && !linked ? "/patients" : null, { search: deferred, page_size: 5 });
  const [lines, setLines] = useState<Line[]>([blankLine()]);
  const [discount, setDiscount] = useState("");
  const [notes, setNotes] = useState("");
  const [payNow, setPayNow] = useState("");
  const [method, setMethod] = useState<PayMethod>("cash");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const setLine = (i: number, patch: Partial<Line>) => setLines((c) => c.map((l, n) => (n === i ? { ...l, ...patch } : l)));
  const subtotal = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
  const disc = Math.min(Math.max(Number(discount) || 0, 0), subtotal);
  const total = subtotal - disc;
  const paying = Number(payNow) || 0;
  const complete = lines.filter((l) => l.description.trim().length >= 2 && Number(l.quantity) > 0 && l.unit_price !== "");
  const ready = linked && complete.length === lines.length && total > 0 && disc < subtotal && paying >= 0 && paying <= total;

  async function submit() {
    if (!linked) return;
    setBusy(true);
    setError("");
    try {
      const body = {
        patient_id: linked.id, discount: disc || null, notes: notes || null,
        items: lines.map((l) => ({ description: l.description.trim(), quantity: Number(l.quantity), unit_price: Number(l.unit_price) })),
        payment: paying > 0 && can("payments.collect") ? { amount: paying, method } : null,
      };
      const created = await api<BillDetail>("/billing/bills", { method: "POST", body });
      toast.success(`Bill ${created.bill_number} created`);
      onCreated(created.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not create the bill");
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader><DialogTitle>New bill</DialogTitle><DialogDescription>For a consultation, procedure or anything else billed at the counter. Lab tests are billed from the Lab section.</DialogDescription></DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-2">
            <Label htmlFor="nb-patient">Patient</Label>
            {linked ? (
              <div className="flex items-center justify-between rounded-lg border bg-muted/40 p-2 text-sm">
                <span><b>{linked.name}</b> <span className="font-mono text-xs text-muted-foreground">{linked.code}</span></span>
                <Button size="sm" variant="ghost" onClick={() => setLinked(null)}>Choose another</Button>
              </div>
            ) : (
              <>
                <Input id="nb-patient" placeholder="Search patients by name, ID or mobile" value={term} onChange={(e) => setTerm(e.target.value)} autoComplete="off" />
                {found.data && found.data.items.length > 0 && (
                  <ul className="grid gap-1 rounded-lg border p-1">
                    {found.data.items.map((p) => (
                      <li key={p.id}><button type="button" className="w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted" onClick={() => { setLinked({ id: p.id, name: p.full_name, code: p.patient_code }); setTerm(""); }}>
                        <b>{p.full_name}</b> <span className="text-muted-foreground">{p.patient_code} · {p.mobile ?? "no mobile"}</span></button></li>
                    ))}
                  </ul>
                )}
                {deferred.length >= 2 && found.data && found.data.items.length === 0 && <p className="text-xs text-muted-foreground">No patient found. Register them under Patients first.</p>}
              </>
            )}
          </div>

          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm leading-none font-medium">Items</legend>
            {lines.map((l, i) => (
              <div key={i} className="grid grid-cols-[1fr_4.5rem_6.5rem_auto] items-center gap-2">
                <Input aria-label={`Item ${i + 1}`} placeholder="Consultation, dressing, procedure…" maxLength={255} value={l.description} onChange={(e) => setLine(i, { description: e.target.value })} />
                <Input aria-label="Quantity" type="number" min="0" step="1" inputMode="decimal" value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} />
                <Input aria-label="Rate" type="number" min="0" step="0.01" inputMode="decimal" placeholder="Rate ₹" value={l.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} />
                <Button type="button" size="icon-sm" variant="ghost" aria-label="Remove item" disabled={lines.length === 1} onClick={() => setLines((c) => c.filter((_, n) => n !== i))}><Trash2 /></Button>
              </div>
            ))}
            <Button type="button" size="sm" variant="outline" className="w-fit" disabled={lines.length >= 30} onClick={() => setLines((c) => [...c, blankLine()])}><Plus aria-hidden /> Add item</Button>
          </fieldset>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="grid gap-1.5"><Label htmlFor="nb-disc">Discount (₹)</Label><Input id="nb-disc" type="number" min="0" step="0.01" inputMode="decimal" value={discount} onChange={(e) => setDiscount(e.target.value)} /></div>
            <div className="grid gap-1.5"><Label htmlFor="nb-notes">Note <span className="font-normal text-muted-foreground">(optional)</span></Label><Input id="nb-notes" maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} /></div>
          </div>

          <div className="grid gap-1 rounded-lg bg-muted p-3 text-sm">
            <div className="flex justify-between"><span className="text-muted-foreground">Subtotal</span><span className="tabular-nums">{inr.format(subtotal)}</span></div>
            {disc > 0 && <div className="flex justify-between"><span className="text-muted-foreground">Discount</span><span className="tabular-nums">− {inr.format(disc)}</span></div>}
            <div className="flex justify-between font-semibold"><span>Total</span><span className="tabular-nums">{inr.format(total)}</span></div>
          </div>

          {can("payments.collect") && (
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="grid gap-1.5"><Label htmlFor="nb-pay">Collect now (₹)</Label><Input id="nb-pay" type="number" min="0" step="0.01" inputMode="decimal" placeholder="Leave empty to bill only" value={payNow} onChange={(e) => setPayNow(e.target.value)} />
                {paying > total && <p className="text-xs text-destructive">More than the bill total.</p>}</div>
              <div className="grid gap-1.5"><Label htmlFor="nb-method">Paid by</Label><NativeSelect id="nb-method" value={method} onChange={(e) => setMethod(e.target.value as PayMethod)}>{PAY_METHODS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}</NativeSelect></div>
            </div>
          )}
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button onClick={submit} disabled={busy || !ready}>{busy && <Loader2 className="animate-spin" aria-hidden />} Create bill</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
