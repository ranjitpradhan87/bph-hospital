import { ArrowLeft, CalendarCheck, CircleCheck, Loader2, MessageSquareText, CreditCard } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { DoctorAvatar } from "@/components/public/doctor-card";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { SlotPicker } from "@/components/shared/slot-picker";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { cn } from "@/lib/utils";
import { formatDay, formatFee, formatTime } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import type { Page } from "@/types/api";
import type { Appointment, OpenSlot } from "@/types/booking";
import type { PublicDepartment, PublicDoctor } from "@/types/public";

/** Patient booking: choose a doctor, a date and an open time, then confirm. */
export function BookPage({ guest = false }: { guest?: boolean }) {
  const { isStaff, user } = useAuth();
  const params = useSearchParams();
  const router = useRouter();
  const [doctorId, setDoctorId] = useState<number | null>(Number(params.get("doctor")) || null);
  const [dept, setDept] = useState("");
  const [slot, setSlot] = useState<{ id: number; open: OpenSlot; date: string } | null>(null);
  const [reason, setReason] = useState("");
  const [asking, setAsking] = useState(false);
  const [done, setDone] = useState<BookedSummary | null>(null);

  const depts = useApi<PublicDepartment[]>("/public/departments");
  const doctors = useApi<Page<PublicDoctor>>("/public/doctors", { department: dept || undefined, page_size: 50 });
  const doctor = doctors.data?.items.find((d) => d.id === doctorId);
  const single = useApi<PublicDoctor>(doctorId && !doctor ? `/public/doctors/${doctorId}` : null);
  const chosen = doctor ?? single.data;

  // Staff have no patient record of their own; they book on a patient's behalf from the admin panel.
  if (isStaff) {
    return (
      <>
        <PageHeader title="Book an appointment" />
        <EmptyState icon={CalendarCheck} title="This page is for patients"
          description="You are signed in as hospital staff, so there is no patient to book for. To book for a patient, open Appointments in the admin panel."
          action={<Link href="/admin/appointments" className={buttonVariants()}>Go to Appointments</Link>} />
      </>
    );
  }

  if (done) return <BookedCard done={done} guest={guest} />;

  return (
    <>
      <PageHeader title="Book an appointment" description="Choose a doctor and an open time." actions={<Link href={guest ? "/book" : "/portal"} className={buttonVariants({ variant: "outline" })}><ArrowLeft aria-hidden /> Back</Link>} />
      {!chosen ? (
        <Card>
          <CardHeader>
            <CardTitle className="font-heading">1. Choose a doctor</CardTitle>
            <CardDescription>
              <span className="mt-2 block max-w-xs">
                <Label htmlFor="bk-dept" className="sr-only">Department</Label>
                <NativeSelect id="bk-dept" value={dept} onChange={(e) => setDept(e.target.value)}>
                  <option value="">All departments</option>
                  {depts.data?.map((d) => <option key={d.id} value={d.slug}>{d.name}</option>)}
                </NativeSelect>
              </span>
            </CardDescription>
          </CardHeader>
          <CardContent>
            {!doctors.data ? <Skeleton className="h-32" /> : doctors.data.items.length === 0 ? (
              <EmptyState icon={CalendarCheck} title="No doctors available" description="Try another department." />
            ) : (
              <ul className="grid gap-3 sm:grid-cols-2">
                {doctors.data.items.map((d) => (
                  <li key={d.id}>
                    <button type="button" onClick={() => setDoctorId(d.id)} className="flex w-full items-center gap-3 rounded-xl border p-3 text-left transition-colors hover:border-primary hover:bg-primary/5">
                      <DoctorAvatar doctor={d} className="size-12 shrink-0 rounded-full" />
                      <span className="min-w-0">
                        <span className="block font-medium">{d.full_name}</span>
                        <span className="block truncate text-sm text-muted-foreground">{d.specialization} · {formatFee(d.consultation_fee)}</span>
                        <span className="block text-xs text-muted-foreground">{d.next_available_date ? `Next: ${formatDay(d.next_available_date)}` : "No open slots"}</span>
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle className="font-heading">2. Pick a time with {chosen.full_name}</CardTitle>
            <CardDescription>{chosen.specialization} · {formatFee(chosen.consultation_fee)} consultation.</CardDescription>
            <Button variant="ghost" size="sm" className="w-fit" onClick={() => { setDoctorId(null); setSlot(null); }}>Change doctor</Button>
          </CardHeader>
          <CardContent className="grid gap-5">
            <SlotPicker key={chosen.id} doctorId={chosen.id} value={slot?.id ?? null} initialDate={params.get("date") ?? undefined} onChange={(id, open, date) => setSlot(id && open && date ? { id, open, date } : null)} />
            {slot && (
              <>
                <div className="grid gap-1.5">
                  <Label htmlFor="bk-reason">Reason for visit <span className="font-normal text-muted-foreground">(optional)</span></Label>
                  <Textarea id="bk-reason" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Briefly, so the doctor can prepare" />
                  <p className="text-xs text-muted-foreground">Please do not include sensitive medical details here.</p>
                </div>
                <p className="rounded-lg bg-muted p-3 text-sm">You are booking <b>{formatDay(slot.date, { weekday: "long", day: "numeric", month: "long" })} at {formatTime(slot.open.start_time)}</b> with {chosen.full_name}.</p>
              </>
            )}
            <div><Button onClick={() => setAsking(true)} disabled={!slot}>Book appointment</Button></div>
          </CardContent>
        </Card>
      )}
      {asking && slot && chosen && (
        <BookingDialog guest={guest} slotId={slot.id} summary={`${formatDay(slot.date, { weekday: "long", day: "numeric", month: "long" })} at ${formatTime(slot.open.start_time)} with ${chosen.full_name}`}
          fee={chosen.consultation_fee} advanceAmount={chosen.advance_amount} reason={reason} accountMobile={user?.mobile ?? null}
          onClose={(taken) => { setAsking(false); if (taken) setSlot(null); }}
          onBooked={(b) => { setAsking(false); setDone(b); if (!guest) toast.success("Appointment booked"); }} />
      )}
    </>
  );
}

interface BookedSummary { number: string; doctor: string; date: string; time: string; mobile: string; accountCreated: boolean; advance: number; balance: number }

interface PaymentInfo {
  driver: "simulate" | "razorpay";
  order_id: string;
  amount: number;
  currency: string;
  key_id: string | null;
  sim_signature?: string;
  appointment_number: string;
  expires_at: string;
  name: string;
  prefill: { name: string | null; contact: string | null };
  balance: number;
}

interface Verified { appointment_number: string; doctor: string; appointment_date: string; start_time: string; mobile: string; account_created: boolean; advance_amount: number; consultation_fee: number }

const maskMobile = (m: string) => `${m.slice(0, 2)}XXXXX${m.slice(-3)}`;
const rupees = (n: number) => new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 0 }).format(n);

/** Razorpay's checkout script, loaded once when it is first needed. */
function loadRazorpay(): Promise<void> {
  const w = window as unknown as { Razorpay?: unknown };
  if (w.Razorpay) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const s = document.createElement("script");
    s.src = "https://checkout.razorpay.com/v1/checkout.js";
    s.onload = () => resolve();
    s.onerror = () => reject(new Error("The payment window could not be loaded. Check your connection and try again."));
    document.head.appendChild(s);
  });
}

function BookedCard({ done, guest }: { done: BookedSummary; guest: boolean }) {
  return (
    <Card className="mx-auto max-w-xl">
      <CardHeader className="items-center text-center">
        <CircleCheck className="size-12 text-success" aria-hidden />
        <CardTitle className="font-heading text-2xl">Your appointment is confirmed</CardTitle>
        <CardDescription>{done.number}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4 text-center">
        <p className="rounded-lg bg-muted p-3 text-sm"><b>{formatDay(done.date, { weekday: "long", day: "numeric", month: "long" })} at {formatTime(done.time)}</b><br />with {done.doctor}</p>
        {done.advance > 0 && <p className="text-sm">Advance of <b>{rupees(done.advance)}</b> paid online.{done.balance > 0 && <> The balance of <b>{rupees(done.balance)}</b> is payable at the hospital.</>}</p>}
        <p className="flex items-start justify-center gap-2 text-sm text-muted-foreground"><MessageSquareText className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span>We sent a text message with these details to {maskMobile(done.mobile)}.{done.accountCreated && " Our staff will complete your details at the hospital, then text you a link to set a password. From your next visit you can sign in with your mobile number."}</span></p>
        <div className="flex flex-wrap justify-center gap-2">
          {guest ? <Link href="/login" className={buttonVariants({ variant: "outline" })}>Sign in</Link> : <Link href="/portal/appointments" className={buttonVariants()}>My appointments</Link>}
          <Link href="/" className={buttonVariants({ variant: guest ? "default" : "outline" })}>Back to home</Link>
        </div>
      </CardContent>
    </Card>
  );
}

/**
 * After a time is chosen: ask for the mobile number (and, for a first visit, a few details), then take the
 * advance online. The time is held while the payment is made, and the booking is confirmed once it succeeds.
 */
function BookingDialog({ guest, slotId, summary, fee, advanceAmount, reason, accountMobile, onClose, onBooked }: {
  guest: boolean; slotId: number; summary: string; fee: string | number; advanceAmount: number; reason: string; accountMobile: string | null;
  onClose: (slotTaken: boolean) => void; onBooked: (b: BookedSummary) => void;
}) {
  const [f, setF] = useState({ full_name: "", mobile: "", consent: false });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [exists, setExists] = useState(false);
  const [pay, setPay] = useState<PaymentInfo | null>(null);
  const set = <K extends keyof typeof f>(k: K, v: (typeof f)[K]) => setF((p) => ({ ...p, [k]: v }));
  const mobile = guest ? f.mobile.replace(/\D/g, "").slice(-10) : accountMobile ?? "";
  const ready = guest ? f.full_name.trim().length >= 2 && /^[6-9]\d{9}$/.test(mobile) && f.consent : true;
  const feeNum = Number(fee);
  const advance = Math.min(feeNum, advanceAmount);

  const finish = (v: Verified) => onBooked({ number: v.appointment_number, doctor: v.doctor, date: v.appointment_date, time: v.start_time, mobile: v.mobile, accountCreated: v.account_created, advance: v.advance_amount, balance: Math.max(0, v.consultation_fee - v.advance_amount) });

  async function verify(orderId: string, paymentId: string, signature: string) {
    setBusy(true);
    setError("");
    try {
      finish(await api<Verified>("/public/payments/verify", { method: "POST", body: { order_id: orderId, payment_id: paymentId, signature } }));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "We could not confirm the payment. If money was taken it will be returned; please call the hospital.");
      setBusy(false);
    }
  }

  async function openPayment(p: PaymentInfo) {
    setError("");
    if (p.driver === "simulate") return; // the dialog shows the test-mode button instead
    try {
      await loadRazorpay();
    } catch (err) {
      return setError(err instanceof Error ? err.message : "The payment window could not be opened");
    }
    const Razorpay = (window as unknown as { Razorpay: new (o: Record<string, unknown>) => { open: () => void } }).Razorpay;
    new Razorpay({
      key: p.key_id, order_id: p.order_id, amount: p.amount, currency: p.currency, name: p.name, description: `Advance for appointment ${p.appointment_number}`,
      prefill: { name: p.prefill.name ?? undefined, contact: p.prefill.contact ?? undefined }, theme: { color: "#0b66c3" },
      handler: (r: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) => verify(r.razorpay_order_id, r.razorpay_payment_id, r.razorpay_signature),
    }).open();
  }

  async function submit() {
    setBusy(true);
    setError("");
    setExists(false);
    try {
      type Booked = { payment: PaymentInfo | null } & Verified & { appointment_date: string };
      let r: Booked;
      if (guest) {
        r = await api<Booked>("/public/bookings", { method: "POST", body: { slot_id: slotId, full_name: f.full_name.trim(), mobile, consent: f.consent, reason: reason || null } });
      } else {
        const a = await api<{ appointment_number: string; doctor_name: string; appointment_date: string; start_time: string; consultation_fee: string; advance_amount: string; payment?: PaymentInfo | null }>("/appointments", { method: "POST", body: { slot_id: slotId, reason: reason || null } });
        r = { appointment_number: a.appointment_number, doctor: a.doctor_name, appointment_date: a.appointment_date, start_time: a.start_time, mobile, account_created: false, advance_amount: Number(a.advance_amount), consultation_fee: Number(a.consultation_fee), payment: a.payment ?? null };
      }
      if (r.payment) {
        setPay(r.payment);
        setBusy(false);
        await openPayment(r.payment);
      } else {
        finish(r);
      }
    } catch (err) {
      const e = err instanceof ApiError ? err : null;
      setError(e?.message ?? "Could not book the appointment");
      if (e?.status === 409 && e.message.includes("already has an account")) setExists(true);
      else if (e?.status === 409) { onClose(true); toast.error(e.message); return; }
      setBusy(false);
    }
  }

  const holdUntil = pay ? formatTime(new Date(pay.expires_at).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: "Asia/Kolkata" })) : "";

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose(false)}>
      <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{pay ? "Pay the advance to confirm" : guest ? "Your mobile number" : "Confirm your booking"}</DialogTitle>
          <DialogDescription>{summary}</DialogDescription>
        </DialogHeader>

        {pay ? (
          <div className="grid gap-4">
            <p className="rounded-lg bg-muted p-3 text-sm">Your time is held for you until <b>{holdUntil}</b>. Pay <b>{rupees(pay.amount / 100)}</b> now to confirm it{pay.balance > 0 && <>; the balance of {rupees(pay.balance)} is paid at the hospital</>}.</p>
            {pay.driver === "simulate" && (
              <div className="grid gap-2 rounded-lg border border-dashed p-3 text-sm">
                <p><b>Test mode</b> — no real payment gateway is connected, so no money is charged.</p>
                <Button disabled={busy} onClick={() => verify(pay.order_id, "sim_payment", pay.sim_signature ?? "")}>{busy && <Loader2 className="animate-spin" aria-hidden />} Simulate payment of {rupees(pay.amount / 100)}</Button>
              </div>
            )}
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            <DialogFooter>
              <Button variant="outline" onClick={() => onClose(false)} disabled={busy}>Close</Button>
              {pay.driver === "razorpay" && <Button onClick={() => openPayment(pay)} disabled={busy}><CreditCard aria-hidden /> Pay {rupees(pay.amount / 100)}</Button>}
            </DialogFooter>
          </div>
        ) : (
          <>
            <div className="grid gap-4">
              {guest ? (
                <>
                  <div className="grid gap-1.5"><Label htmlFor="bd-mobile">Mobile number</Label>
                    <Input id="bd-mobile" type="tel" inputMode="numeric" autoComplete="tel-national" placeholder="10-digit mobile" maxLength={14} value={f.mobile} onChange={(e) => set("mobile", e.target.value)} autoFocus />
                    <p className="text-xs text-muted-foreground">We text your confirmation here. Our staff will complete your details, then text you a link to set a password for your next visit.</p></div>
                  <div className="grid gap-1.5"><Label htmlFor="bd-name">Full name</Label><Input id="bd-name" autoComplete="name" maxLength={150} value={f.full_name} onChange={(e) => set("full_name", e.target.value)} /></div>
                </>
              ) : (
                <p className="flex items-start gap-2 rounded-lg bg-muted p-3 text-sm"><MessageSquareText className="mt-0.5 size-4 shrink-0" aria-hidden />
                  <span>We will text your confirmation to <b>{accountMobile ? maskMobile(accountMobile) : "your registered mobile number"}</b>.</span></p>
              )}

              <div className="rounded-lg border border-primary bg-primary/5 p-3 text-sm">
                <p className="flex items-center gap-2 font-medium"><CreditCard className="size-4" aria-hidden /> {advance > 0 ? <>Advance payment {rupees(advance)}</> : "No advance payment needed"}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  {advance > 0
                    ? <>Paid online to confirm your time. Consultation fee {formatFee(fee)}{feeNum > advance && <> — the balance of {rupees(feeNum - advance)} is paid at the hospital</>}.</>
                    : <>Your appointment is confirmed straight away. Consultation fee {formatFee(fee)} is paid at the hospital.</>}
                </p>
              </div>

              {guest && (
                <label className="flex cursor-pointer items-start gap-2 text-xs text-muted-foreground">
                  <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={f.consent} onChange={(e) => set("consent", e.target.checked)} />
                  <span>I agree to the hospital keeping these details to manage my appointments and records.</span>
                </label>
              )}
              {error && (
                <p role="alert" className="text-sm text-destructive">
                  {error} {exists && <Link href="/login" className="font-medium underline">Sign in</Link>}
                </p>
              )}
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => onClose(false)} disabled={busy}>Back</Button>
              <Button onClick={submit} disabled={busy || !ready}>{busy && <Loader2 className="animate-spin" aria-hidden />} {advance > 0 ? `Pay ${rupees(advance)} & book` : "Confirm booking"}</Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}

/** Reopen the payment for a booking that is still being held, from "My appointments". */
export function PayHeldBooking({ appointment, onClose }: { appointment: Appointment; onClose: () => void }) {
  const [pay, setPay] = useState<PaymentInfo | null>(null);
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function open(p: PaymentInfo) {
    if (p.driver === "simulate") return;
    try {
      await loadRazorpay();
    } catch (err) {
      return setError(err instanceof Error ? err.message : "The payment window could not be opened");
    }
    const Razorpay = (window as unknown as { Razorpay: new (o: Record<string, unknown>) => { open: () => void } }).Razorpay;
    new Razorpay({
      key: p.key_id, order_id: p.order_id, amount: p.amount, currency: p.currency, name: p.name, description: `Advance for appointment ${p.appointment_number}`,
      prefill: { name: p.prefill.name ?? undefined, contact: p.prefill.contact ?? undefined }, theme: { color: "#0b66c3" },
      handler: (r: { razorpay_order_id: string; razorpay_payment_id: string; razorpay_signature: string }) => verify(r.razorpay_order_id, r.razorpay_payment_id, r.razorpay_signature),
    }).open();
  }
  async function verify(orderId: string, paymentId: string, signature: string) {
    setBusy(true);
    setError("");
    try {
      await api("/public/payments/verify", { method: "POST", body: { order_id: orderId, payment_id: paymentId, signature } });
      setDone(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "We could not confirm the payment.");
    } finally {
      setBusy(false);
    }
  }
  async function start() {
    setBusy(true);
    setError("");
    try {
      const p = await api<PaymentInfo>(`/appointments/${appointment.id}/pay`, { method: "POST" });
      setPay(p);
      setBusy(false);
      await open(p);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not start the payment");
      setBusy(false);
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{done ? "Appointment confirmed" : "Pay the advance to confirm"}</DialogTitle>
          <DialogDescription>{formatDay(appointment.appointment_date, { weekday: "long", day: "numeric", month: "long" })} at {formatTime(appointment.start_time)} with {appointment.doctor_name}</DialogDescription>
        </DialogHeader>
        {done ? <p className="flex items-start gap-2 text-sm"><CircleCheck className="size-5 shrink-0 text-success" aria-hidden /> Payment received. We have texted you the confirmation.</p> : (
          <div className="grid gap-3">
            <p className="rounded-lg bg-muted p-3 text-sm">Your time stays held only for a short while. The booking is confirmed as soon as the advance is paid.</p>
            {pay?.driver === "simulate" && (
              <div className="grid gap-2 rounded-lg border border-dashed p-3 text-sm">
                <p><b>Test mode</b> — no money is charged.</p>
                <Button disabled={busy} onClick={() => verify(pay.order_id, "sim_payment", pay.sim_signature ?? "")}>{busy && <Loader2 className="animate-spin" aria-hidden />} Simulate payment of {rupees(pay.amount / 100)}</Button>
              </div>
            )}
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
          </div>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>{done ? "Done" : "Close"}</Button>
          {!done && (!pay || pay.driver === "razorpay") && <Button onClick={() => (pay ? open(pay) : start())} disabled={busy}>{busy && <Loader2 className="animate-spin" aria-hidden />} <CreditCard aria-hidden /> Pay advance</Button>}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
