import { cn } from "@/lib/utils";
import type { AppointmentStatus, BillStatus, SlotDisplayStatus } from "@/types/api";

type Status = SlotDisplayStatus | AppointmentStatus | BillStatus;

const STYLES: Record<Status, { label: string; className: string }> = {
  // slots
  available: { label: "Available", className: "bg-slot-available/12 text-slot-available ring-slot-available/30" },
  partially_booked: { label: "Partially booked", className: "bg-slot-partial/15 text-amber-700 ring-slot-partial/40 dark:text-slot-partial" },
  fully_booked: { label: "Fully booked", className: "bg-slot-full/12 text-slot-full ring-slot-full/30" },
  blocked: { label: "Blocked", className: "bg-slot-blocked/12 text-slot-blocked ring-slot-blocked/30" },
  cancelled: { label: "Cancelled", className: "bg-slot-cancelled/15 text-muted-foreground ring-slot-cancelled/40 line-through" },
  // appointments
  pending: { label: "Pending", className: "bg-warning/15 text-amber-700 ring-warning/40 dark:text-warning" },
  confirmed: { label: "Confirmed", className: "bg-primary/10 text-primary ring-primary/30" },
  checked_in: { label: "Checked in", className: "bg-teal/12 text-teal ring-teal/30" },
  in_consultation: { label: "In consultation", className: "bg-info/12 text-info ring-info/30" },
  completed: { label: "Completed", className: "bg-success/12 text-success ring-success/30" },
  no_show: { label: "No show", className: "bg-destructive/10 text-destructive ring-destructive/30" },
  // bills
  partially_paid: { label: "Partially paid", className: "bg-warning/15 text-amber-700 ring-warning/40 dark:text-warning" },
  paid: { label: "Paid", className: "bg-success/12 text-success ring-success/30" },
  refunded: { label: "Refunded", className: "bg-info/12 text-info ring-info/30" },
};

/** `awaitingPayment`: a held online booking that is not confirmed until its advance is paid. */
export function StatusBadge({ status, className, awaitingPayment }: { status: Status; className?: string; awaitingPayment?: boolean }) {
  const style = awaitingPayment && status === "pending" ? { label: "Awaiting payment", className: STYLES.pending.className } : STYLES[status];
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset",
        style.className,
        className,
      )}
    >
      <span aria-hidden className="size-1.5 rounded-full bg-current" />
      {style.label}
    </span>
  );
}
