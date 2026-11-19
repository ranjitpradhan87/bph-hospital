"use client";

import { CalendarDays } from "lucide-react";
import Link from "next/link";

import { EmptyState } from "@/components/shared/empty-state";
import { StatusBadge } from "@/components/shared/status-badge";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { inr } from "@/lib/forms";
import { formatDay, formatTime } from "@/lib/public-format";
import type { AppointmentRow } from "@/types/management";

/** Appointment history; `show` picks the counterpart column (patient on a doctor page, doctor on a patient page). */
export function AppointmentsTable({ rows, show }: { rows: AppointmentRow[]; show: "patient" | "doctor" }) {
  if (!rows.length) return <EmptyState icon={CalendarDays} title="No appointments yet" />;
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="pl-4">When</TableHead>
          <TableHead>{show === "patient" ? "Patient" : "Doctor"}</TableHead>
          <TableHead className="hidden md:table-cell">Department</TableHead>
          <TableHead className="hidden sm:table-cell">Fee</TableHead>
          <TableHead className="pr-4">Status</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.map((a) => (
          <TableRow key={a.id}>
            <TableCell className="pl-4">
              <span className="block font-medium">{formatDay(a.appointment_date, { day: "numeric", month: "short", year: "numeric" })}</span>
              <span className="text-xs text-muted-foreground">{formatTime(a.start_time)} · {a.appointment_number}</span>
            </TableCell>
            <TableCell>
              {show === "patient" ? (
                <Link href={`/admin/patients/${a.patient_id}`} className="hover:text-primary">
                  {a.patient_name} <span className="text-xs text-muted-foreground">{a.patient_code}</span>
                </Link>
              ) : (
                a.doctor_name
              )}
            </TableCell>
            <TableCell className="hidden md:table-cell">{a.department_name}</TableCell>
            <TableCell className="hidden tabular-nums sm:table-cell">{inr.format(Number(a.consultation_fee))}</TableCell>
            <TableCell className="pr-4"><StatusBadge status={a.status} /></TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
