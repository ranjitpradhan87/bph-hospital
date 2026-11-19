"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import { useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { RolePicker } from "@/app/admin/users/role-picker";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { ApiError, api } from "@/lib/api";
import { emailSchema, mobileSchema, nameSchema, passwordSchema } from "@/lib/validation";
import type { Role, UserAccount } from "@/types/api";

const createSchema = z.object({
  full_name: nameSchema,
  email: emailSchema,
  mobile: mobileSchema,
  password: passwordSchema,
  roles: z.array(z.string()).min(1, "Select at least one role"),
});
type CreateInput = z.input<typeof createSchema>;
type CreateOutput = z.output<typeof createSchema>;

export function CreateUserDialog({
  open,
  onOpenChange,
  roles,
  onCreated,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  roles: Role[];
  onCreated: () => void;
}) {
  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<CreateInput, unknown, CreateOutput>({
    resolver: zodResolver(createSchema),
    defaultValues: { roles: [] },
  });

  async function onSubmit(values: CreateOutput) {
    try {
      const user = await api<UserAccount>("/users", { method: "POST", body: values });
      toast.success(`${user.full_name} can now sign in`);
      reset();
      onOpenChange(false);
      onCreated();
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fieldErrors).length) {
        for (const [name, message] of Object.entries(err.fieldErrors)) setError(name as keyof CreateInput, { message });
      } else {
        setError("root", { message: err instanceof ApiError ? err.message : "Could not create user" });
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>Add staff account</DialogTitle>
          <DialogDescription>
            Creates a login for a hospital employee. Patients register themselves or at the front desk.
          </DialogDescription>
        </DialogHeader>
        <form id="create-user" onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4 sm:grid-cols-2">
          {errors.root && <p role="alert" className="text-sm text-destructive sm:col-span-2">{errors.root.message}</p>}
          <FormField id="cu-name" label="Full name" error={errors.full_name?.message} className="sm:col-span-2">
            <Input {...fieldAria("cu-name", errors.full_name?.message)} {...register("full_name")} />
          </FormField>
          <FormField id="cu-email" label="Email" error={errors.email?.message}>
            <Input {...fieldAria("cu-email", errors.email?.message)} type="email" autoComplete="off" {...register("email")} />
          </FormField>
          <FormField id="cu-mobile" label="Mobile" error={errors.mobile?.message}>
            <Input {...fieldAria("cu-mobile", errors.mobile?.message)} type="tel" {...register("mobile")} />
          </FormField>
          <FormField
            id="cu-password"
            label="Temporary password"
            error={errors.password?.message}
            hint="Share it securely; they can change it after signing in."
            className="sm:col-span-2"
          >
            <Input
              {...fieldAria("cu-password", errors.password?.message, "hint")}
              type="text"
              autoComplete="new-password"
              {...register("password")}
            />
          </FormField>
          <div className="grid gap-1.5 sm:col-span-2">
            <span className="text-sm font-medium">Roles</span>
            <Controller
              control={control}
              name="roles"
              render={({ field }) => (
                <RolePicker roles={roles} value={field.value} onChange={field.onChange} describedBy="cu-roles-error" />
              )}
            />
            {errors.roles && (
              <p id="cu-roles-error" role="alert" className="text-xs text-destructive">
                {errors.roles.message}
              </p>
            )}
          </div>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="create-user" disabled={isSubmitting}>
            {isSubmitting && <Loader2 className="animate-spin" aria-hidden />}
            Create account
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function EditRolesDialog({
  user,
  roles,
  onOpenChange,
  onSaved,
}: {
  user: UserAccount | null;
  roles: Role[];
  onOpenChange: (open: boolean) => void;
  onSaved: () => void;
}) {
  return (
    <Dialog open={!!user} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl">
        {/* Keyed so the selection resets each time a different user is opened. */}
        {user && <EditRolesForm key={user.id} user={user} roles={roles} onDone={() => onOpenChange(false)} onSaved={onSaved} />}
      </DialogContent>
    </Dialog>
  );
}

function EditRolesForm({
  user,
  roles,
  onDone,
  onSaved,
}: {
  user: UserAccount;
  roles: Role[];
  onDone: () => void;
  onSaved: () => void;
}) {
  const [value, setValue] = useState<string[]>(user.roles);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // Roles the actor can't grant are still shown if the user already holds them.
  const visible = [...roles, ...user.roles.filter((c) => !roles.some((r) => r.code === c)).map((code) => ({
    id: -1, code, name: code, description: "Currently assigned", is_system: true, permissions: [], user_count: 0,
  }))];

  async function save() {
    if (!value.length) return setError("Select at least one role");
    setBusy(true);
    setError(null);
    try {
      await api(`/users/${user.id}/roles`, { method: "PUT", body: { roles: value } });
      toast.success(`Roles updated for ${user.full_name}`);
      onSaved();
      onDone();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update roles");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Edit roles</DialogTitle>
        <DialogDescription>{user.full_name} · {user.email}</DialogDescription>
      </DialogHeader>
      {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <RolePicker roles={visible} value={value} onChange={setValue} />
      <DialogFooter>
        <Button variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button onClick={save} disabled={busy}>
          {busy && <Loader2 className="animate-spin" aria-hidden />}
          Save roles
        </Button>
      </DialogFooter>
    </>
  );
}
