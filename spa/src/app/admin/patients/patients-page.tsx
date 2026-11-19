"use client";

import { Search, UserPlus, UserRound } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useDeferredValue, useState } from "react";
import { toast } from "sonner";

import { PatientForm } from "@/app/admin/patients/patient-form";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Pagination } from "@/components/shared/pagination";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page } from "@/types/api";
import type { PatientListItem } from "@/types/management";

const PAGE_SIZE = 20;

export function PatientsPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("patients.read")}>
      <PatientsView />
    </RequireAuth>
  );
}

function PatientsView() {
  const { can, user } = useAuth();
  const router = useRouter();
  const [search, setSearch] = useState("");
  const deferred = useDeferredValue(search.trim());
  const [page, setPage] = useState(1);
  const [incomplete, setIncomplete] = useState(false);
  const [registering, setRegistering] = useState(false);
  const list = useApi<Page<PatientListItem>>("/patients", { search: deferred || undefined, incomplete: incomplete ? 1 : undefined, page, page_size: PAGE_SIZE });
  const data = list.data;
  const scoped = !!user?.doctor_id && !can("patients.update");

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Patients"
        description={scoped ? "Patients who have had appointments with you." : "Search by name, patient ID, mobile number or email."}
        actions={can("patients.create") && <Button onClick={() => setRegistering(true)}><UserPlus aria-hidden /> Register patient</Button>}
      />
      <Card className="gap-0 p-0">
        <div className="border-b p-3">
          <div className="relative">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input type="search" autoFocus aria-label="Search patients" placeholder="e.g. PAT-000123, 98450…, Meera" className="h-10 pl-8"
                   value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} />
          </div>
          {!scoped && (
            <label className="mt-2 flex w-fit cursor-pointer items-center gap-2 text-sm text-muted-foreground">
              <input type="checkbox" className="size-4 accent-primary" checked={incomplete} onChange={(e) => { setIncomplete(e.target.checked); setPage(1); }} />
              Only patients whose details are pending (booked online)
            </label>
          )}
        </div>
        {list.error ? (
          <p role="alert" className="p-6 text-sm text-destructive">{list.error.message}</p>
        ) : !data ? (
          <div className="grid gap-3 p-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
        ) : data.items.length === 0 ? (
          <EmptyState icon={UserRound} title={search ? "No matching patients" : "No patients yet"}
                      description={search && can("patients.create") ? "Check the spelling, or register them as a new patient." : undefined} />
        ) : (
          <div className={cn("transition-opacity", list.loading && "opacity-60")}>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Patient</TableHead>
                  <TableHead>Mobile</TableHead>
                  <TableHead className="hidden sm:table-cell">Age / sex</TableHead>
                  <TableHead className="hidden pr-4 md:table-cell">Last visit</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.items.map((p) => (
                  <TableRow key={p.id} className="relative">
                    <TableCell className="pl-4">
                      <Link href={`/admin/patients/${p.id}`} className="font-medium after:absolute after:inset-0 hover:text-primary">{p.full_name}</Link>
                      <p className="font-mono text-xs text-muted-foreground">{p.patient_code}{p.details_pending && <span className="ml-2 rounded-full bg-warning/15 px-2 py-0.5 font-sans text-[11px] font-medium text-warning-foreground">Details pending</span>}</p>
                    </TableCell>
                    <TableCell className="tabular-nums">{p.mobile}</TableCell>
                    <TableCell className="hidden capitalize sm:table-cell">{p.age ?? "—"} · {p.gender?.[0] ?? "—"}</TableCell>
                    <TableCell className="hidden pr-4 md:table-cell">{p.last_visit ? formatDay(p.last_visit, { day: "numeric", month: "short", year: "numeric" }) : "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <Pagination page={page} total={data.total} pageSize={PAGE_SIZE} onPage={setPage} noun="patient" />
          </div>
        )}
      </Card>

      <Dialog open={registering} onOpenChange={setRegistering}>
        <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Register patient</DialogTitle>
            <DialogDescription>For walk-ins and phone bookings. If they later sign up online with the same mobile and date of birth, their account is linked to this record.</DialogDescription>
          </DialogHeader>
          {registering && (
            <PatientForm patient={null} onCancel={() => setRegistering(false)} onSaved={(p) => {
              toast.success(`Registered ${p.full_name} — patient ID ${p.patient_code}`);
              router.push(`/admin/patients/${p.id}`);
            }} />
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
