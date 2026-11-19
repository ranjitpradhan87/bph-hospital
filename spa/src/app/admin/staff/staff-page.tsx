"use client";

import { CalendarCheck2, Plus, Search, Users } from "lucide-react";
import Link from "next/link";
import { useDeferredValue, useState } from "react";

import { DoctorStatusBadge, EMPLOYMENT_LABELS } from "@/app/admin/doctors/doctor-status";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { buttonVariants } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { roleLabel } from "@/lib/format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import type { DepartmentAdmin, StaffMember } from "@/types/management";

const PAGE_SIZE = 20;

export function StaffPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.some((p) => p === "staff.read" || p === "staff.manage")}>
      <StaffView />
    </RequireAuth>
  );
}

function StaffView() {
  const { can } = useAuth();
  const [search, setSearch] = useState("");
  const deferred = useDeferredValue(search.trim());
  const [dept, setDept] = useState("");
  const [status, setStatus] = useState("active");
  const [page, setPage] = useState(1);
  const depts = useApi<DepartmentAdmin[]>("/departments");
  const list = useApi<Page<StaffMember>>("/staff", {
    search: deferred || undefined, department_id: dept || undefined, status: status || undefined, page, page_size: PAGE_SIZE,
  });
  const data = list.data;
  const reset = <T,>(fn: (v: T) => void) => (v: T) => { fn(v); setPage(1); };

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Staff"
        description="Hospital employees, their departments, logins and roles."
        actions={
          <>
            {can("attendance.manage") && <Link href="/admin/attendance" className={buttonVariants({ variant: "outline" })}><CalendarCheck2 aria-hidden /> Attendance</Link>}
            {can("staff.manage") && <Link href="/admin/staff/new" className={buttonVariants()}><Plus aria-hidden /> Add staff</Link>}
          </>
        }
      />
      <Card className="gap-0 p-0">
        <div className="flex flex-col gap-2 border-b p-3 sm:flex-row">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input type="search" aria-label="Search staff" placeholder="Name, code, designation, email or mobile" className="pl-8"
                   value={search} onChange={(e) => reset(setSearch)(e.target.value)} />
          </div>
          <div className="grid grid-cols-2 gap-2 sm:flex">
            <NativeSelect aria-label="Department" value={dept} onChange={(e) => reset(setDept)(e.target.value)} className="sm:w-48">
              <option value="">All departments</option>
              {depts.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </NativeSelect>
            <NativeSelect aria-label="Employment status" value={status} onChange={(e) => reset(setStatus)(e.target.value)} className="sm:w-40">
              <option value="">Any status</option>
              {Object.entries(EMPLOYMENT_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </NativeSelect>
          </div>
        </div>
        {list.error ? (
          <p role="alert" className="p-6 text-sm text-destructive">{list.error.message}</p>
        ) : !data ? (
          <div className="grid gap-3 p-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
        ) : data.items.length === 0 ? (
          <EmptyState icon={Users} title="No staff found" description={search || dept ? "Try other filters." : "Add the first staff member."} />
        ) : (
          <div className={cn("transition-opacity", list.loading && "opacity-60")}>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Name</TableHead>
                  <TableHead className="hidden md:table-cell">Department</TableHead>
                  <TableHead className="hidden lg:table-cell">Roles</TableHead>
                  <TableHead className="pr-4">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.items.map((s) => (
                  <TableRow key={s.id} className="relative">
                    <TableCell className="pl-4">
                      <Link href={`/admin/staff/${s.id}`} className="font-medium after:absolute after:inset-0 hover:text-primary">{s.full_name}</Link>
                      <p className="text-xs text-muted-foreground">{s.staff_code} · {s.designation}</p>
                    </TableCell>
                    <TableCell className="hidden md:table-cell">{s.department_name ?? "—"}</TableCell>
                    <TableCell className="hidden text-sm lg:table-cell">{s.roles.map(roleLabel).join(", ")}</TableCell>
                    <TableCell className="pr-4"><DoctorStatusBadge status={s.employment_status} /></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <Pagination page={page} total={data.total} pageSize={PAGE_SIZE} onPage={setPage} noun="staff member" />
          </div>
        )}
      </Card>
    </div>
  );
}
