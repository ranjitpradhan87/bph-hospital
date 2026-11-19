import { BedDouble } from "lucide-react";
import Link from "next/link";

import { DoctorNames } from "@/app/admin/ipd/ipd-links";
import { EmptyState } from "@/components/shared/empty-state";
import { Skeleton } from "@/components/ui/skeleton";
import { inr } from "@/lib/forms";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import type { IpdAdmission } from "@/types/ipd";

/** A patient's in-patient stays, linked from their record back to the register. */
export function PatientIpd({ patientId }: { patientId: number }) {
  const list = useApi<{ items: IpdAdmission[] }>("/ipd/admissions", { patient_id: patientId });
  if (list.error) return <p role="alert" className="text-sm text-destructive">{list.error.message}</p>;
  if (!list.data) return <Skeleton className="h-24" />;
  if (list.data.items.length === 0) return <EmptyState icon={BedDouble} title="No in-patient stays" description="Stays recorded in the IPD register and linked to this patient appear here." />;
  return (
    <ul className="grid gap-2">
      {list.data.items.map((a) => (
        <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
          <div>
            <p className="font-medium">{formatDay(a.admission_date, { day: "numeric", month: "short", year: "numeric" })}{a.bed ? ` · Bed ${a.bed}` : ""}{a.case_title ? ` · ${a.case_title}` : ""}</p>
            <p className="text-xs text-muted-foreground"><DoctorNames list={a.doctor_list} other={a.other_doctors} />{a.doctors ? " · " : ""}{a.status === "admitted" ? "Currently admitted" : `Discharged${a.discharge_date ? ` ${formatDay(a.discharge_date, { day: "numeric", month: "short" })}` : ""}`}</p>
          </div>
          <div className="text-right">
            <p className="font-semibold tabular-nums">{inr.format(a.received)} <span className="text-xs font-normal text-muted-foreground">received</span></p>
            <Link href={`/admin/ipd?month=${a.admission_date.slice(0, 7)}`} className="text-xs text-primary hover:underline">Open in register</Link>
          </div>
        </li>
      ))}
    </ul>
  );
}

/** A doctor's in-patient cases (as treating doctor or anaesthetist), linked to the patients and the register. */
export function DoctorIpd({ doctorId }: { doctorId: number }) {
  const list = useApi<{ items: IpdAdmission[] }>("/ipd/admissions", { doctor_id: doctorId });
  if (list.error) return <p role="alert" className="text-sm text-destructive">{list.error.message}</p>;
  if (!list.data) return <Skeleton className="h-24" />;
  if (list.data.items.length === 0) return <EmptyState icon={BedDouble} title="No in-patient cases" description="Admissions where this doctor is chosen in the IPD register appear here." />;
  return (
    <ul className="grid gap-2">
      {list.data.items.map((a) => (
        <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
          <div>
            <p className="font-medium">
              {a.patient_id ? <Link href={`/admin/patients/${a.patient_id}`} className="text-primary hover:underline">{a.patient_name}</Link> : a.patient_name}
              <span className="font-normal text-muted-foreground"> · {formatDay(a.admission_date, { day: "numeric", month: "short", year: "numeric" })}{a.bed ? ` · Bed ${a.bed}` : ""}{a.case_title ? ` · ${a.case_title}` : ""}</span>
            </p>
            <p className="text-xs text-muted-foreground">{a.status === "admitted" ? "Currently admitted" : `Discharged${a.discharge_date ? ` ${formatDay(a.discharge_date, { day: "numeric", month: "short" })}` : ""}`}</p>
          </div>
          <Link href={`/admin/ipd?month=${a.admission_date.slice(0, 7)}`} className="text-xs text-primary hover:underline">Open in register</Link>
        </li>
      ))}
    </ul>
  );
}
