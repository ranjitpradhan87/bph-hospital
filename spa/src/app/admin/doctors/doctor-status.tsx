import { cn } from "@/lib/utils";
import type { DoctorStatus, EmploymentStatus } from "@/types/management";

const STYLES: Record<DoctorStatus | EmploymentStatus, { label: string; className: string }> = {
  active: { label: "Active", className: "bg-success/12 text-success ring-success/30" },
  on_leave: { label: "On leave", className: "bg-warning/15 text-amber-700 ring-warning/40 dark:text-warning" },
  inactive: { label: "Inactive", className: "bg-muted text-muted-foreground ring-border" },
  suspended: { label: "Suspended", className: "bg-destructive/10 text-destructive ring-destructive/30" },
  resigned: { label: "Resigned", className: "bg-muted text-muted-foreground ring-border" },
  terminated: { label: "Terminated", className: "bg-muted text-muted-foreground ring-border" },
};

export function DoctorStatusBadge({ status }: { status: DoctorStatus | EmploymentStatus }) {
  const s = STYLES[status];
  return (
    <span className={cn("inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset", s.className)}>
      <span aria-hidden className="size-1.5 rounded-full bg-current" />
      {s.label}
    </span>
  );
}

export const EMPLOYMENT_LABELS: Record<EmploymentStatus, string> = {
  active: "Active", on_leave: "On leave", suspended: "Suspended", resigned: "Resigned", terminated: "Terminated",
};
