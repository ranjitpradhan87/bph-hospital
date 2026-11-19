"use client";

import { Checkbox } from "@/components/ui/checkbox";
import { useAuth } from "@/lib/auth/auth-provider";
import type { Role } from "@/types/api";

/** Roles the current user may grant — mirrors the API's no-escalation rule. */
export function useAssignableRoles(roles: Role[] | undefined): Role[] {
  const { user } = useAuth();
  const held = new Set(user?.permissions ?? []);
  return (roles ?? []).filter(
    (r) =>
      r.code !== "patient" &&
      (held.has("roles.manage") || !["super_admin", "admin"].includes(r.code)) &&
      r.permissions.every((p) => held.has(p)),
  );
}

export function RolePicker({
  roles,
  value,
  onChange,
  describedBy,
}: {
  roles: Role[];
  value: string[];
  onChange: (codes: string[]) => void;
  describedBy?: string;
}) {
  return (
    <div role="group" aria-label="Roles" aria-describedby={describedBy} className="grid gap-2 sm:grid-cols-2">
      {roles.map((role) => {
        const checked = value.includes(role.code);
        return (
          <label
            key={role.code}
            className="flex cursor-pointer items-start gap-2.5 rounded-lg border p-2.5 text-sm has-data-checked:border-primary has-data-checked:bg-primary/5"
          >
            <Checkbox
              className="mt-0.5"
              checked={checked}
              onCheckedChange={(c) => onChange(c ? [...value, role.code] : value.filter((v) => v !== role.code))}
            />
            <span className="grid gap-0.5">
              <span className="font-medium">{role.name}</span>
              <span className="text-xs text-muted-foreground">{role.description || `${role.permissions.length} permissions`}</span>
            </span>
          </label>
        );
      })}
    </div>
  );
}
