"use client";

import { Checkbox } from "@/components/ui/checkbox";
import type { Permission } from "@/types/api";

export function groupByModule(perms: Permission[]): [string, Permission[]][] {
  const groups = new Map<string, Permission[]>();
  for (const p of perms) groups.set(p.module, [...(groups.get(p.module) ?? []), p]);
  return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
}

export function moduleLabel(module: string) {
  return module.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

export function PermissionChecklist({
  permissions,
  value,
  onChange,
}: {
  permissions: Permission[];
  value: string[];
  onChange: (codes: string[]) => void;
}) {
  const selected = new Set(value);
  const toggle = (codes: string[], on: boolean) => {
    const next = new Set(selected);
    for (const c of codes) {
      if (on) next.add(c);
      else next.delete(c);
    }
    onChange([...next].sort());
  };

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {groupByModule(permissions).map(([module, perms]) => {
        const codes = perms.map((p) => p.code);
        const count = codes.filter((c) => selected.has(c)).length;
        return (
          <fieldset key={module} className="rounded-lg border p-3">
            <legend className="sr-only">{moduleLabel(module)}</legend>
            <label className="mb-2 flex cursor-pointer items-center gap-2 text-sm font-semibold">
              <Checkbox
                checked={count === codes.length}
                indeterminate={count > 0 && count < codes.length}
                onCheckedChange={(on) => toggle(codes, !!on)}
              />
              {moduleLabel(module)}
              <span className="ml-auto text-xs font-normal text-muted-foreground">
                {count}/{codes.length}
              </span>
            </label>
            <div className="grid gap-1.5 pl-6">
              {perms.map((p) => (
                <label key={p.code} className="flex cursor-pointer items-start gap-2 text-sm">
                  <Checkbox className="mt-0.5" checked={selected.has(p.code)} onCheckedChange={(on) => toggle([p.code], !!on)} />
                  <span>
                    {p.description}
                    <span className="block font-mono text-[11px] text-muted-foreground">{p.code}</span>
                  </span>
                </label>
              ))}
            </div>
          </fieldset>
        );
      })}
    </div>
  );
}
