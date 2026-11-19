"use client";

import { Loader2 } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { PermissionChecklist } from "@/app/admin/roles/permission-checklist";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { ApiError, api } from "@/lib/api";
import type { Permission, Role } from "@/types/api";

/** `role` undefined → create; a custom role → edit. Kept closed when `open` is false. */
export function RoleDialog({
  open,
  role,
  permissions,
  onOpenChange,
  onSaved,
}: {
  open: boolean;
  role?: Role;
  permissions: Permission[];
  onOpenChange: (open: boolean) => void;
  onSaved: () => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-3xl">
        {open && (
          <RoleForm key={role?.id ?? "new"} role={role} permissions={permissions} onDone={() => onOpenChange(false)} onSaved={onSaved} />
        )}
      </DialogContent>
    </Dialog>
  );
}

const CODE_RE = /^[a-z][a-z0-9_]{2,39}$/;

function RoleForm({
  role,
  permissions,
  onDone,
  onSaved,
}: {
  role?: Role;
  permissions: Permission[];
  onDone: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(role?.name ?? "");
  const [code, setCode] = useState(role?.code ?? "");
  const [codeEdited, setCodeEdited] = useState(!!role);
  const [description, setDescription] = useState(role?.description ?? "");
  const [selected, setSelected] = useState<string[]>(role?.permissions ?? []);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  function onNameChange(v: string) {
    setName(v);
    if (!codeEdited) setCode(v.toLowerCase().trim().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 40));
  }

  async function save() {
    const next: Record<string, string> = {};
    if (name.trim().length < 2) next.name = "Enter a role name";
    if (!role && !CODE_RE.test(code)) next.code = "3–40 lowercase letters, numbers or _, starting with a letter";
    if (!selected.length) next.permissions = "Select at least one permission";
    setErrors(next);
    if (Object.keys(next).length) return;

    setBusy(true);
    try {
      if (role) {
        await api(`/roles/${role.id}`, { method: "PUT", body: { name, description } });
        await api(`/roles/${role.id}/permissions`, { method: "PUT", body: { permissions: selected } });
      } else {
        await api("/roles", { method: "POST", body: { code, name, description, permissions: selected } });
      }
      toast.success(role ? `${name} updated` : `${name} role created`);
      onSaved();
      onDone();
    } catch (err) {
      setErrors({ form: err instanceof ApiError ? err.message : "Could not save role" });
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{role ? `Edit ${role.name}` : "New custom role"}</DialogTitle>
        <DialogDescription>
          Custom roles bundle permissions for job functions the built-in roles don&apos;t cover, such as lab
          technicians or pharmacists.
        </DialogDescription>
      </DialogHeader>
      {errors.form && <p role="alert" className="text-sm text-destructive">{errors.form}</p>}
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField id="role-name" label="Name" error={errors.name}>
          <Input {...fieldAria("role-name", errors.name)} value={name} onChange={(e) => onNameChange(e.target.value)} />
        </FormField>
        <FormField id="role-code" label="Code" error={errors.code} hint={role ? "Codes can't be changed" : "Used by the API"}>
          <Input
            {...fieldAria("role-code", errors.code, "hint")}
            className="font-mono"
            value={code}
            disabled={!!role}
            onChange={(e) => {
              setCodeEdited(true);
              setCode(e.target.value);
            }}
          />
        </FormField>
        <FormField id="role-desc" label="Description" optional className="sm:col-span-2">
          <Input {...fieldAria("role-desc")} value={description} maxLength={255} onChange={(e) => setDescription(e.target.value)} />
        </FormField>
      </div>
      <div className="grid gap-2">
        <p className="text-sm font-medium">Permissions</p>
        {errors.permissions && <p role="alert" className="text-xs text-destructive">{errors.permissions}</p>}
        <PermissionChecklist permissions={permissions} value={selected} onChange={setSelected} />
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button onClick={save} disabled={busy}>
          {busy && <Loader2 className="animate-spin" aria-hidden />}
          {role ? "Save changes" : "Create role"}
        </Button>
      </DialogFooter>
    </>
  );
}
