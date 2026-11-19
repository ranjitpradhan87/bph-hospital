import { CalendarX2 } from "lucide-react";
import { useEffect, useState } from "react";

import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { formatDay, formatTime } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import { type OpenSlot, todayIST } from "@/types/booking";
import type { PublicDoctorDetail } from "@/types/public";

interface SlotPickerProps {
  doctorId: number;
  value: number | null;
  onChange: (slotId: number | null, slot?: OpenSlot, date?: string) => void;
  /** Start on this date instead of the doctor's next available day. */
  initialDate?: string;
  id?: string;
}

/** Pick a date, then one of the doctor's open slots (live from the public availability API). */
export function SlotPicker({ doctorId, value, onChange, initialDate, id = "slot-date" }: SlotPickerProps) {
  const doctor = useApi<PublicDoctorDetail>(`/public/doctors/${doctorId}`);
  const [date, setDate] = useState(initialDate ?? "");
  // Default to the next day with open slots once we know it.
  useEffect(() => {
    if (!date && doctor.data?.next_available_date) setDate(doctor.data.next_available_date);
  }, [date, doctor.data]);

  const slots = useApi<OpenSlot[]>(date ? `/public/doctors/${doctorId}/slots` : null, { date });

  return (
    <div className="grid gap-3">
      <div className="grid gap-1.5">
        <label htmlFor={id} className="text-sm font-medium">Date</label>
        <Input id={id} type="date" min={todayIST()} value={date} className="w-44"
               onChange={(e) => { setDate(e.target.value); onChange(null); }} />
        {doctor.data && !doctor.data.next_available_date && (
          <p className="text-xs text-muted-foreground">This doctor has no open slots right now.</p>
        )}
      </div>
      {!date ? null : slots.loading && !slots.data ? (
        <div className="flex flex-wrap gap-2">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-9 w-20" />)}</div>
      ) : slots.error ? (
        <p role="alert" className="text-sm text-destructive">{slots.error.message}</p>
      ) : slots.data && slots.data.length > 0 ? (
        <div>
          <p className="mb-2 text-xs text-muted-foreground">{slots.data.length} open slot{slots.data.length === 1 ? "" : "s"} on {formatDay(date)}</p>
          <ul className="flex flex-wrap gap-2" role="radiogroup" aria-label="Available times">
            {slots.data.map((s) => (
              <li key={s.id}>
                <button
                  type="button"
                  role="radio"
                  aria-checked={value === s.id}
                  onClick={() => onChange(s.id, s, date)}
                  className={cn(
                    "rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors",
                    value === s.id ? "border-primary bg-primary text-primary-foreground" : "border-primary/40 text-primary hover:bg-primary/10",
                  )}
                >
                  {formatTime(s.start_time)}
                  {s.appointment_type === "online" && <span className="ml-1 text-[10px] uppercase">online</span>}
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <p className="flex items-center gap-2 text-sm text-muted-foreground"><CalendarX2 className="size-4" aria-hidden /> No open slots on {formatDay(date)}. Try another date.</p>
      )}
    </div>
  );
}
