"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2, Save } from "lucide-react";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";

import { RolePicker, useAssignableRoles } from "@/app/admin/users/role-picker";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { ImageField } from "@/components/shared/image-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { applyApiError, blankToNull } from "@/lib/forms";
import { useApi } from "@/lib/use-api";
import { emailSchema, mobileSchema, nameSchema, passwordSchema } from "@/lib/validation";
import type { Role } from "@/types/api";
import type { DepartmentAdmin, StaffMember } from "@/types/management";

const base = z.object({
  full_name: nameSchema,
  email: emailSchema,
  mobile: mobileSchema,
  gender: z.enum(["", "male", "female", "other"]),
  date_of_birth: z.string(),
  address: z.string().trim().max(500),
  photo_url: z.string().nullable(),
  department_id: z.string(),
  designation: z.string().trim().min(2, "Enter the job title").max(100),
  joining_date: z.string().min(1, "Enter the joining date"),
  salary: z.string().regex(/^(\d{1,8}(\.\d{1,2})?)?$/, "Enter an amount like 32000 or 32000.50"),
  password: z.string(),
  roles: z.array(z.string()),
});
type Values = z.infer<typeof base>;
const FIELDS = Object.keys(base.shape);

export function StaffForm({ staff, onSaved }: { staff: StaffMember | null; onSaved: (s: StaffMember) => void }) {
  const { can } = useAuth();
  const canSalary = can("staff.salary");
  const depts = useApi<DepartmentAdmin[]>("/departments");
  const roles = useApi<Role[]>(staff ? null : "/roles");
  const assignable = useAssignableRoles(roles.data).filter((r) => r.code !== "doctor");

  const schema = staff
    ? base
    : base.extend({ password: passwordSchema, roles: z.array(z.string()).min(1, "Choose at least one role") });
  const { register, control, handleSubmit, setError, formState: { errors: e, isSubmitting, isDirty } } = useForm<Values>({
    resolver: zodResolver(schema as typeof base),
    defaultValues: {
      full_name: staff?.full_name ?? "",
      email: staff?.email ?? "",
      mobile: staff?.mobile ?? "",
      gender: staff?.gender ?? "",
      date_of_birth: staff?.date_of_birth ?? "",
      address: staff?.address ?? "",
      photo_url: staff?.photo_url ?? null,
      department_id: staff?.department_id ? String(staff.department_id) : "",
      designation: staff?.designation ?? "",
      joining_date: staff?.joining_date ?? "",
      salary: staff?.salary ?? "",
      password: "",
      roles: [],
    },
  });

  async function onSubmit(v: Values) {
    const { password, roles: chosen, salary, department_id, ...rest } = v;
    const body: Record<string, unknown> = blankToNull({ ...rest, gender: rest.gender || null, department_id: department_id ? Number(department_id) : null });
    if (canSalary) body.salary = salary === "" ? null : salary;
    if (!staff) Object.assign(body, { password, roles: chosen });
    try {
      onSaved(await api<StaffMember>(staff ? `/staff/${staff.id}` : "/staff", { method: staff ? "PUT" : "POST", body }));
    } catch (err) {
      applyApiError(err, setError, FIELDS);
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4">
      {e.root && <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">{e.root.message}</p>}
      <Card>
        <CardHeader><CardTitle>Personal</CardTitle></CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          <FormField id="st-name" label="Full name" error={e.full_name?.message}>
            <Input {...fieldAria("st-name", e.full_name?.message)} {...register("full_name")} />
          </FormField>
          <FormField id="st-gender" label="Gender" optional>
            <NativeSelect id="st-gender" {...register("gender")}>
              <option value="">Prefer not to say</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option>
            </NativeSelect>
          </FormField>
          <FormField id="st-email" label="Email (sign-in)" error={e.email?.message}>
            <Input {...fieldAria("st-email", e.email?.message)} type="email" autoComplete="off" {...register("email")} />
          </FormField>
          <FormField id="st-mobile" label="Mobile (sign-in)" error={e.mobile?.message}>
            <Input {...fieldAria("st-mobile", e.mobile?.message)} type="tel" {...register("mobile")} />
          </FormField>
          <FormField id="st-dob" label="Date of birth" optional error={e.date_of_birth?.message}>
            <Input {...fieldAria("st-dob", e.date_of_birth?.message)} type="date" {...register("date_of_birth")} />
          </FormField>
          <FormField id="st-address" label="Address" optional error={e.address?.message} className="sm:col-span-2">
            <Textarea {...fieldAria("st-address", e.address?.message)} rows={2} {...register("address")} />
          </FormField>
          <div className="sm:col-span-2">
            <Controller control={control} name="photo_url" render={({ field }) => (
              <ImageField label="Photo" round value={field.value} onChange={field.onChange} hint="Optional. Used on ID cards and the staff list." />
            )} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>Employment</CardTitle></CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          <FormField id="st-desig" label="Designation" error={e.designation?.message}>
            <Input {...fieldAria("st-desig", e.designation?.message)} placeholder="e.g. Staff nurse" {...register("designation")} />
          </FormField>
          <FormField id="st-dept" label="Department" optional error={e.department_id?.message}>
            <NativeSelect {...fieldAria("st-dept", e.department_id?.message)} {...register("department_id")}>
              <option value="">None / administration</option>
              {depts.data?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </NativeSelect>
          </FormField>
          <FormField id="st-join" label="Joining date" error={e.joining_date?.message}>
            <Input {...fieldAria("st-join", e.joining_date?.message)} type="date" {...register("joining_date")} />
          </FormField>
          {canSalary && (
            <FormField id="st-salary" label="Monthly salary (₹)" optional error={e.salary?.message} hint="Encrypted at rest; visible only to people with salary access.">
              <Input {...fieldAria("st-salary", e.salary?.message, "hint")} inputMode="decimal" className="w-40" {...register("salary")} />
            </FormField>
          )}
        </CardContent>
      </Card>

      {!staff && (
        <Card>
          <CardHeader>
            <CardTitle>Login & roles</CardTitle>
            <CardDescription>Roles decide what they can do. Doctors are added from the Doctors screen.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            <FormField id="st-pw" label="Temporary password" error={e.password?.message} hint="Share securely; they can change it after signing in.">
              <Input {...fieldAria("st-pw", e.password?.message, "hint")} type="text" autoComplete="new-password" className="max-w-sm" {...register("password")} />
            </FormField>
            <Controller control={control} name="roles" render={({ field }) => (
              <RolePicker roles={assignable} value={field.value} onChange={field.onChange} describedBy="st-roles-error" />
            )} />
            {e.roles && <p id="st-roles-error" role="alert" className="text-xs text-destructive">{e.roles.message}</p>}
          </CardContent>
        </Card>
      )}

      <div className="sticky bottom-4 z-30 flex justify-end">
        <Button type="submit" size="lg" className="shadow-lg" disabled={isSubmitting || (!!staff && !isDirty)}>
          {isSubmitting ? <Loader2 className="animate-spin" aria-hidden /> : <Save aria-hidden />}
          {staff ? "Save changes" : "Create staff member"}
        </Button>
      </div>
    </form>
  );
}
