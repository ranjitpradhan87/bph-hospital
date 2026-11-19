import { FlaskConical, Printer } from "lucide-react";

import { BalanceText, LabStatusBadge } from "@/app/admin/lab/lab-page";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { inr } from "@/lib/forms";
import { printReport } from "@/lib/print-report";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { PortalLabOrder } from "@/types/lab";

/** The patient's own lab tests. Results show only once the lab has released the report. */
export function LabReports() {
  const list = useApi<PortalLabOrder[]>("/portal/lab-orders");
  return (
    <>
      <PageHeader title="Lab reports" description="Your lab tests and their reports." />
      {list.error ? <p role="alert" className="text-sm text-destructive">{list.error.message}</p> : !list.data ? <Skeleton className="h-40" /> : list.data.length === 0 ? (
        <EmptyState icon={FlaskConical} title="No lab tests yet" description="Tests done at the hospital lab appear here, with the report once it is ready." />
      ) : (
        <div className="grid gap-4">
          {list.data.map((o) => (
            <Card key={o.id}>
              <CardHeader className="flex-row flex-wrap items-start justify-between gap-2">
                <div>
                  <CardTitle className="font-heading text-base">{o.tests}</CardTitle>
                  <CardDescription>{o.order_number} · {formatDay(o.created_at.slice(0, 10), { day: "numeric", month: "short", year: "numeric" })} · {inr.format(o.bill.total)} · <BalanceText order={o} /></CardDescription>
                </div>
                <LabStatusBadge status={o.status} />
              </CardHeader>
              <CardContent className="grid gap-3">
                {o.status !== "completed" ? <p className="text-sm text-muted-foreground">Your report is not ready yet. We will let you know when it is.</p> : (
                  <>
                    <ul className="divide-y rounded-lg border text-sm">
                      {o.items.map((i) => (
                        <li key={i.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                          <span className="font-medium">{i.test_name}</span>
                          <span className={cn("tabular-nums", i.flag === "critical" && "font-semibold text-destructive", (i.flag === "high" || i.flag === "low") && "font-semibold")}>
                            {i.result_value}{i.result_unit ? ` ${i.result_unit}` : ""}{i.flag && i.flag !== "normal" && <span className="ml-1 text-xs uppercase">{i.flag}</span>}
                            {i.reference_range && <span className="ml-2 text-xs font-normal text-muted-foreground">Normal {i.reference_range}</span>}
                          </span>
                        </li>
                      ))}
                    </ul>
                    <div><Button variant="outline" size="sm" onClick={() => printReport(o)}><Printer aria-hidden /> Print report</Button></div>
                  </>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </>
  );
}
