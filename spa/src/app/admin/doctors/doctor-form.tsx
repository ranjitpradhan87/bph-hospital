"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2, Save } from "lucide-react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { z } from "zod";

import { FormField, fieldAria } from "@/components/shared/form-field";
import { ImageField } from "@/components/shared/image-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";
import { applyApiError, blankToNull } from "@/lib/forms";
import { useApi } from "@/lib/use-api";
import { emailSchema, mobileSchema, nameSchema, passwordSchema } from "@/lib/validation";
import type { DepartmentAdmin, DoctorAdmin } from "@/types/management";

const base = z.object({
  full_name: nameSchema,
  email: emailSchema,
  mobile: mobileSchema,
  gender: z.enum(["", "male", "female", "other"]),
  qualification: z.string().trim().min(2, "Enter qualifications").max(255),
  specialization: z.string().trim().min(2, "Enter the specialisation").max(120),
  experience_years: z.coerce.number().int().min(0).max(70),
  registration_number: z.string().trim().min(3, "Enter the medical council registration number").max(60),
  consultation_fee: z.coerce.number().min(0).max(100000),
  advance_amount: z.string().refine((v) => v.trim() === "" || (Number(v) >= 0 && Number(v) <= 100000), "Enter an amount of 0 or more"),
  languages: z.string().trim().max(255),
  bio: z.string().trim().max(5000),
  photo_url: z.string().nullable(),
  is_featured: z.boolean(),
  department_ids: z.array(z.number()).min(1, "Choose at least one department"),
  primary_department_id: z.number().nullable(),
});
const createSchema = base.extend({ password: passwordSchema });
type In = z.input<typeof createSchema>;
type Out = z.output<typeof createSchema>;
const FIELDS = [...Object.keys(createSchema.shape)] as const;

export function DoctorForm({ doctor, onSaved }: { doctor: DoctorAdmin | null; onSaved: (d: DoctorAdmin) => void }) {
  const depts = useApi<DepartmentAdmin[]>("/departments");
  const schema = doctor ? base.extend({ password: z.string().optional() }) : createSchema;
  const { register, control, handleSubmit, setError, setValue, formState: { errors, isSubmitting, isDirty } } = useForm<In, unknown, Out>({
    resolver: zodResolver(schema as typeof createSchema),
    defaultValues: {
      full_name: doctor?.full_name ?? "",
      email: doctor?.email ?? "",
      mobile: doctor?.mobile ?? "",
      gender: doctor?.gender ?? "",
      qualification: doctor?.qualification ?? "",
      specialization: doctor?.specialization ?? "",
      experience_years: doctor?.experience_years ?? 0,
      registration_number: doctor?.registration_number ?? "",
      consultation_fee: doctor ? Number(doctor.consultation_fee) : 500,
      advance_amount: doctor?.advance_amount == null ? "" : String(doctor.advance_amount),
      languages: doctor?.languages ?? "",
      bio: doctor?.bio ?? "",
      photo_url: doctor?.photo_url ?? null,
      is_featured: doctor?.is_featured ?? false,
      department_ids: doctor?.departments.map((d) => d.id) ?? [],
      primary_department_id: doctor?.departments.find((d) => d.is_primary)?.id ?? null,
      password: "",
    },
  });
  const chosen = useWatch({ control, name: "department_ids" });
  const primary = useWatch({ control, name: "primary_department_id" });
  const e = errors;

  async function onSubmit(values: Out) {
    const { password, ...rest } = values;
    const body = blankToNull({
      ...rest,
      gender: rest.gender || null,
      primary_department_id: rest.primary_department_id && rest.department_ids.includes(rest.primary_department_id) ? rest.primary_department_id : null,
      ...(doctor ? {} : { password }),
    });
    try {
      const saved = await api<DoctorAdmin>(doctor ? `/doctors/${doctor.id}` : "/doctors", { method: doctor ? "PUT" : "POST", body });
      onSaved(saved);
    } catch (err) {
      applyApiError(err, setError, FIELDS);
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4">
      {e.root && <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">{e.root.message}</p>}
      <Card>
        <CardHeader><CardTitle>Personal & login</CardTitle></CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          <FormField id="dr-name" label="Full name" error={e.full_name?.message} hint="Include the title, e.g. Dr Asha Rao">
            <Input {...fieldAria("dr-name", e.full_name?.message, "hint")} {...register("full_name")} />
          </FormField>
          <FormField id="dr-gender" label="Gender" optional error={e.gender?.message}>
            <NativeSelect {...fieldAria("dr-gender")} {...register("gender")}>
              <option value="">Prefer not to say</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option>
            </NativeSelect>
          </FormField>
          <FormField id="dr-email" label="Email (sign-in)" error={e.email?.message}>
            <Input {...fieldAria("dr-email", e.email?.message)} type="email" autoComplete="off" {...register("email")} />
          </FormField>
          <FormField id="dr-mobile" label="Mobile (sign-in)" error={e.mobile?.message}>
            <Input {...fieldAria("dr-mobile", e.mobile?.message)} type="tel" {...register("mobile")} />
          </FormField>
          {!doctor && (
            <FormField id="dr-pw" label="Temporary password" error={e.password?.message} hint="Share securely; they can change it after signing in." className="sm:col-span-2">
              <Input {...fieldAria("dr-pw", e.password?.message, "hint")} type="text" autoComplete="new-password" {...register("password")} />
            </FormField>
          )}
          <div className="sm:col-span-2">
            <Controller control={control} name="photo_url" render={({ field }) => (
              <ImageField label="Photo" round value={field.value} onChange={field.onChange} hint="Square photo, shown on the website. PNG, JPEG or WebP." />
            )} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>Professional</CardTitle></CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          <FormField id="dr-qual" label="Qualifications" error={e.qualification?.message} className="sm:col-span-2">
            <Input {...fieldAria("dr-qual", e.qualification?.message)} placeholder="MBBS, MD (Medicine), DM (Cardiology)" {...register("qualification")} />
          </FormField>
          <FormField id="dr-spec" label="Specialisation" error={e.specialization?.message}>
            <Input {...fieldAria("dr-spec", e.specialization?.message)} {...register("specialization")} />
          </FormField>
          <FormField id="dr-reg" label="Registration number" error={e.registration_number?.message} hint="State Medical Council / NMC number, shown publicly">
            <Input {...fieldAria("dr-reg", e.registration_number?.message, "hint")} {...register("registration_number")} />
          </FormField>
          <FormField id="dr-exp" label="Experience (years)" error={e.experience_years?.message}>
            <Input {...fieldAria("dr-exp", e.experience_years?.message)} type="number" min={0} className="w-28" {...register("experience_years")} />
          </FormField>
          <FormField id="dr-fee" label="Consultation fee (₹)" error={e.consultation_fee?.message} hint="Default for new slots">
            <Input {...fieldAria("dr-fee", e.consultation_fee?.message, "hint")} type="number" min={0} step="50" className="w-36" {...register("consultation_fee")} />
          </FormField>
          <FormField id="dr-adv" label="Advance payment (₹)" error={e.advance_amount?.message} hint={`Paid online to confirm a booking. Blank = hospital default${doctor ? ` (₹${doctor.default_advance})` : ""}; never more than the fee.`}>
            <Input {...fieldAria("dr-adv", e.advance_amount?.message, "hint")} type="number" min={0} step="50" className="w-36" placeholder={doctor ? String(doctor.default_advance) : "Default"} {...register("advance_amount")} />
          </FormField>
          <FormField id="dr-lang" label="Languages" optional error={e.languages?.message} className="sm:col-span-2">
            <Input {...fieldAria("dr-lang", e.languages?.message)} placeholder="English, Hindi, Kannada" {...register("languages")} />
          </FormField>
          <FormField id="dr-bio" label="Biography" optional error={e.bio?.message} className="sm:col-span-2" hint="Shown on the public profile. Blank line between paragraphs.">
            <Textarea {...fieldAria("dr-bio", e.bio?.message, "hint")} rows={5} {...register("bio")} />
          </FormField>
          <Controller control={control} name="is_featured" render={({ field }) => (
            <label className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm sm:col-span-2">
              <span><span className="font-medium">Feature on the home page</span><span className="block text-xs text-muted-foreground">Featured doctors are shown first.</span></span>
              <Switch checked={field.value} onCheckedChange={field.onChange} />
            </label>
          )} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>Departments</CardTitle></CardHeader>
        <CardContent className="grid gap-3">
          <Controller control={control} name="department_ids" render={({ field }) => (
            <div role="group" aria-label="Departments" className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              {depts.data?.map((d) => {
                const on = field.value.includes(d.id);
                return (
                  <label key={d.id} className="flex cursor-pointer items-center gap-2.5 rounded-lg border p-2.5 text-sm has-data-checked:border-primary has-data-checked:bg-primary/5">
                    <Checkbox checked={on} onCheckedChange={(c) => field.onChange(c ? [...field.value, d.id] : field.value.filter((x) => x !== d.id))} />
                    {d.name}{!d.is_active && <span className="text-xs text-muted-foreground">(hidden)</span>}
                  </label>
                );
              })}
            </div>
          )} />
          {e.department_ids && <p role="alert" className="text-xs text-destructive">{e.department_ids.message}</p>}
          {chosen.length > 1 && (
            <FormField id="dr-primary" label="Primary department" hint="Listed first on the profile">
              <NativeSelect id="dr-primary" className="w-64" value={String(primary ?? "")}
                            onChange={(ev) => setValue("primary_department_id", ev.target.value ? Number(ev.target.value) : null, { shouldDirty: true })}>
                <option value="">First selected</option>
                {depts.data?.filter((d) => chosen.includes(d.id)).map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
              </NativeSelect>
            </FormField>
          )}
        </CardContent>
      </Card>

      <div className="sticky bottom-4 z-30 flex justify-end">
        <Button type="submit" size="lg" disabled={isSubmitting || (!!doctor && !isDirty)} className="shadow-lg">
          {isSubmitting ? <Loader2 className="animate-spin" aria-hidden /> : <Save aria-hidden />}
          {doctor ? "Save changes" : "Create doctor"}
        </Button>
      </div>
    </form>
  );
}
