"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Building2, Loader2, MoreHorizontal, Pencil, Plus, Power, Trash2 } from "lucide-react";
import { useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { refreshPublicContent } from "@/app/admin/settings/actions";
import { CONTENT_ICON_NAMES, ContentIcon } from "@/components/public/content-icon";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { EmptyState } from "@/components/shared/empty-state";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { ImageField } from "@/components/shared/image-field";
import { PageHeader } from "@/components/shared/page-header";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api, getAccessToken } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { RequireAuth } from "@/lib/auth/require-auth";
import { applyApiError, blankToNull } from "@/lib/forms";
import { useApi } from "@/lib/use-api";
import { cn } from "@/lib/utils";
import type { DepartmentAdmin } from "@/types/management";

export function DepartmentsPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.some((p) => p === "departments.read" || p === "departments.manage")}>
      <DepartmentsView />
    </RequireAuth>
  );
}

function DepartmentsView() {
  const { can } = useAuth();
  const canManage = can("departments.manage");
  const list = useApi<DepartmentAdmin[]>("/departments");
  const [editing, setEditing] = useState<DepartmentAdmin | "new" | null>(null);
  const [deleting, setDeleting] = useState<DepartmentAdmin | null>(null);

  const changed = () => {
    list.reload();
    refreshPublicContent(getAccessToken());
  };

  async function toggle(d: DepartmentAdmin) {
    try {
      await api(`/departments/${d.id}/status`, { method: "PATCH", body: { is_active: !d.is_active } });
      toast.success(`${d.name} ${d.is_active ? "hidden from" : "shown on"} the website`);
      changed();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not update");
    }
  }

  async function remove(d: DepartmentAdmin) {
    try {
      await api(`/departments/${d.id}`, { method: "DELETE" });
      toast.success(`${d.name} deleted`);
      changed();
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Could not delete");
      throw err;
    }
  }

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader
        title="Departments"
        description="Departments appear on the website and group doctors and staff."
        actions={canManage && <Button onClick={() => setEditing("new")}><Plus aria-hidden /> Add department</Button>}
      />
      <Card className="gap-0 p-0">
        {list.error ? (
          <p role="alert" className="p-6 text-sm text-destructive">{list.error.message}</p>
        ) : !list.data ? (
          <div className="grid gap-3 p-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-12" />)}</div>
        ) : list.data.length === 0 ? (
          <EmptyState icon={Building2} title="No departments yet" />
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="pl-4">Department</TableHead>
                <TableHead className="hidden md:table-cell">Doctors</TableHead>
                <TableHead className="hidden md:table-cell">Staff</TableHead>
                <TableHead>Website</TableHead>
                {canManage && <TableHead className="w-12 pr-4"><span className="sr-only">Actions</span></TableHead>}
              </TableRow>
            </TableHeader>
            <TableBody>
              {list.data.map((d) => (
                <TableRow key={d.id}>
                  <TableCell className="pl-4">
                    <div className="flex items-center gap-3">
                      <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <ContentIcon icon={d.icon} className="size-4" />
                      </span>
                      <div className="min-w-0">
                        <p className="font-medium">{d.name}</p>
                        <p className="max-w-md truncate text-xs text-muted-foreground">{d.short_description || `/${d.slug}`}</p>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="hidden tabular-nums md:table-cell">{d.doctor_count}</TableCell>
                  <TableCell className="hidden tabular-nums md:table-cell">{d.staff_count}</TableCell>
                  <TableCell>
                    <span className={cn("inline-flex items-center gap-1.5 text-sm", d.is_active ? "text-success" : "text-muted-foreground")}>
                      <span className={cn("size-1.5 rounded-full", d.is_active ? "bg-success" : "bg-muted-foreground")} aria-hidden />
                      {d.is_active ? "Shown" : "Hidden"}
                    </span>
                  </TableCell>
                  {canManage && (
                    <TableCell className="pr-4">
                      <DropdownMenu>
                        <DropdownMenuTrigger render={<Button variant="ghost" size="icon-sm" aria-label={`Actions for ${d.name}`} />}>
                          <MoreHorizontal />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onClick={() => setEditing(d)}><Pencil aria-hidden /> Edit</DropdownMenuItem>
                          <DropdownMenuItem onClick={() => toggle(d)}><Power aria-hidden /> {d.is_active ? "Hide from website" : "Show on website"}</DropdownMenuItem>
                          <DropdownMenuItem variant="destructive" onClick={() => setDeleting(d)}><Trash2 aria-hidden /> Delete</DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Card>

      <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
          {editing !== null && (
            <DepartmentForm
              key={editing === "new" ? "new" : editing.id}
              department={editing === "new" ? null : editing}
              onDone={() => setEditing(null)}
              onSaved={changed}
            />
          )}
        </DialogContent>
      </Dialog>
      <ConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title={`Delete ${deleting?.name}?`}
        description="Only possible when no doctors, staff or upcoming appointments belong to it. Past records keep their history."
        confirmLabel="Delete"
        destructive
        onConfirm={() => remove(deleting!)}
      />
    </div>
  );
}

const schema = z.object({
  name: z.string().trim().min(2, "Enter a name").max(100),
  slug: z.string().trim().max(120).regex(/^([a-z0-9]+(-[a-z0-9]+)*)?$/, "Lowercase letters, numbers and hyphens only"),
  short_description: z.string().trim().max(255),
  description: z.string().trim().max(5000),
  icon: z.string(),
  image_url: z.string().nullable(),
  display_order: z.coerce.number().int().min(0).max(999),
  is_active: z.boolean(),
});
type In = z.input<typeof schema>;
type Out = z.output<typeof schema>;
const FIELDS = ["name", "slug", "short_description", "description", "icon", "image_url", "display_order", "is_active"] as const;

function DepartmentForm({ department, onDone, onSaved }: { department: DepartmentAdmin | null; onDone: () => void; onSaved: () => void }) {
  const { register, control, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<In, unknown, Out>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: department?.name ?? "",
      slug: department?.slug ?? "",
      short_description: department?.short_description ?? "",
      description: department?.description ?? "",
      icon: department?.icon ?? "stethoscope",
      image_url: department?.image_url ?? null,
      display_order: department?.display_order ?? 0,
      is_active: department?.is_active ?? true,
    },
  });
  const icon = useWatch({ control, name: "icon" });

  async function onSubmit(values: Out) {
    try {
      const body = blankToNull({ ...values });
      await api(department ? `/departments/${department.id}` : "/departments", { method: department ? "PUT" : "POST", body });
      toast.success(department ? "Department updated" : "Department added");
      onSaved();
      onDone();
    } catch (err) {
      applyApiError(err, setError, FIELDS);
    }
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4">
      <DialogHeader>
        <DialogTitle>{department ? `Edit ${department.name}` : "Add department"}</DialogTitle>
        <DialogDescription>Shown on the public website while active.</DialogDescription>
      </DialogHeader>
      {errors.root && <p role="alert" className="text-sm text-destructive">{errors.root.message}</p>}
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField id="d-name" label="Name" error={errors.name?.message}>
          <Input {...fieldAria("d-name", errors.name?.message)} {...register("name")} />
        </FormField>
        <FormField id="d-slug" label="Web address" optional error={errors.slug?.message} hint="Leave blank to generate from the name">
          <Input {...fieldAria("d-slug", errors.slug?.message, "hint")} className="font-mono" placeholder="e.g. cardiology" {...register("slug")} />
        </FormField>
        <FormField id="d-short" label="Short description" optional error={errors.short_description?.message} className="sm:col-span-2">
          <Input {...fieldAria("d-short", errors.short_description?.message)} {...register("short_description")} />
        </FormField>
        <FormField id="d-desc" label="Full description" optional error={errors.description?.message} className="sm:col-span-2" hint="Shown on the department page. Leave a blank line between paragraphs.">
          <Textarea {...fieldAria("d-desc", errors.description?.message, "hint")} rows={4} {...register("description")} />
        </FormField>
        <FormField id="d-icon" label="Icon" error={errors.icon?.message}>
          <div className="flex items-center gap-2">
            <span className="grid size-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary"><ContentIcon icon={icon} className="size-4" /></span>
            <NativeSelect {...fieldAria("d-icon", errors.icon?.message)} {...register("icon")}>
              {CONTENT_ICON_NAMES.map((n) => <option key={n} value={n}>{n.replace(/-/g, " ")}</option>)}
            </NativeSelect>
          </div>
        </FormField>
        <FormField id="d-order" label="Display order" error={errors.display_order?.message} hint="Lower numbers come first">
          <Input {...fieldAria("d-order", errors.display_order?.message, "hint")} type="number" min={0} className="w-28" {...register("display_order")} />
        </FormField>
        <div className="sm:col-span-2">
          <Controller control={control} name="image_url" render={({ field }) => (
            <ImageField label="Department image" value={field.value} onChange={field.onChange} error={errors.image_url?.message} />
          )} />
        </div>
        <Controller control={control} name="is_active" render={({ field }) => (
          <label className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm sm:col-span-2">
            <span><span className="font-medium">Show on website</span><span className="block text-xs text-muted-foreground">Hidden departments keep their doctors and history.</span></span>
            <Switch checked={field.value} onCheckedChange={field.onChange} />
          </label>
        )} />
      </div>
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onDone}>Cancel</Button>
        <Button type="submit" disabled={isSubmitting}>{isSubmitting && <Loader2 className="animate-spin" aria-hidden />} Save</Button>
      </DialogFooter>
    </form>
  );
}
