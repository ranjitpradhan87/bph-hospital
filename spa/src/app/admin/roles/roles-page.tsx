"use client";

import { ChevronDown, Lock, Pencil, Plus, ShieldCheck, Trash2 } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";

import { groupByModule, moduleLabel } from "@/app/admin/roles/permission-checklist";
import { RoleDialog } from "@/app/admin/roles/role-dialog";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { PageHeader } from "@/components/shared/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { ApiError, api } from "@/lib/api";
import { RequireAuth } from "@/lib/auth/require-auth";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Permission, Role } from "@/types/api";

export function RolesPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("roles.manage")}>
      <RolesView />
    </RequireAuth>
  );
}

function RolesView() {
  const roles = useApi<Role[]>("/roles");
  const permissions = useApi<Permission[]>("/permissions");
  const [expanded, setExpanded] = useState<number | null>(null);
  const [dialog, setDialog] = useState<{ open: boolean; role?: Role }>({ open: false });
  const [deleting, setDeleting] = useState<Role | null>(null);

  const permsByCode = new Map(permissions.data?.map((p) => [p.code, p]));

  async function remove(role: Role) {
    try {
      await api(`/roles/${role.id}`, { method: "DELETE" });
      toast.success(`${role.name} deleted`);
      roles.reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not delete role");
      throw err;
    }
  }

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader
        title="Roles & permissions"
        description="Built-in roles are fixed by the application. Create custom roles for other job functions."
        actions={
          <Button onClick={() => setDialog({ open: true })} disabled={!permissions.data}>
            <Plus aria-hidden /> New role
          </Button>
        }
      />

      {roles.error && <p role="alert" className="text-sm text-destructive">{roles.error.message}</p>}
      <div className="grid gap-3">
        {!roles.data
          ? Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-20 w-full" />)
          : roles.data.map((role) => {
              const open = expanded === role.id;
              const rolePerms = role.permissions.map((c) => permsByCode.get(c)).filter((p): p is Permission => !!p);
              return (
                <Card key={role.id} className="gap-0 py-0">
                  <CardHeader className="flex flex-row items-center gap-3 py-4">
                    <div className={cn("rounded-lg p-2", role.is_system ? "bg-primary/10 text-primary" : "bg-teal/10 text-teal")}>
                      {role.is_system ? <Lock className="size-4" aria-hidden /> : <ShieldCheck className="size-4" aria-hidden />}
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-medium">{role.name}</h2>
                        <code className="text-xs text-muted-foreground">{role.code}</code>
                        <Badge variant={role.is_system ? "secondary" : "outline"}>{role.is_system ? "Built-in" : "Custom"}</Badge>
                      </div>
                      <p className="truncate text-sm text-muted-foreground">
                        {role.description || "—"} · {role.permissions.length} permissions · {role.user_count} user
                        {role.user_count === 1 ? "" : "s"}
                      </p>
                    </div>
                    {!role.is_system && (
                      <>
                        <Button variant="ghost" size="icon-sm" aria-label={`Edit ${role.name}`} onClick={() => setDialog({ open: true, role })}>
                          <Pencil />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          aria-label={`Delete ${role.name}`}
                          onClick={() => setDeleting(role)}
                        >
                          <Trash2 />
                        </Button>
                      </>
                    )}
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      aria-expanded={open}
                      aria-controls={`role-${role.id}-perms`}
                      aria-label={open ? "Hide permissions" : "Show permissions"}
                      onClick={() => setExpanded(open ? null : role.id)}
                    >
                      <ChevronDown className={cn("transition-transform", open && "rotate-180")} />
                    </Button>
                  </CardHeader>
                  {open && (
                    <CardContent id={`role-${role.id}-perms`} className="grid gap-3 border-t py-4 sm:grid-cols-2">
                      {groupByModule(rolePerms).map(([module, perms]) => (
                        <div key={module}>
                          <p className="mb-1 text-sm font-medium">{moduleLabel(module)}</p>
                          <ul className="grid gap-0.5 text-sm text-muted-foreground">
                            {perms.map((p) => (
                              <li key={p.code}>{p.description}</li>
                            ))}
                          </ul>
                        </div>
                      ))}
                    </CardContent>
                  )}
                </Card>
              );
            })}
      </div>

      <RoleDialog
        open={dialog.open}
        role={dialog.role}
        permissions={permissions.data ?? []}
        onOpenChange={(o) => setDialog((d) => ({ ...d, open: o }))}
        onSaved={roles.reload}
      />
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title={`Delete ${deleting?.name}?`}
        description={
          deleting?.user_count
            ? `This role is assigned to ${deleting.user_count} user(s). Remove it from them first.`
            : "This can't be undone."
        }
        confirmLabel="Delete role"
        destructive
        onConfirm={() => remove(deleting!)}
      />
    </div>
  );
}
