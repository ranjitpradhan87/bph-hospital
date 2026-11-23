import { Stethoscope } from "lucide-react";

import { EmptyState } from "@/components/shared/empty-state";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import type { Page } from "@/types/api";
import { type OpdVisitRow, VISIT_STATUS_LABEL } from "@/types/opd";

const inr = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 0 });

/** A patient's OPD visits, newest first. */
export function PatientOpd({ patientId }: { patientId: number }) {
  const list = useApi<Page<OpdVisitRow>>("/opd/visits", { patient_id: patientId, page_size: 50 });
  return (
    <Card className="p-0">
      {!list.data ? <div className="p-4"><Skeleton className="h-40" /></div> : list.data.items.length === 0 ? <EmptyState icon={Stethoscope} title="No OPD visits yet" /> : (
        <Table>
          <TableHeader><TableRow><TableHead className="pl-4">Date</TableHead><TableHead>Doctor</TableHead><TableHead>Status</TableHead><TableHead className="text-right">Fee paid</TableHead><TableHead className="pr-4 text-right">Lab</TableHead></TableRow></TableHeader>
          <TableBody>
            {list.data.items.map((v) => (
              <TableRow key={v.id}>
                <TableCell className="pl-4">{formatDay(v.visit_date)}<span className="block text-xs text-muted-foreground">Token #{v.token_number}{v.source === "appointment" ? " · Booked" : ""}</span></TableCell>
                <TableCell>{v.doctor_name}<span className="block text-xs text-muted-foreground">{v.department}</span></TableCell>
                <TableCell><Badge variant={v.status === "cancelled" ? "destructive" : v.status === "completed" ? "outline" : "secondary"}>{VISIT_STATUS_LABEL[v.status]}</Badge></TableCell>
                <TableCell className="text-right tabular-nums">{v.bill ? `${inr.format(v.bill.paid)} / ${inr.format(v.bill.total)}` : "—"}</TableCell>
                <TableCell className="pr-4 text-right tabular-nums">{v.lab_count || "—"}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Card>
  );
}
