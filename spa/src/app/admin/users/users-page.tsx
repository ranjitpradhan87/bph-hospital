"use client";

import { ChevronLeft, ChevronRight, MoreHorizontal, Plus, Search, UserCog, Users } from "lucide-react";
import { useDeferredValue, useState } from "react";
import { toast } from "sonner";

import { useAssignableRoles } from "@/app/admin/users/role-picker";
import { CreateUserDialog, EditRolesDialog } from "@/app/admin/users/user-dialogs";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { PageHeader } from "@/components/shared/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { formatDateTime, roleLabel } from "@/lib/format";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { Page, Role, UserAccount } from "@/types/api";

const PAGE_SIZE = 20;

export function UsersPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("users.manage")}>
      <UsersView />
    </RequireAuth>
  );
}

function UsersView() {
  const { user: me } = useAuth();
  const [search, setSearch] = useState("");
  const deferredSearch = useDeferredValue(search.trim());
  const [role, setRole] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);

  const users = useApi<Page<UserAccount>>("/users", {
    search: deferredSearch || undefined,
    role: role || undefined,
    is_active: status === "" ? undefined : status === "active",
    page,
    page_size: PAGE_SIZE,
  });
  const roles = useApi<Role[]>("/roles");
  const assignable = useAssignableRoles(roles.data);

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<UserAccount | null>(null);
  const [toggling, setToggling] = useState<UserAccount | null>(null);

  const data = users.data;
  const pages = data ? Math.max(1, Math.ceil(data.total / PAGE_SIZE)) : 1;
  const resetPage = <T,>(fn: (v: T) => void) => (v: T) => {
    fn(v);
    setPage(1);
  };

  async function toggleStatus(target: UserAccount) {
    try {
      await api(`/users/${target.id}/status`, { method: "PATCH", body: { is_active: !target.is_active } });
      toast.success(`${target.full_name} ${target.is_active ? "deactivated" : "reactivated"}`);
      users.reload();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update status");
      throw err;
    }
  }

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Users"
        description="Staff login accounts and the roles that control what they can do."
        actions={
          <Button onClick={() => setCreateOpen(true)} disabled={!assignable.length}>
            <Plus aria-hidden /> Add user
          </Button>
        }
      />

      <Card className="gap-0 p-0">
        <div className="flex flex-col gap-2 border-b p-3 sm:flex-row">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input
              type="search"
              aria-label="Search users"
              placeholder="Search name, email or mobile"
              className="pl-8"
              value={search}
              onChange={(e) => resetPage(setSearch)(e.target.value)}
            />
          </div>
          <div className="grid grid-cols-2 gap-2 sm:flex">
            <NativeSelect aria-label="Filter by role" value={role} onChange={(e) => resetPage(setRole)(e.target.value)} className="sm:w-44">
              <option value="">All roles</option>
              {roles.data?.map((r) => (
                <option key={r.code} value={r.code}>
                  {r.name}
                </option>
              ))}
            </NativeSelect>
            <NativeSelect aria-label="Filter by status" value={status} onChange={(e) => resetPage(setStatus)(e.target.value)} className="sm:w-36">
              <option value="">Any status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </NativeSelect>
          </div>
        </div>

        {users.error ? (
          <p role="alert" className="p-6 text-sm text-destructive">{users.error.message}</p>
        ) : !data ? (
          <div className="grid gap-3 p-4" aria-busy="true" aria-label="Loading users">
            {Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-10 w-full" />)}
          </div>
        ) : data.items.length === 0 ? (
          <EmptyState
            icon={Users}
            title="No users found"
            description={search || role || status ? "Try a different search or filter." : "Add the first staff account."}
          />
        ) : (
          <div className={cn("transition-opacity", users.loading && "opacity-60")}>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-4">Name</TableHead>
                  <TableHead className="hidden md:table-cell">Mobile</TableHead>
                  <TableHead>Roles</TableHead>
                  <TableHead className="hidden sm:table-cell">Status</TableHead>
                  <TableHead className="hidden lg:table-cell">Last sign-in</TableHead>
                  <TableHead className="w-12 pr-4"><span className="sr-only">Actions</span></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data.items.map((u) => {
                  const self = u.id === me?.id;
                  return (
                    <TableRow key={u.id}>
                      <TableCell className="pl-4">
                        <div className="font-medium">{u.full_name}{self && <span className="ml-1.5 text-xs text-muted-foreground">(you)</span>}</div>
                        <div className="text-xs text-muted-foreground">{u.email}</div>
                        {!u.is_active && <span className="text-xs font-medium text-destructive sm:hidden">Inactive</span>}
                      </TableCell>
                      <TableCell className="hidden md:table-cell">{u.mobile}</TableCell>
                      <TableCell>
                        <div className="flex flex-wrap gap-1">
                          {u.roles.map((r) => (
                            <Badge key={r} variant={r === "super_admin" || r === "admin" ? "default" : "secondary"}>
                              {roles.data?.find((x) => x.code === r)?.name ?? roleLabel(r)}
                            </Badge>
                          ))}
                        </div>
                      </TableCell>
                      <TableCell className="hidden sm:table-cell">
                        <span className={cn("inline-flex items-center gap-1.5 text-sm", u.is_active ? "text-success" : "text-muted-foreground")}>
                          <span className={cn("size-1.5 rounded-full", u.is_active ? "bg-success" : "bg-muted-foreground")} aria-hidden />
                          {u.is_active ? "Active" : "Inactive"}
                        </span>
                      </TableCell>
                      <TableCell className="hidden text-sm text-muted-foreground lg:table-cell">{formatDateTime(u.last_login_at)}</TableCell>
                      <TableCell className="pr-4">
                        {!self && !u.roles.includes("patient") && (
                          <DropdownMenu>
                            <DropdownMenuTrigger render={<Button variant="ghost" size="icon-sm" aria-label={`Actions for ${u.full_name}`} />}>
                              <MoreHorizontal />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem onClick={() => setEditing(u)}>
                                <UserCog aria-hidden /> Edit roles
                              </DropdownMenuItem>
                              <DropdownMenuItem variant={u.is_active ? "destructive" : "default"} onClick={() => setToggling(u)}>
                                {u.is_active ? "Deactivate" : "Reactivate"}
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
            <div className="flex items-center justify-between border-t px-4 py-3 text-sm text-muted-foreground">
              <span>
                {data.total} user{data.total === 1 ? "" : "s"} · page {page} of {pages}
              </span>
              <div className="flex gap-1">
                <Button variant="outline" size="icon-sm" aria-label="Previous page" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                  <ChevronLeft />
                </Button>
                <Button variant="outline" size="icon-sm" aria-label="Next page" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
                  <ChevronRight />
                </Button>
              </div>
            </div>
          </div>
        )}
      </Card>

      <CreateUserDialog open={createOpen} onOpenChange={setCreateOpen} roles={assignable} onCreated={users.reload} />
      <EditRolesDialog user={editing} roles={assignable} onOpenChange={(o) => !o && setEditing(null)} onSaved={users.reload} />
      <ConfirmDialog
        open={!!toggling}
        onOpenChange={(o) => !o && setToggling(null)}
        title={toggling?.is_active ? "Deactivate account?" : "Reactivate account?"}
        description={
          toggling?.is_active
            ? `${toggling.full_name} will be signed out everywhere and won't be able to sign in until reactivated.`
            : `${toggling?.full_name} will be able to sign in again.`
        }
        confirmLabel={toggling?.is_active ? "Deactivate" : "Reactivate"}
        destructive={toggling?.is_active}
        onConfirm={() => toggleStatus(toggling!)}
      />
    </div>
  );
}
