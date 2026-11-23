"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import {
  Activity, AlertTriangle, ArrowLeft, Download, FileText, History, Loader2, Lock, NotebookPen, Pencil, Plus, Upload,
} from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useRef, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { PatientIpd } from "@/app/admin/ipd/patient-ipd";
import { PatientLab } from "@/app/admin/lab/lab-page";
import { PatientOpd } from "@/app/admin/opd/patient-opd";
import { PatientForm } from "@/app/admin/patients/patient-form";
import { AppointmentsTable } from "@/components/shared/appointments-table";
import { EmptyState } from "@/components/shared/empty-state";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Pagination } from "@/components/shared/pagination";
import { StatusBadge } from "@/components/shared/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { downloadFile } from "@/lib/download";
import { formatDateTime } from "@/lib/format";
import { applyApiError, inr } from "@/lib/forms";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import type { AppointmentRow, BillRow, MedicalRecord, Patient, RecordType, RecordVisibility } from "@/types/management";

export function PatientDetailPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("patients.read")}>
      <PatientDetail />
    </RequireAuth>
  );
}

function ageOf(dob: string): number {
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kolkata" }).format(new Date());
  const [y, m, d] = dob.split("-").map(Number);
  const [ty, tm, td] = today.split("-").map(Number);
  return ty - y - (tm < m || (tm === m && td < d) ? 1 : 0);
}

function PatientDetail() {
  const { id } = useParams<{ id: string }>();
  const { can } = useAuth();
  const loaded = useApi<Patient>(`/patients/${id}`);
  const [saved, setSaved] = useState<Patient | null>(null);
  const [editing, setEditing] = useState(false);
  const p = saved?.id === Number(id) ? saved : loaded.data;

  if (loaded.error) return <p role="alert" className="mx-auto max-w-5xl text-sm text-destructive">{loaded.error.status === 404 ? "Patient not found, or not one of your patients." : loaded.error.message}</p>;
  if (!p) return <div className="mx-auto grid max-w-5xl gap-4"><Skeleton className="h-20" /><Skeleton className="h-96" /></div>;

  const clinical = can("medical_records.read");
  async function sendLink() {
    try { await api(`/patients/${p!.id}/password-link`, { method: "POST" }); toast.success("Password link texted"); }
    catch (err) { toast.error(err instanceof ApiError ? err.message : "Could not send the link"); }
  }
  return (
    <div className="mx-auto grid max-w-5xl gap-6">
      <Link href="/admin/patients" className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" aria-hidden /> Patients</Link>
      <div className="flex flex-col gap-1">
        <h1 className="font-heading text-2xl font-bold">{p.full_name}</h1>
        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
          <span className="font-mono font-semibold text-primary">{p.patient_code}</span>
          <span className="capitalize">{p.date_of_birth ? `${ageOf(p.date_of_birth)} yrs` : "Age not recorded"}{p.gender ? ` · ${p.gender}` : ""}</span>
          {p.blood_group && <span>Blood group {p.blood_group}</span>}
          <span>{p.mobile}</span>
          <span className={cn("rounded-full px-2 py-0.5 text-xs", p.has_login ? "bg-success/12 text-success" : "bg-muted")}>{p.has_login ? "Has online account" : "No online account"}</span>
        </p>
      </div>

      {(p.details_pending || p.password_pending) && can("patients.update") && (
        <div role="status" className="flex flex-col gap-3 rounded-xl border border-warning/40 bg-warning/10 p-4 text-sm sm:flex-row sm:items-center sm:justify-between">
          <p>
            {p.details_pending
              ? <><b>Booked online — details pending.</b> Fill in the date of birth, gender and address under Personal. When you save, the patient is texted a link to set their own password.</>
              : <><b>No password set yet.</b> The patient can be texted a link to choose one.</>}
          </p>
          <span className="flex shrink-0 gap-2">
            {p.details_pending && <Button size="sm" onClick={() => setEditing(true)}><Pencil aria-hidden /> Fill in details</Button>}
            {p.has_login && p.password_pending && <Button size="sm" variant="outline" onClick={sendLink}>Text password link</Button>}
          </span>
        </div>
      )}

      <Tabs defaultValue="personal" className="gap-4">
        <div className="-mx-1 overflow-x-auto px-1">
          <TabsList>
            <TabsTrigger value="personal">Personal</TabsTrigger>
            {(can("appointments.read") || can("appointments.read_own")) && <TabsTrigger value="appointments">Appointments</TabsTrigger>}
            {can("billing.read") && <TabsTrigger value="billing">Billing</TabsTrigger>}
            {can("opd.read") && <TabsTrigger value="opd">OPD visits</TabsTrigger>}
            {can("ipd.read") && <TabsTrigger value="ipd">In-patient</TabsTrigger>}
            {can("lab.read") && <TabsTrigger value="lab">Lab tests</TabsTrigger>}
            {clinical && <TabsTrigger value="medical">Medical</TabsTrigger>}
          </TabsList>
        </div>
        <TabsContent value="personal">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Personal information</CardTitle>
              {can("patients.update") && !editing && <Button variant="outline" size="sm" onClick={() => setEditing(true)}><Pencil aria-hidden /> Edit</Button>}
            </CardHeader>
            <CardContent>
              {editing ? (
                <PatientForm patient={p} onCancel={() => setEditing(false)} onSaved={(next) => { setSaved(next); setEditing(false); toast.success((next as Patient & { password_link_sent?: boolean }).password_link_sent ? "Details saved. A password link was texted to the patient." : "Patient details saved"); }} />
              ) : (
                <dl className="grid gap-4 text-sm sm:grid-cols-2">
                  {[
                    ["Date of birth", p.date_of_birth ? formatDay(p.date_of_birth, { day: "numeric", month: "long", year: "numeric" }) : "Not provided yet"],
                    ["Email", p.email || "—"],
                    ["Address", [p.address, p.city, p.state, p.pincode].filter(Boolean).join(", ") || "—"],
                    ["Emergency contact", [p.emergency_contact_name, p.emergency_contact_phone].filter(Boolean).join(" · ") || "—"],
                    ["Registered", formatDateTime(p.created_at)],
                    ["Data-processing consent", p.consent_at ? formatDateTime(p.consent_at) : "Not recorded"],
                  ].map(([k, v]) => (
                    <div key={k}><dt className="text-muted-foreground">{k}</dt><dd className="font-medium">{v}</dd></div>
                  ))}
                </dl>
              )}
            </CardContent>
          </Card>
        </TabsContent>
        {can("opd.read") && <TabsContent value="opd"><PatientOpd patientId={p.id} /></TabsContent>}
        <TabsContent value="ipd"><PatientIpd patientId={p.id} /></TabsContent>
        {can("lab.read") && <TabsContent value="lab"><PatientLab patientId={p.id} patientName={p.full_name} /></TabsContent>}
        <TabsContent value="appointments"><Appointments patientId={p.id} /></TabsContent>
        <TabsContent value="billing"><Bills patientId={p.id} /></TabsContent>
        {clinical && <TabsContent value="medical"><MedicalRecords patientId={p.id} /></TabsContent>}
      </Tabs>
    </div>
  );
}

function Appointments({ patientId }: { patientId: number }) {
  const [page, setPage] = useState(1);
  const list = useApi<Page<AppointmentRow>>(`/patients/${patientId}/appointments`, { page, page_size: 20 });
  return (
    <Card className="gap-0 p-0">
      {!list.data ? <div className="p-4"><Skeleton className="h-40" /></div> : (
        <>
          <AppointmentsTable rows={list.data.items} show="doctor" />
          {list.data.total > 20 && <Pagination page={page} total={list.data.total} pageSize={20} onPage={setPage} noun="appointment" />}
        </>
      )}
    </Card>
  );
}

function Bills({ patientId }: { patientId: number }) {
  const bills = useApi<BillRow[]>(`/patients/${patientId}/bills`);
  const outstanding = (bills.data ?? []).filter((b) => b.status === "pending" || b.status === "partially_paid").reduce((s, b) => s + Number(b.balance), 0);
  return (
    <Card className="gap-0 p-0">
      {!bills.data ? <div className="p-4"><Skeleton className="h-40" /></div> : bills.data.length === 0 ? (
        <EmptyState icon={FileText} title="No bills yet" />
      ) : (
        <>
          <p className="border-b px-4 py-3 text-sm">Outstanding: <span className="font-semibold">{inr.format(outstanding)}</span></p>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Bill</TableHead><TableHead>Total</TableHead><TableHead className="hidden sm:table-cell">Paid</TableHead>
                <TableHead className="hidden sm:table-cell">Balance</TableHead><TableHead className="pr-4">Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {bills.data.map((b) => (
                <TableRow key={b.id}>
                  <TableCell className="pl-4"><span className="block font-medium">{b.bill_number}</span><span className="text-xs text-muted-foreground">{formatDay(b.bill_date, { day: "numeric", month: "short", year: "numeric" })}</span></TableCell>
                  <TableCell className="tabular-nums">{inr.format(Number(b.total_amount))}</TableCell>
                  <TableCell className="hidden tabular-nums sm:table-cell">{inr.format(Number(b.paid_amount))}</TableCell>
                  <TableCell className="hidden tabular-nums sm:table-cell">{inr.format(Number(b.balance))}</TableCell>
                  <TableCell className="pr-4"><StatusBadge status={b.status} /></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </>
      )}
    </Card>
  );
}

const TYPE_META: Record<RecordType, { label: string; icon: typeof FileText }> = {
  note: { label: "Clinical note", icon: NotebookPen },
  history: { label: "Medical history", icon: History },
  allergy: { label: "Allergy", icon: AlertTriangle },
  observation: { label: "Observation", icon: Activity },
  document: { label: "Document", icon: FileText },
};
const VISIBILITY: Record<RecordVisibility, string> = {
  clinical: "Clinical team",
  doctor_only: "Doctors only",
  patient_visible: "Clinical team & patient",
};

function MedicalRecords({ patientId }: { patientId: number }) {
  const { can, user } = useAuth();
  const records = useApi<MedicalRecord[]>(`/patients/${patientId}/medical-records`);
  const [adding, setAdding] = useState(false);
  const [uploading, setUploading] = useState(false);
  const canWrite = can("medical_records.create") || can("observations.create");

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground"><Lock className="size-3.5" aria-hidden /> Viewing these records is logged.</p>
        <div className="flex gap-2">
          {can("medical_records.create") && <Button variant="outline" onClick={() => setUploading(true)}><Upload aria-hidden /> Upload document</Button>}
          {canWrite && <Button onClick={() => setAdding(true)}><Plus aria-hidden /> Add record</Button>}
        </div>
      </div>
      {!records.data ? <Skeleton className="h-48" /> : records.data.length === 0 ? (
        <Card><EmptyState icon={NotebookPen} title="No medical records yet" /></Card>
      ) : (
        <ol className="grid gap-3">
          {records.data.map((r) => {
            const Meta = TYPE_META[r.record_type];
            return (
              <li key={r.id}>
                <Card className={cn("gap-2 p-4", r.record_type === "allergy" && "border-destructive/40")}>
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <p className="flex items-center gap-2 font-medium">
                      <Meta.icon className={cn("size-4", r.record_type === "allergy" ? "text-destructive" : "text-primary")} aria-hidden />
                      {r.title}
                    </p>
                    <p className="text-xs text-muted-foreground">{Meta.label} · {formatDateTime(r.created_at)}{r.recorded_by && ` · ${r.recorded_by}`}</p>
                  </div>
                  {r.content && <p className="text-sm whitespace-pre-line">{r.content}</p>}
                  {r.data && (
                    <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                      {Object.entries(r.data).map(([k, v]) => <div key={k} className="flex gap-1.5"><dt className="text-muted-foreground uppercase">{k}</dt><dd className="font-medium">{String(v)}</dd></div>)}
                    </dl>
                  )}
                  {r.file_name && (
                    <Button variant="outline" size="sm" className="w-fit" onClick={() => downloadFile(`/medical-records/${r.id}/file`, r.file_name!).catch((err) => toast.error(err.message))}>
                      <Download aria-hidden /> {r.file_name} <span className="text-muted-foreground">({Math.ceil((r.file_size ?? 0) / 1024)} KB)</span>
                    </Button>
                  )}
                  <p className="text-xs text-muted-foreground">Visible to: {VISIBILITY[r.visibility]}</p>
                </Card>
              </li>
            );
          })}
        </ol>
      )}
      <Dialog open={adding} onOpenChange={setAdding}>
        <DialogContent className="sm:max-w-lg">
          {adding && <RecordForm patientId={patientId} isDoctor={!!user?.roles.includes("doctor")} notesAllowed={can("medical_records.create")}
                                 onDone={() => setAdding(false)} onSaved={records.reload} />}
        </DialogContent>
      </Dialog>
      <Dialog open={uploading} onOpenChange={setUploading}>
        <DialogContent className="sm:max-w-lg">
          {uploading && <UploadForm patientId={patientId} isDoctor={!!user?.roles.includes("doctor")} onDone={() => setUploading(false)} onSaved={records.reload} />}
        </DialogContent>
      </Dialog>
    </div>
  );
}

const recordSchema = z.object({
  record_type: z.enum(["note", "history", "allergy", "observation"]),
  title: z.string().trim().min(2, "Add a title").max(200),
  content: z.string().trim().max(10000),
  bp: z.string().trim().max(20), pulse: z.string().trim().max(10), temp: z.string().trim().max(10),
  spo2: z.string().trim().max(10), weight: z.string().trim().max(10),
  visibility: z.enum(["clinical", "doctor_only", "patient_visible"]),
}).superRefine((v, ctx) => {
  if (v.record_type === "observation" && ![v.bp, v.pulse, v.temp, v.spo2, v.weight].some(Boolean)) ctx.addIssue({ code: "custom", path: ["bp"], message: "Enter at least one measurement" });
  if (v.record_type !== "observation" && !v.content) ctx.addIssue({ code: "custom", path: ["content"], message: "Write the details" });
});
type RecordValues = z.infer<typeof recordSchema>;

function RecordForm({ patientId, isDoctor, notesAllowed, onDone, onSaved }: { patientId: number; isDoctor: boolean; notesAllowed: boolean; onDone: () => void; onSaved: () => void }) {
  const { register, control, handleSubmit, setError, formState: { errors: e, isSubmitting } } = useForm<RecordValues>({
    resolver: zodResolver(recordSchema),
    defaultValues: { record_type: notesAllowed ? "note" : "observation", title: notesAllowed ? "" : "Vitals", content: "", bp: "", pulse: "", temp: "", spo2: "", weight: "", visibility: "clinical" },
  });
  const type = useWatch({ control, name: "record_type" });

  async function onSubmit(v: RecordValues) {
    const data = Object.fromEntries(([["bp", v.bp], ["pulse", v.pulse], ["temp", v.temp], ["spo2", v.spo2], ["weight", v.weight]] as const).filter(([, x]) => x));
    const body = type === "observation"
      ? { record_type: type, title: v.title, data, visibility: v.visibility }
      : { record_type: type, title: v.title, content: v.content, visibility: v.visibility };
    try {
      await api(`/patients/${patientId}/medical-records`, { method: "POST", body });
      toast.success("Record added");
      onSaved();
      onDone();
    } catch (err) {
      applyApiError(err, setError, ["title", "content", "visibility"]);
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4">
      <DialogHeader><DialogTitle>Add medical record</DialogTitle><DialogDescription>Recorded under your name with the current time.</DialogDescription></DialogHeader>
      {e.root && <p role="alert" className="text-sm text-destructive">{e.root.message}</p>}
      <FormField id="r-type" label="Type">
        <NativeSelect id="r-type" {...register("record_type")}>
          {notesAllowed && <><option value="note">Clinical note</option><option value="history">Medical history</option><option value="allergy">Allergy</option></>}
          <option value="observation">Observation (vitals)</option>
        </NativeSelect>
      </FormField>
      <FormField id="r-title" label="Title" error={e.title?.message}>
        <Input {...fieldAria("r-title", e.title?.message)} placeholder={type === "allergy" ? "e.g. Penicillin" : undefined} {...register("title")} />
      </FormField>
      {type === "observation" ? (
        <fieldset className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <legend className="sr-only">Measurements</legend>
          {([["bp", "BP (mmHg)", "120/80"], ["pulse", "Pulse (bpm)", "72"], ["temp", "Temp (°F)", "98.6"], ["spo2", "SpO₂ (%)", "98"], ["weight", "Weight (kg)", "64"]] as const).map(([k, label, ph]) => (
            <FormField key={k} id={`r-${k}`} label={label} error={k === "bp" ? e.bp?.message : undefined}>
              <Input {...fieldAria(`r-${k}`, k === "bp" ? e.bp?.message : undefined)} placeholder={ph} inputMode="decimal" {...register(k)} />
            </FormField>
          ))}
        </fieldset>
      ) : (
        <FormField id="r-content" label="Details" error={e.content?.message}>
          <Textarea {...fieldAria("r-content", e.content?.message)} rows={5} {...register("content")} />
        </FormField>
      )}
      <FormField id="r-vis" label="Who can see this">
        <NativeSelect id="r-vis" {...register("visibility")}>
          <option value="clinical">Clinical team</option>
          <option value="patient_visible">Clinical team & patient</option>
          {isDoctor && <option value="doctor_only">Doctors only</option>}
        </NativeSelect>
      </FormField>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onDone}>Cancel</Button>
        <Button type="submit" disabled={isSubmitting}>{isSubmitting && <Loader2 className="animate-spin" aria-hidden />} Save record</Button>
      </DialogFooter>
    </form>
  );
}

function UploadForm({ patientId, isDoctor, onDone, onSaved }: { patientId: number; isDoctor: boolean; onDone: () => void; onSaved: () => void }) {
  const file = useRef<HTMLInputElement>(null);
  const [title, setTitle] = useState("");
  const [visibility, setVisibility] = useState<RecordVisibility>("clinical");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(ev: React.FormEvent) {
    ev.preventDefault();
    const f = file.current?.files?.[0];
    if (!f) return setError("Choose a file");
    if (title.trim().length < 2) return setError("Give the document a title");
    if (f.size > 10 * 1024 * 1024) return setError("Documents must be 10 MB or smaller");
    setBusy(true);
    setError(null);
    try {
      const form = new FormData();
      form.append("file", f);
      form.append("title", title.trim());
      form.append("visibility", visibility);
      await api(`/patients/${patientId}/documents`, { method: "POST", body: form });
      toast.success("Document uploaded");
      onSaved();
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Upload failed");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <DialogHeader><DialogTitle>Upload document</DialogTitle><DialogDescription>Lab reports, scans, discharge summaries. PDF, PNG, JPEG or WebP up to 10 MB. Stored privately; never public.</DialogDescription></DialogHeader>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <FormField id="u-file" label="File"><Input ref={file} id="u-file" type="file" accept="application/pdf,image/png,image/jpeg,image/webp" /></FormField>
      <FormField id="u-title" label="Title"><Input id="u-title" value={title} onChange={(ev) => setTitle(ev.target.value)} placeholder="e.g. CBC report, 12 Nov" /></FormField>
      <FormField id="u-vis" label="Who can see this">
        <NativeSelect id="u-vis" value={visibility} onChange={(ev) => setVisibility(ev.target.value as RecordVisibility)}>
          <option value="clinical">Clinical team</option><option value="patient_visible">Clinical team & patient</option>
          {isDoctor && <option value="doctor_only">Doctors only</option>}
        </NativeSelect>
      </FormField>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onDone}>Cancel</Button>
        <Button type="submit" disabled={busy}>{busy ? <Loader2 className="animate-spin" aria-hidden /> : <Upload aria-hidden />} Upload</Button>
      </DialogFooter>
    </form>
  );
}
