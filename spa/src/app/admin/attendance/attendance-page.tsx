"use client";

import { CheckCheck, Loader2, Save, Users } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { toast } from "sonner";

import { ATTENDANCE_LABELS } from "@/app/admin/attendance/attendance-labels";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ApiError, api } from "@/lib/api";
import { RequireAuth } from "@/lib/auth/require-auth";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { AttendanceRow, AttendanceStatus, DepartmentAdmin } from "@/types/management";

const todayIST = () => new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kolkata" }).format(new Date());
type Edit = Pick<AttendanceRow, "status" | "check_in" | "check_out" | "remarks">;

export function AttendancePage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("attendance.manage")}>
      <AttendanceView />
    </RequireAuth>
  );
}

function AttendanceView() {
  const [day, setDay] = useState(todayIST);
  const [dept, setDept] = useState("");
  const depts = useApi<DepartmentAdmin[]>("/departments");
  const rows = useApi<AttendanceRow[]>("/attendance", { date: day, department_id: dept || undefined });
  // Unsaved edits keyed by staff id, discarded when the day/department changes.
  const [edits, setEdits] = useState<{ key: string; byStaff: Record<number, Edit> }>({ key: "", byStaff: {} });
  const key = `${day}|${dept}`;
  const pending = edits.key === key ? edits.byStaff : {};
  const [saving, setSaving] = useState(false);

  const value = (r: AttendanceRow): Edit => pending[r.staff_id] ?? { status: r.status, check_in: r.check_in, check_out: r.check_out, remarks: r.remarks };
  const patch = (r: AttendanceRow, change: Partial<Edit>) =>
    setEdits({ key, byStaff: { ...pending, [r.staff_id]: { ...value(r), ...change } } });

  function markRestPresent() {
    const next = { ...pending };
    for (const r of rows.data ?? []) if (!value(r).status) next[r.staff_id] = { ...value(r), status: "present" };
    setEdits({ key, byStaff: next });
  }

  async function save() {
    const entries = Object.entries(pending)
      .filter(([, e]) => e.status)
      .map(([id, e]) => ({ staff_id: Number(id), status: e.status, check_in: e.check_in?.slice(0, 5) || null, check_out: e.check_out?.slice(0, 5) || null, remarks: e.remarks || null }));
    if (!entries.length) return;
    setSaving(true);
    try {
      await api("/attendance", { method: "PUT", body: { attendance_date: day, entries } });
      toast.success(`Saved attendance for ${entries.length} ${entries.length === 1 ? "person" : "people"}`);
      setEdits({ key, byStaff: {} });
      rows.reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not save");
    } finally {
      setSaving(false);
    }
  }

  const changed = Object.keys(pending).length;
  const unrecorded = (rows.data ?? []).filter((r) => !value(r).status).length;

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Attendance"
        description="Record who was in each day. Times are in hospital time (IST)."
        actions={<Link href="/admin/staff" className="text-sm text-primary hover:underline">Back to staff</Link>}
      />
      <Card className="gap-0 p-0">
        <div className="flex flex-col gap-2 border-b p-3 sm:flex-row sm:items-center">
          <Input type="date" aria-label="Date" value={day} max={todayIST()} onChange={(e) => e.target.value && setDay(e.target.value)} className="sm:w-44" />
          <NativeSelect aria-label="Department" value={dept} onChange={(e) => setDept(e.target.value)} className="sm:w-52">
            <option value="">All departments</option>
            {depts.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
          </NativeSelect>
          <div className="flex-1" />
          {unrecorded > 0 && <Button variant="outline" onClick={markRestPresent}><CheckCheck aria-hidden /> Mark {unrecorded} unrecorded present</Button>}
        </div>
        {!rows.data ? (
          <div className="grid gap-3 p-4">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
        ) : rows.data.length === 0 ? (
          <EmptyState icon={Users} title="No staff for this day" description="Only people who had joined by this date are listed." />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Staff member</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="hidden md:table-cell">In</TableHead>
                <TableHead className="hidden md:table-cell">Out</TableHead>
                <TableHead className="hidden pr-4 lg:table-cell">Remarks</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.data.map((r) => {
                const v = value(r);
                const dirty = r.staff_id in pending;
                return (
                  <TableRow key={r.staff_id} className={cn(dirty && "bg-primary/5")}>
                    <TableCell className="pl-4">
                      <p className="font-medium">{r.full_name}</p>
                      <p className="text-xs text-muted-foreground">{r.designation}{r.department_name && ` · ${r.department_name}`}</p>
                    </TableCell>
                    <TableCell>
                      <NativeSelect aria-label={`Status for ${r.full_name}`} value={v.status ?? ""} className="w-32"
                                    onChange={(e) => patch(r, { status: (e.target.value || null) as AttendanceStatus | null })}>
                        <option value="">Not recorded</option>
                        {Object.entries(ATTENDANCE_LABELS).map(([s, l]) => <option key={s} value={s}>{l}</option>)}
                      </NativeSelect>
                    </TableCell>
                    <TableCell className="hidden md:table-cell">
                      <Input type="time" aria-label={`Check-in for ${r.full_name}`} className="w-28" value={v.check_in?.slice(0, 5) ?? ""}
                             onChange={(e) => patch(r, { check_in: e.target.value || null })} />
                    </TableCell>
                    <TableCell className="hidden md:table-cell">
                      <Input type="time" aria-label={`Check-out for ${r.full_name}`} className="w-28" value={v.check_out?.slice(0, 5) ?? ""}
                             onChange={(e) => patch(r, { check_out: e.target.value || null })} />
                    </TableCell>
                    <TableCell className="hidden pr-4 lg:table-cell">
                      <Input aria-label={`Remarks for ${r.full_name}`} value={v.remarks ?? ""} maxLength={255}
                             onChange={(e) => patch(r, { remarks: e.target.value })} />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        )}
      </Card>
      {changed > 0 && (
        <div className="sticky bottom-4 z-30 mt-4 flex items-center justify-between gap-3 rounded-xl border bg-background/95 p-3 shadow-lg backdrop-blur">
          <p className="text-sm text-muted-foreground">{changed} unsaved change{changed === 1 ? "" : "s"}</p>
          <div className="flex gap-2">
            <Button variant="ghost" onClick={() => setEdits({ key, byStaff: {} })} disabled={saving}>Discard</Button>
            <Button onClick={save} disabled={saving}>{saving ? <Loader2 className="animate-spin" aria-hidden /> : <Save aria-hidden />} Save attendance</Button>
          </div>
        </div>
      )}
    </div>
  );
}
