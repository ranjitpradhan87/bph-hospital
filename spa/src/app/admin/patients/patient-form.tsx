"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2, Save } from "lucide-react";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { applyApiError, blankToNull } from "@/lib/forms";
import { mobileSchema, nameSchema, normalizeMobile } from "@/lib/validation";
import { BLOOD_GROUPS } from "@/types/api";
import type { Patient } from "@/types/management";

const optionalMobile = z.string().trim().transform((v) => (v ? normalizeMobile(v) : ""))
  .refine((v) => !v || /^[6-9]\d{9}$/.test(v), "Enter a valid 10-digit mobile number");

const schema = z.object({
  full_name: nameSchema,
  mobile: mobileSchema,
  email: z.string().trim().refine((v) => !v || z.string().email().safeParse(v).success, "Enter a valid email"),
  date_of_birth: z.string().min(1, "Date of birth is required"),
  gender: z.enum(["male", "female", "other"], { message: "Select a gender" }),
  blood_group: z.string(),
  address: z.string().trim().max(500),
  city: z.string().trim().max(80),
  state: z.string().trim().max(80),
  pincode: z.string().trim().regex(/^(\d{6})?$/, "PIN code must be 6 digits"),
  emergency_contact_name: z.string().trim().max(150),
  emergency_contact_phone: optionalMobile,
  consent: z.boolean(),
});
type In = z.input<typeof schema>;
type Out = z.output<typeof schema>;
const FIELDS = Object.keys(schema.shape);

/** Register (patient = null) or edit a patient. Registration surfaces likely duplicates instead of creating them. */
export function PatientForm({ patient, onSaved, onCancel }: { patient: Patient | null; onSaved: (p: Patient) => void; onCancel?: () => void }) {
  const [duplicate, setDuplicate] = useState<string | null>(null);
  const { register, handleSubmit, setError, formState: { errors: e, isSubmitting } } = useForm<In, unknown, Out>({
    resolver: zodResolver(schema.refine((v) => patient || v.consent, { path: ["consent"], message: "Record the patient's consent first" })),
    defaultValues: {
      full_name: patient?.full_name ?? "", mobile: patient?.mobile ?? "", email: patient?.email ?? "",
      date_of_birth: patient?.date_of_birth ?? "", gender: patient?.gender ?? undefined, blood_group: patient?.blood_group ?? "",
      address: patient?.address ?? "", city: patient?.city ?? "", state: patient?.state ?? "", pincode: patient?.pincode ?? "",
      emergency_contact_name: patient?.emergency_contact_name ?? "", emergency_contact_phone: patient?.emergency_contact_phone ?? "",
      consent: false,
    },
  });

  async function submit(v: Out, allowDuplicate = false) {
    const { consent, ...rest } = v;
    const body = blankToNull({ ...rest, ...(patient ? {} : { consent, allow_duplicate: allowDuplicate }) });
    try {
      onSaved(await api<Patient>(patient ? `/patients/${patient.id}` : "/patients", { method: patient ? "PUT" : "POST", body }));
    } catch (err) {
      if (!patient && err instanceof ApiError && err.status === 409) setDuplicate(err.message);
      else applyApiError(err, setError, FIELDS);
    }
  }

  return (
    <form onSubmit={handleSubmit((v) => submit(v))} noValidate className="grid gap-4 sm:grid-cols-2">
      {e.root && <p role="alert" className="text-sm text-destructive sm:col-span-2">{e.root.message}</p>}
      <FormField id="p-name" label="Full name" error={e.full_name?.message} className="sm:col-span-2">
        <Input {...fieldAria("p-name", e.full_name?.message)} {...register("full_name")} />
      </FormField>
      <FormField id="p-mobile" label="Mobile" error={e.mobile?.message}>
        <Input {...fieldAria("p-mobile", e.mobile?.message)} type="tel" {...register("mobile")} />
      </FormField>
      <FormField id="p-email" label="Email" optional error={e.email?.message}>
        <Input {...fieldAria("p-email", e.email?.message)} type="email" {...register("email")} />
      </FormField>
      <FormField id="p-dob" label="Date of birth" error={e.date_of_birth?.message}>
        <Input {...fieldAria("p-dob", e.date_of_birth?.message)} type="date" {...register("date_of_birth")} />
      </FormField>
      <div className="grid grid-cols-2 gap-3">
        <FormField id="p-gender" label="Gender" error={e.gender?.message}>
          <NativeSelect {...fieldAria("p-gender", e.gender?.message)} defaultValue={patient?.gender ?? ""} {...register("gender")}>
            <option value="" disabled>Select…</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option>
          </NativeSelect>
        </FormField>
        <FormField id="p-blood" label="Blood group" optional>
          <NativeSelect id="p-blood" {...register("blood_group")}>
            <option value="">Unknown</option>{BLOOD_GROUPS.map((g) => <option key={g} value={g}>{g}</option>)}
          </NativeSelect>
        </FormField>
      </div>
      <FormField id="p-address" label="Address" optional error={e.address?.message} className="sm:col-span-2">
        <Textarea {...fieldAria("p-address", e.address?.message)} rows={2} {...register("address")} />
      </FormField>
      <div className="grid grid-cols-3 gap-3 sm:col-span-2">
        <FormField id="p-city" label="City" optional><Input id="p-city" {...register("city")} /></FormField>
        <FormField id="p-state" label="State" optional><Input id="p-state" {...register("state")} /></FormField>
        <FormField id="p-pin" label="PIN" optional error={e.pincode?.message}>
          <Input {...fieldAria("p-pin", e.pincode?.message)} inputMode="numeric" {...register("pincode")} />
        </FormField>
      </div>
      <FormField id="p-ec-name" label="Emergency contact" optional>
        <Input id="p-ec-name" {...register("emergency_contact_name")} />
      </FormField>
      <FormField id="p-ec-phone" label="Emergency contact mobile" optional error={e.emergency_contact_phone?.message}>
        <Input {...fieldAria("p-ec-phone", e.emergency_contact_phone?.message)} type="tel" {...register("emergency_contact_phone")} />
      </FormField>
      {!patient && (
        <div className="grid gap-1 sm:col-span-2">
          <label className="flex items-start gap-2.5 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 accent-primary" {...register("consent")} />
            <span className="text-muted-foreground">The patient consents to the hospital processing their personal and health data for their care (DPDP Act, 2023).</span>
          </label>
          {e.consent && <p role="alert" className="text-xs text-destructive">{e.consent.message}</p>}
        </div>
      )}
      {duplicate && (
        <div role="alert" className="grid gap-2 rounded-lg border border-warning/50 bg-warning/10 p-3 text-sm sm:col-span-2">
          <p>{duplicate}</p>
          <div className="flex gap-2">
            <Button type="button" size="sm" variant="outline" onClick={handleSubmit((v) => submit(v, true))}>It&apos;s a different person — register anyway</Button>
          </div>
        </div>
      )}
      <div className="flex justify-end gap-2 sm:col-span-2">
        {onCancel && <Button type="button" variant="outline" onClick={onCancel}>Cancel</Button>}
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? <Loader2 className="animate-spin" aria-hidden /> : <Save aria-hidden />} {patient ? "Save changes" : "Register patient"}</Button>
      </div>
    </form>
  );
}
