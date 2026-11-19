"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { CalendarOff, Loader2, Plus, Trash2 } from "lucide-react";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { refreshPublicContent } from "@/app/admin/settings/actions";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError, api, getAccessToken } from "@/lib/api";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import type { Holiday } from "@/types/api";

const schema = z.object({
  holiday_date: z.string().min(1, "Choose a date"),
  name: z.string().trim().min(2, "Enter a name").max(120),
});
type Values = z.infer<typeof schema>;

export function HolidaysTab() {
  const thisYear = Number(new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kolkata", year: "numeric" }).format(new Date()));
  const [year, setYear] = useState(thisYear);
  const holidays = useApi<Holiday[]>("/holidays", { year });
  const [deleting, setDeleting] = useState<Holiday | null>(null);
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<Values>({ resolver: zodResolver(schema) });

  async function add(values: Values) {
    try {
      const h = await api<Holiday>("/holidays", { method: "POST", body: values });
      toast.success(`${h.name} added — no slots will be offered that day`);
      reset();
      if (Number(h.holiday_date.slice(0, 4)) !== year) setYear(Number(h.holiday_date.slice(0, 4)));
      else holidays.reload();
      refreshPublicContent(getAccessToken());
    } catch (err) {
      setError(err instanceof ApiError && err.status === 409 ? "holiday_date" : "root", {
        message: err instanceof ApiError ? err.message : "Could not add holiday",
      });
    }
  }

  async function remove(h: Holiday) {
    try {
      await api(`/holidays/${h.id}`, { method: "DELETE" });
      toast.success(`${h.name} removed`);
      holidays.reload();
      refreshPublicContent(getAccessToken());
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not remove holiday");
      throw err;
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
      <Card className="gap-0">
        <CardHeader className="flex flex-row items-center justify-between gap-3 border-b pb-4">
          <div>
            <CardTitle>Hospital holidays</CardTitle>
            <CardDescription>No appointment slots are offered on these dates.</CardDescription>
          </div>
          <NativeSelect aria-label="Year" value={year} onChange={(e) => setYear(Number(e.target.value))} className="w-28">
            {[thisYear - 1, thisYear, thisYear + 1, thisYear + 2].map((y) => (
              <option key={y} value={y}>{y}</option>
            ))}
          </NativeSelect>
        </CardHeader>
        <CardContent className="p-0">
          {!holidays.data ? (
            <div className="grid gap-2 p-4">{Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-10" />)}</div>
          ) : holidays.data.length === 0 ? (
            <EmptyState icon={CalendarOff} title={`No holidays in ${year}`} description="Add public holidays and planned closures." />
          ) : (
            <ul className="divide-y">
              {holidays.data.map((h) => (
                <li key={h.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <span>
                    <span className="block font-medium">{h.name}</span>
                    <span className="text-sm text-muted-foreground">{formatDay(h.holiday_date, { weekday: "long", day: "numeric", month: "long", year: "numeric" })}</span>
                  </span>
                  <Button variant="ghost" size="icon-sm" aria-label={`Remove ${h.name}`} onClick={() => setDeleting(h)}>
                    <Trash2 />
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      <Card className="h-fit">
        <CardHeader>
          <CardTitle>Add a holiday</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit(add)} noValidate className="grid gap-4">
            {errors.root && <p role="alert" className="text-sm text-destructive">{errors.root.message}</p>}
            <FormField id="h-date" label="Date" error={errors.holiday_date?.message}>
              <Input {...fieldAria("h-date", errors.holiday_date?.message)} type="date" {...register("holiday_date")} />
            </FormField>
            <FormField id="h-name" label="Name" error={errors.name?.message}>
              <Input {...fieldAria("h-name", errors.name?.message)} placeholder="e.g. Diwali" {...register("name")} />
            </FormField>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? <Loader2 className="animate-spin" aria-hidden /> : <Plus aria-hidden />} Add holiday
            </Button>
          </form>
        </CardContent>
      </Card>

      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title={`Remove ${deleting?.name}?`}
        description="Slots on this date will become bookable again if they're published."
        confirmLabel="Remove"
        destructive
        onConfirm={() => remove(deleting!)}
      />
    </div>
  );
}
