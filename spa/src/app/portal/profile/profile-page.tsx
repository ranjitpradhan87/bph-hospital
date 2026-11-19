"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowLeft, Loader2, Save } from "lucide-react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { FormField, fieldAria } from "@/components/shared/form-field";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";
import { applyApiError, blankToNull } from "@/lib/forms";
import { formatDay } from "@/lib/public-format";
import { useApi } from "@/lib/use-api";
import { normalizeMobile } from "@/lib/validation";
import { BLOOD_GROUPS } from "@/types/api";
import type { Patient } from "@/types/management";

const schema = z.object({
  blood_group: z.string(),
  address: z.string().trim().max(500),
  city: z.string().trim().max(80),
  state: z.string().trim().max(80),
  pincode: z.string().trim().regex(/^(\d{6})?$/, "PIN code must be 6 digits"),
  emergency_contact_name: z.string().trim().max(150),
  emergency_contact_phone: z.string().trim().transform((v) => (v ? normalizeMobile(v) : ""))
    .refine((v) => !v || /^[6-9]\d{9}$/.test(v), "Enter a valid 10-digit mobile number"),
});
type In = z.input<typeof schema>;
type Out = z.output<typeof schema>;

export function ProfilePage() {
  const profile = useApi<Patient>("/portal/profile");
  const p = profile.data;
  return (
    <div className="mx-auto max-w-3xl">
      <Link href="/portal" className="mb-4 flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" aria-hidden /> Home</Link>
      <PageHeader title="My profile" description="Keep your contact details up to date so we can reach you about appointments." />
      {profile.error && <p role="alert" className="text-sm text-destructive">{profile.error.message}</p>}
      {!p ? <Skeleton className="h-96" /> : (
        <div className="grid gap-4">
          <Card>
            <CardHeader>
              <CardTitle>Identity</CardTitle>
              <CardDescription>To correct these, please ask at the front desk — they&apos;re verified against your ID.</CardDescription>
            </CardHeader>
            <CardContent>
              <dl className="grid gap-4 text-sm sm:grid-cols-2">
                {[["Patient ID", p.patient_code], ["Name", p.full_name], ["Date of birth", p.date_of_birth ? formatDay(p.date_of_birth, { day: "numeric", month: "long", year: "numeric" }) : "Our staff will add this"],
                  ["Mobile", p.mobile], ["Email", p.email ?? "—"]].map(([k, v]) => (
                  <div key={k}><dt className="text-muted-foreground">{k}</dt><dd className="font-medium">{v}</dd></div>
                ))}
              </dl>
            </CardContent>
          </Card>
          <ContactForm patient={p} />
        </div>
      )}
    </div>
  );
}

function ContactForm({ patient }: { patient: Patient }) {
  const { register, handleSubmit, setError, reset, formState: { errors: e, isSubmitting, isDirty } } = useForm<In, unknown, Out>({
    resolver: zodResolver(schema),
    defaultValues: {
      blood_group: patient.blood_group ?? "", address: patient.address ?? "", city: patient.city ?? "", state: patient.state ?? "",
      pincode: patient.pincode ?? "", emergency_contact_name: patient.emergency_contact_name ?? "",
      emergency_contact_phone: patient.emergency_contact_phone ?? "",
    },
  });

  async function onSubmit(v: Out) {
    try {
      const saved = await api<Patient>("/portal/profile", { method: "PUT", body: blankToNull({ ...v }) });
      reset({
        blood_group: saved.blood_group ?? "", address: saved.address ?? "", city: saved.city ?? "", state: saved.state ?? "",
        pincode: saved.pincode ?? "", emergency_contact_name: saved.emergency_contact_name ?? "", emergency_contact_phone: saved.emergency_contact_phone ?? "",
      });
      toast.success("Your details are saved");
    } catch (err) {
      applyApiError(err, setError, Object.keys(schema.shape));
    }
  }

  return (
    <Card>
      <CardHeader><CardTitle>Contact & emergency details</CardTitle></CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4 sm:grid-cols-2">
          {e.root && <p role="alert" className="text-sm text-destructive sm:col-span-2">{e.root.message}</p>}
          <FormField id="pf-address" label="Address" optional error={e.address?.message} className="sm:col-span-2">
            <Textarea {...fieldAria("pf-address", e.address?.message)} rows={2} autoComplete="street-address" {...register("address")} />
          </FormField>
          <FormField id="pf-city" label="City" optional><Input id="pf-city" autoComplete="address-level2" {...register("city")} /></FormField>
          <FormField id="pf-state" label="State" optional><Input id="pf-state" autoComplete="address-level1" {...register("state")} /></FormField>
          <FormField id="pf-pin" label="PIN code" optional error={e.pincode?.message}>
            <Input {...fieldAria("pf-pin", e.pincode?.message)} inputMode="numeric" autoComplete="postal-code" {...register("pincode")} />
          </FormField>
          <FormField id="pf-blood" label="Blood group" optional>
            <NativeSelect id="pf-blood" {...register("blood_group")}>
              <option value="">Not sure</option>{BLOOD_GROUPS.map((g) => <option key={g} value={g}>{g}</option>)}
            </NativeSelect>
          </FormField>
          <FormField id="pf-ec" label="Emergency contact name" optional><Input id="pf-ec" {...register("emergency_contact_name")} /></FormField>
          <FormField id="pf-ecp" label="Emergency contact mobile" optional error={e.emergency_contact_phone?.message}>
            <Input {...fieldAria("pf-ecp", e.emergency_contact_phone?.message)} type="tel" {...register("emergency_contact_phone")} />
          </FormField>
          <div className="flex justify-end sm:col-span-2">
            <Button type="submit" disabled={isSubmitting || !isDirty}>{isSubmitting ? <Loader2 className="animate-spin" aria-hidden /> : <Save aria-hidden />} Save</Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
