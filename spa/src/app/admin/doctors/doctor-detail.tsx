"use client";

import { ArrowLeft, CalendarCheck, CalendarClock, ExternalLink, Loader2, Pencil, Percent, Trash2, Users } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { DoctorForm } from "@/app/admin/doctors/doctor-form";
import { DoctorStatusBadge } from "@/app/admin/doctors/doctor-status";
import { refreshPublicContent } from "@/app/admin/settings/actions";
import { DoctorAvatar } from "@/components/public/doctor-card";
import { AppointmentsTable } from "@/components/shared/appointments-table";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Pagination } from "@/components/shared/pagination";
import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { DoctorIpd } from "@/app/admin/ipd/patient-ipd";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ApiError, api, getAccessToken } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { useApi } from "@/lib/use-api";
import type { Page } from "@/types/api";
import type { AppointmentRow, DoctorAdmin, DoctorStats, DoctorStatus } from "@/types/management";

export function DoctorDetailPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.some((p) => p === "doctors.read" || p === "doctors.manage")}>
      <DoctorDetail />
    </RequireAuth>
  );
}

function DoctorDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const doctor = useApi<DoctorAdmin>(`/doctors/${id}`);
  const [saved, setSaved] = useState<DoctorAdmin | null>(null);
  const [confirm, setConfirm] = useState<"delete" | "inactive" | null>(null);
  const d = saved?.id === Number(id) ? saved : doctor.data;

  if (doctor.error) {
    return <p role="alert" className="mx-auto max-w-5xl text-sm text-destructive">{doctor.error.status === 404 ? "This doctor doesn't exist or was removed." : doctor.error.message}</p>;
  }
  if (!d) return <div className="mx-auto grid max-w-5xl gap-4"><Skeleton className="h-24" /><Skeleton className="h-96" /></div>;

  const updated = (next: DoctorAdmin, message: string) => {
    setSaved(next);
    toast.success(message);
    refreshPublicContent(getAccessToken());
  };

  async function changeStatus(status: DoctorStatus) {
    try {
      updated(await api<DoctorAdmin>(`/doctors/${id}/status`, { method: "PATCH", body: { status } }), "Status updated");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not change status");
      throw err;
    }
  }

  async function remove() {
    try {
      await api(`/doctors/${id}`, { method: "DELETE" });
      toast.success(`${d!.full_name} removed`);
      refreshPublicContent(getAccessToken());
      router.replace("/admin/doctors");
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not remove");
      throw err;
    }
  }

  const manage = can("doctors.manage");
  return (
    <div className="mx-auto grid max-w-5xl gap-6">
      <Link href="/admin/doctors" className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" aria-hidden /> Doctors</Link>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
        <DoctorAvatar doctor={d} className="size-20 shrink-0 rounded-2xl text-2xl" />
        <div className="flex-1">
          <h1 className="font-heading text-2xl font-bold">{d.full_name}</h1>
          <p className="text-muted-foreground">{d.specialization} · {d.doctor_code}</p>
          <div className="mt-1 flex flex-wrap items-center gap-2">
            <DoctorStatusBadge status={d.status} />
            {!d.login_active && <span className="text-xs text-muted-foreground">Login disabled</span>}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {d.status !== "inactive" && (
            <a href={`/doctors/${d.id}`} target="_blank" rel="noreferrer" className={buttonVariants({ variant: "outline" })}>
              Public profile <ExternalLink aria-hidden />
            </a>
          )}
          {manage && (
            <NativeSelect aria-label="Change status" value={d.status} className="w-36"
                          onChange={(e) => {
                            const next = e.target.value as DoctorStatus;
                            if (next === "inactive") setConfirm("inactive");
                            else changeStatus(next).catch(() => {});
                          }}>
              <option value="active">Active</option><option value="on_leave">On leave</option><option value="inactive">Inactive</option>
            </NativeSelect>
          )}
        </div>
      </div>

      <Tabs defaultValue="overview" className="gap-4">
        <TabsList>
          <TabsTrigger value="overview">Overview</TabsTrigger>
          {manage && <TabsTrigger value="profile">Edit profile</TabsTrigger>}
          {can("appointments.read") && <TabsTrigger value="appointments">Appointments</TabsTrigger>}
          {can("ipd.read") && <TabsTrigger value="ipd">In-patient cases</TabsTrigger>}
        </TabsList>
        <TabsContent value="overview"><Overview doctor={d} onFeesSaved={manage ? (next) => updated(next, "Fees saved") : undefined} /></TabsContent>
        {manage && (
          <TabsContent value="profile" keepMounted>
            <DoctorForm key={d.id} doctor={d} onSaved={(next) => updated(next, "Profile saved")} />
            <Card className="mt-6 border-destructive/30">
              <CardHeader>
                <CardTitle className="text-destructive">Remove doctor</CardTitle>
                <CardDescription>Disables their login and withdraws open slots. Past appointments and records are kept. Not possible while bookings are upcoming.</CardDescription>
              </CardHeader>
              <CardContent>
                <Button variant="destructive" onClick={() => setConfirm("delete")}><Trash2 aria-hidden /> Remove {d.full_name}</Button>
              </CardContent>
            </Card>
          </TabsContent>
        )}
        <TabsContent value="appointments"><History doctorId={d.id} /></TabsContent>
        {can("ipd.read") && <TabsContent value="ipd"><DoctorIpd doctorId={d.id} /></TabsContent>}
      </Tabs>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(o) => !o && setConfirm(null)}
        title={confirm === "delete" ? `Remove ${d.full_name}?` : `Deactivate ${d.full_name}?`}
        description={confirm === "delete"
          ? "They'll disappear from the website and can't sign in. This can't be undone from the app."
          : "Their login is disabled and their open slots are withdrawn. You can reactivate them later."}
        confirmLabel={confirm === "delete" ? "Remove" : "Deactivate"}
        destructive
        onConfirm={() => (confirm === "delete" ? remove() : changeStatus("inactive"))}
      />
    </div>
  );
}

function Overview({ doctor, onFeesSaved }: { doctor: DoctorAdmin; onFeesSaved?: (d: DoctorAdmin) => void }) {
  const { can } = useAuth();
  const [editingFees, setEditingFees] = useState(false);
  const stats = useApi<DoctorStats>(can("reports.view") || can("doctors.manage") ? `/doctors/${doctor.id}/stats` : null, { days: 30 });
  const s = stats.data;
  const tiles = s ? [
    { icon: CalendarCheck, label: "Appointments (30 days)", value: s.total },
    { icon: Users, label: "Unique patients", value: s.unique_patients },
    { icon: Percent, label: "Attendance rate", value: s.completion_rate === null ? "—" : `${Math.round(s.completion_rate * 100)}%`, hint: "Completed vs no-show" },
    { icon: CalendarClock, label: "Upcoming", value: s.upcoming, hint: `${s.open_slots_next_30_days} open slots next 30 days` },
  ] : [];
  return (
    <div className="grid gap-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {!s ? Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-24 rounded-xl" />) : tiles.map((t) => (
          <Card key={t.label} className="gap-1 py-4">
            <CardContent className="grid gap-1 px-4">
              <p className="flex items-center gap-2 text-sm text-muted-foreground"><t.icon className="size-4 text-primary" aria-hidden /> {t.label}</p>
              <p className="font-heading text-2xl font-bold tabular-nums">{t.value}</p>
              {t.hint && <p className="text-xs text-muted-foreground">{t.hint}</p>}
            </CardContent>
          </Card>
        ))}
      </div>
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Details</CardTitle>
          {onFeesSaved && <Button variant="outline" size="sm" onClick={() => setEditingFees(true)}><Pencil aria-hidden /> Edit fee &amp; advance</Button>}
        </CardHeader>
        <CardContent>
          <dl className="grid gap-4 text-sm sm:grid-cols-2">
            {[
              ["Qualifications", doctor.qualification],
              ["Registration no.", doctor.registration_number],
              ["Experience", `${doctor.experience_years} years`],
              ["Consultation fee", `₹${Number(doctor.consultation_fee).toLocaleString("en-IN")}`],
              ["Advance payment", doctor.advance_amount == null ? `₹${Number(doctor.default_advance).toLocaleString("en-IN")} (hospital default)` : `₹${Number(doctor.advance_amount).toLocaleString("en-IN")}`],
              ["Departments", doctor.departments.map((x) => x.name + (x.is_primary ? " (primary)" : "")).join(", ")],
              ["Languages", doctor.languages || "—"],
              ["Email", doctor.email || "—"],
              ["Mobile", doctor.mobile || "—"],
            ].map(([k, v]) => (
              <div key={k}><dt className="text-muted-foreground">{k}</dt><dd className="font-medium">{v}</dd></div>
            ))}
          </dl>
        </CardContent>
      </Card>
      {editingFees && onFeesSaved && <FeesDialog doctor={doctor} onClose={() => setEditingFees(false)} onSaved={(next) => { setEditingFees(false); onFeesSaved(next); }} />}
    </div>
  );
}

/** Change the consultation fee and the advance paid online without opening the whole profile form. */
function FeesDialog({ doctor, onClose, onSaved }: { doctor: DoctorAdmin; onClose: () => void; onSaved: (d: DoctorAdmin) => void }) {
  const [fee, setFee] = useState(String(Number(doctor.consultation_fee)));
  const [advance, setAdvance] = useState(doctor.advance_amount == null ? "" : String(Number(doctor.advance_amount)));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function save() {
    setBusy(true);
    setError("");
    try {
      onSaved(await api<DoctorAdmin>(`/doctors/${doctor.id}/fees`, { method: "PATCH", body: { consultation_fee: Number(fee), advance_amount: advance.trim() === "" ? null : Number(advance) } }));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save");
      setBusy(false);
    }
  }
  const bad = fee.trim() === "" || Number(fee) < 0 || (advance.trim() !== "" && (Number(advance) < 0 || Number(advance) > Number(fee)));

  return (
    <Dialog open onOpenChange={(o) => !busy && !o && onClose()}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader><DialogTitle>Fee and advance</DialogTitle><DialogDescription>What patients pay for a visit with {doctor.full_name}.</DialogDescription></DialogHeader>
        <div className="grid gap-4">
          <div className="grid gap-1.5"><Label htmlFor="fe-fee">Consultation fee (₹)</Label>
            <Input id="fe-fee" type="number" min={0} step="50" value={fee} onChange={(e) => setFee(e.target.value)} autoFocus />
            <p className="text-xs text-muted-foreground">Default for new slots.</p></div>
          <div className="grid gap-1.5"><Label htmlFor="fe-adv">Advance payment (₹)</Label>
            <Input id="fe-adv" type="number" min={0} step="50" placeholder={String(doctor.default_advance)} value={advance} onChange={(e) => setAdvance(e.target.value)} />
            <p className="text-xs text-muted-foreground">Paid online to confirm a booking. Leave blank for the hospital default (₹{doctor.default_advance}); never more than the fee.</p></div>
          {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button onClick={save} disabled={busy || bad}>{busy && <Loader2 className="animate-spin" aria-hidden />} Save</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function History({ doctorId }: { doctorId: number }) {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const list = useApi<Page<AppointmentRow>>(`/doctors/${doctorId}/appointments`, { page, page_size: 20, status: status || undefined });
  return (
    <Card className="gap-0 p-0">
      <div className="flex justify-end border-b p-3">
        <NativeSelect aria-label="Filter by status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="w-44">
          <option value="">All statuses</option>
          {["confirmed", "checked_in", "completed", "cancelled", "no_show"].map((s) => <option key={s} value={s}>{s.replace("_", " ")}</option>)}
        </NativeSelect>
      </div>
      {!list.data ? <div className="p-4"><Skeleton className="h-40" /></div> : (
        <>
          <AppointmentsTable rows={list.data.items} show="patient" />
          {list.data.total > 20 && <Pagination page={page} total={list.data.total} pageSize={20} onPage={setPage} noun="appointment" />}
        </>
      )}
    </Card>
  );
}
