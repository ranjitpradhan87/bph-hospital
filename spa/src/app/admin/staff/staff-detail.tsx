"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowLeft, CalendarClock, Loader2, Plus, Trash2 } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { DoctorStatusBadge, EMPLOYMENT_LABELS } from "@/app/admin/doctors/doctor-status";
import { ATTENDANCE_LABELS } from "@/app/admin/attendance/attendance-labels";
import { StaffForm } from "@/app/admin/staff/staff-form";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { roleLabel } from "@/lib/format";
import { applyApiError } from "@/lib/forms";
import { formatDay, formatTime } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import type { AttendanceRow, EmploymentStatus, Shift, StaffMember } from "@/types/management";

const todayIST = () => new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kolkata" }).format(new Date());

export function StaffDetailPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.some((p) => p === "staff.read" || p === "staff.manage")}>
      <StaffDetail />
    </RequireAuth>
  );
}

function StaffDetail() {
  const { id } = useParams<{ id: string }>();
  const { can, user } = useAuth();
  const member = useApi<StaffMember>(`/staff/${id}`);
  const [saved, setSaved] = useState<StaffMember | null>(null);
  const [pending, setPending] = useState<EmploymentStatus | null>(null);
  const s = saved?.id === Number(id) ? saved : member.data;

  if (member.error) return <p role="alert" className="mx-auto max-w-5xl text-sm text-destructive">{member.error.status === 404 ? "This staff member doesn't exist." : member.error.message}</p>;
  if (!s) return <div className="mx-auto grid max-w-5xl gap-4"><Skeleton className="h-20" /><Skeleton className="h-96" /></div>;

  const manage = can("staff.manage");
  const self = s.user_id === user?.id;

  async function setEmployment(status: EmploymentStatus) {
    try {
      setSaved(await api<StaffMember>(`/staff/${id}/employment`, { method: "PATCH", body: { employment_status: status } }));
      toast.success(status === "active" ? "Reactivated — they can sign in again" : `Marked ${EMPLOYMENT_LABELS[status].toLowerCase()}; login disabled`);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update");
      throw err;
    }
  }

  return (
    <div className="mx-auto grid max-w-5xl gap-6">
      <Link href="/admin/staff" className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" aria-hidden /> Staff</Link>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="font-heading text-2xl font-bold">{s.full_name}</h1>
          <p className="text-muted-foreground">{s.designation}{s.department_name && ` · ${s.department_name}`} · {s.staff_code}</p>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <DoctorStatusBadge status={s.employment_status} /> {s.roles.map(roleLabel).join(", ")}
          </div>
        </div>
        {manage && !self && (
          <NativeSelect aria-label="Employment status" value={s.employment_status} className="w-40"
                        onChange={(e) => {
                          const next = e.target.value as EmploymentStatus;
                          if (next === "active") setEmployment(next).catch(() => {});
                          else setPending(next);
                        }}>
            {Object.entries(EMPLOYMENT_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </NativeSelect>
        )}
      </div>

      <Tabs defaultValue={manage ? "details" : "shifts"} className="gap-4">
        <TabsList>
          {manage && <TabsTrigger value="details">Details</TabsTrigger>}
          <TabsTrigger value="shifts">Shifts</TabsTrigger>
          <TabsTrigger value="attendance">Attendance</TabsTrigger>
        </TabsList>
        {manage && (
          <TabsContent value="details" keepMounted>
            <StaffForm key={s.id} staff={s} onSaved={(next) => { setSaved(next); toast.success("Saved"); }} />
          </TabsContent>
        )}
        <TabsContent value="shifts"><Shifts staffId={s.id} canEdit={manage} /></TabsContent>
        <TabsContent value="attendance"><MonthAttendance staffId={s.id} /></TabsContent>
      </Tabs>

      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(o) => !o && setPending(null)}
        title={`Mark ${s.full_name} as ${pending ? EMPLOYMENT_LABELS[pending].toLowerCase() : ""}?`}
        description="Their login will be disabled and they'll be signed out everywhere. Records are kept."
        confirmLabel="Confirm"
        destructive
        onConfirm={() => setEmployment(pending!)}
      />
    </div>
  );
}

const shiftSchema = z.object({
  shift_date: z.string().min(1, "Choose a date"),
  start_time: z.string().min(1, "Start time"),
  end_time: z.string().min(1, "End time"),
  shift_type: z.enum(["general", "morning", "evening", "night"]),
}).refine((v) => v.start_time !== v.end_time, { path: ["end_time"], message: "Must differ from the start" });
type ShiftValues = z.infer<typeof shiftSchema>;

function Shifts({ staffId, canEdit }: { staffId: number; canEdit: boolean }) {
  const from = todayIST();
  const list = useApi<Shift[]>(`/staff/${staffId}/shifts`, { from });
  const [deleting, setDeleting] = useState<Shift | null>(null);
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<ShiftValues>({
    resolver: zodResolver(shiftSchema),
    defaultValues: { shift_date: from, start_time: "09:00", end_time: "17:00", shift_type: "general" },
  });

  async function add(v: ShiftValues) {
    try {
      await api(`/staff/${staffId}/shifts`, { method: "POST", body: v });
      toast.success("Shift added");
      reset({ ...v });
      list.reload();
    } catch (err) {
      applyApiError(err, setError, ["shift_date", "start_time", "end_time", "shift_type"]);
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
      <Card className="gap-0">
        <CardHeader className="border-b pb-4"><CardTitle>Next two weeks</CardTitle></CardHeader>
        <CardContent className="p-0">
          {!list.data ? <div className="p-4"><Skeleton className="h-32" /></div> : list.data.length === 0 ? (
            <EmptyState icon={CalendarClock} title="No shifts scheduled" />
          ) : (
            <ul className="divide-y">
              {list.data.map((sh) => (
                <li key={sh.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <span>
                    <span className="block font-medium">{formatDay(sh.shift_date, { weekday: "short", day: "numeric", month: "short" })}</span>
                    <span className="text-sm text-muted-foreground">
                      {formatTime(sh.start_time)} – {formatTime(sh.end_time)}{sh.end_time < sh.start_time && " (next day)"} · {sh.shift_type}
                    </span>
                  </span>
                  {canEdit && <Button variant="ghost" size="icon-sm" aria-label="Remove shift" onClick={() => setDeleting(sh)}><Trash2 /></Button>}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
      {canEdit && (
        <Card className="h-fit">
          <CardHeader><CardTitle>Add shift</CardTitle></CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit(add)} noValidate className="grid gap-3">
              {errors.root && <p role="alert" className="text-sm text-destructive">{errors.root.message}</p>}
              <FormField id="sh-date" label="Date" error={errors.shift_date?.message}>
                <Input {...fieldAria("sh-date", errors.shift_date?.message)} type="date" {...register("shift_date")} />
              </FormField>
              <div className="grid grid-cols-2 gap-3">
                <FormField id="sh-start" label="Starts" error={errors.start_time?.message}>
                  <Input {...fieldAria("sh-start", errors.start_time?.message)} type="time" {...register("start_time")} />
                </FormField>
                <FormField id="sh-end" label="Ends" error={errors.end_time?.message} hint="Earlier = overnight">
                  <Input {...fieldAria("sh-end", errors.end_time?.message, "hint")} type="time" {...register("end_time")} />
                </FormField>
              </div>
              <FormField id="sh-type" label="Type">
                <NativeSelect id="sh-type" {...register("shift_type")}>
                  <option value="general">General</option><option value="morning">Morning</option><option value="evening">Evening</option><option value="night">Night</option>
                </NativeSelect>
              </FormField>
              <Button type="submit" disabled={isSubmitting}>{isSubmitting ? <Loader2 className="animate-spin" aria-hidden /> : <Plus aria-hidden />} Add shift</Button>
            </form>
          </CardContent>
        </Card>
      )}
      <ConfirmDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)} title="Remove this shift?" description="This can't be undone."
        confirmLabel="Remove" destructive onConfirm={async () => {
          try {
            await api(`/staff/shifts/${deleting!.id}`, { method: "DELETE" });
            list.reload();
          } catch (err) {
            toast.error(err instanceof ApiError ? err.message : "Could not remove");
            throw err;
          }
        }} />
    </div>
  );
}

function MonthAttendance({ staffId }: { staffId: number }) {
  const [month, setMonth] = useState(todayIST().slice(0, 7));
  const rows = useApi<AttendanceRow[]>(`/staff/${staffId}/attendance`, { month });
  const counts = (rows.data ?? []).reduce<Record<string, number>>((acc, r) => ({ ...acc, [r.status ?? ""]: (acc[r.status ?? ""] ?? 0) + 1 }), {});
  return (
    <Card className="gap-0">
      <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
        <CardTitle>Attendance</CardTitle>
        <Input type="month" aria-label="Month" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} className="w-44" />
      </CardHeader>
      <CardContent className="p-0">
        {!rows.data ? <div className="p-4"><Skeleton className="h-32" /></div> : rows.data.length === 0 ? (
          <EmptyState icon={CalendarClock} title="Nothing recorded this month" />
        ) : (
          <>
            <p className="flex flex-wrap gap-x-4 gap-y-1 border-b px-4 py-3 text-sm">
              {Object.entries(counts).map(([k, n]) => <span key={k}><span className="font-medium">{n}</span> {ATTENDANCE_LABELS[k as keyof typeof ATTENDANCE_LABELS]?.toLowerCase()}</span>)}
            </p>
            <ul className="divide-y text-sm">
              {rows.data.map((r) => (
                <li key={r.attendance_date} className="flex justify-between gap-3 px-4 py-2.5">
                  <span>{formatDay(r.attendance_date, { weekday: "short", day: "numeric", month: "short" })}</span>
                  <span className="text-right">
                    <span className="font-medium">{r.status ? ATTENDANCE_LABELS[r.status] : "—"}</span>
                    {r.check_in && <span className="text-muted-foreground"> · {formatTime(r.check_in)}{r.check_out && ` – ${formatTime(r.check_out)}`}</span>}
                  </span>
                </li>
              ))}
            </ul>
          </>
        )}
      </CardContent>
    </Card>
  );
}
