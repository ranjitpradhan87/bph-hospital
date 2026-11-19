"use client";

import { Plus, Search, Stethoscope } from "lucide-react";
import Link from "next/link";
import { useDeferredValue, useState } from "react";

import { DoctorStatusBadge } from "@/app/admin/doctors/doctor-status";
import { DoctorAvatar } from "@/components/public/doctor-card";
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
import { inr } from "@/lib/forms";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import type { DepartmentAdmin, DoctorAdmin } from "@/types/management";

const PAGE_SIZE = 20;

export function DoctorsPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.some((p) => p === "doctors.read" || p === "doctors.manage")}>
      <DoctorsView />
    </RequireAuth>
  );
}

function DoctorsView() {
  const { can } = useAuth();
  const [search, setSearch] = useState("");
  const deferred = useDeferredValue(search.trim());
  const [dept, setDept] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const depts = useApi<DepartmentAdmin[]>("/departments");
  const list = useApi<Page<DoctorAdmin>>("/doctors", {
    search: deferred || undefined, department_id: dept || undefined, status: status || undefined, page, page_size: PAGE_SIZE,
  });
  const data = list.data;
  const reset = <T,>(fn: (v: T) => void) => (v: T) => { fn(v); setPage(1); };

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Doctors"
        description="Profiles shown on the website, and the logins doctors use."
        actions={can("doctors.manage") && <Link href="/admin/doctors/new" className={buttonVariants()}><Plus aria-hidden /> Add doctor</Link>}
      />
      <Card className="gap-0 p-0">
        <div className="flex flex-col gap-2 border-b p-3 sm:flex-row">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input type="search" aria-label="Search doctors" placeholder="Name, code, speciality or registration no." className="pl-8"
                   value={search} onChange={(e) => reset(setSearch)(e.target.value)} />
          </div>
          <div className="grid grid-cols-2 gap-2 sm:flex">
            <NativeSelect aria-label="Department" value={dept} onChange={(e) => reset(setDept)(e.target.value)} className="sm:w-48">
              <option value="">All departments</option>
              {depts.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </NativeSelect>
            <NativeSelect aria-label="Status" value={status} onChange={(e) => reset(setStatus)(e.target.value)} className="sm:w-36">
              <option value="">Any status</option><option value="active">Active</option><option value="on_leave">On leave</option><option value="inactive">Inactive</option>
            </NativeSelect>
          </div>
        </div>
        {list.error ? (
          <p role="alert" className="p-6 text-sm text-destructive">{list.error.message}</p>
        ) : !data ? (
          <div className="grid gap-3 p-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-14" />)}</div>
        ) : data.items.length === 0 ? (
          <EmptyState icon={Stethoscope} title="No doctors found" description={search || dept || status ? "Try other filters." : "Add the first doctor to start taking bookings."} />
        ) : (
          <div className={cn("transition-opacity", list.loading && "opacity-60")}>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Doctor</TableHead>
                  <TableHead className="hidden lg:table-cell">Departments</TableHead>
                  <TableHead className="hidden md:table-cell">Fee</TableHead>
                  <TableHead className="hidden sm:table-cell">Upcoming</TableHead>
                  <TableHead className="pr-4">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.items.map((d) => (
                  <TableRow key={d.id} className="relative">
                    <TableCell className="pl-4">
                      <div className="flex items-center gap-3">
                        <DoctorAvatar doctor={d} className="size-10 shrink-0 rounded-full text-sm" />
                        <div className="min-w-0">
                          <Link href={`/admin/doctors/${d.id}`} className="font-medium after:absolute after:inset-0 hover:text-primary">{d.full_name}</Link>
                          <p className="truncate text-xs text-muted-foreground">{d.doctor_code} · {d.specialization}</p>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="hidden text-sm lg:table-cell">{d.departments.map((x) => x.name).join(", ")}</TableCell>
                    <TableCell className="hidden tabular-nums md:table-cell">{inr.format(Number(d.consultation_fee))}</TableCell>
                    <TableCell className="hidden tabular-nums sm:table-cell">{d.upcoming_appointments}</TableCell>
                    <TableCell className="pr-4"><DoctorStatusBadge status={d.status} /></TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <Pagination page={page} total={data.total} pageSize={PAGE_SIZE} onPage={setPage} noun="doctor" />
          </div>
        )}
      </Card>
    </div>
  );
}
