"use client";

import { useState } from "react";
import { toast } from "sonner";

import { refreshPublicContent } from "@/app/admin/settings/actions";
import { ApiError, api, getAccessToken } from "@/lib/api";
import type { SettingRow } from "@/types/api";

export type Values = Record<string, unknown>;

/**
 * Local draft for a group of settings. `save()` sends only changed keys; a 422 from the API is
 * mapped to field paths like "hospital.address.pincode" or "content.services.2.title".
 */
export function useSettingsDraft(all: Values, keys: readonly string[], onSaved: (rows: SettingRow[]) => void) {
  const pick = () => Object.fromEntries(keys.map((k) => [k, all[k]]));
  const [draft, setDraft] = useState<Values>(pick);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  const changed = keys.filter((k) => JSON.stringify(draft[k]) !== JSON.stringify(all[k]));

  function set(key: string, value: unknown) {
    setDraft((d) => ({ ...d, [key]: value }));
    // Clear errors for this setting once it's edited.
    setErrors((e) => Object.fromEntries(Object.entries(e).filter(([path]) => path !== key && !path.startsWith(`${key}.`))));
  }

  async function save() {
    if (!changed.length) return;
    setSaving(true);
    try {
      const rows = await api<SettingRow[]>("/settings", {
        method: "PUT",
        body: { values: Object.fromEntries(changed.map((k) => [k, draft[k]])) },
      });
      onSaved(rows);
      const fresh = Object.fromEntries(rows.map((r) => [r.key, r.value]));
      setDraft(Object.fromEntries(keys.map((k) => [k, fresh[k]]))); // take normalised values back
      setErrors({});
      const published = rows.some((r) => r.public && changed.includes(r.key));
      if (published) await refreshPublicContent(getAccessToken());
      toast.success(published ? "Saved — the website is updated" : "Settings saved");
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && Array.isArray(err.detail)) {
        const next: Record<string, string> = {};
        for (const e of err.detail as { loc: (string | number)[]; msg: string }[]) {
          next[e.loc.slice(1).join(".")] = e.msg.replace(/^Value error, /, "");
        }
        setErrors(next);
        toast.error("Please fix the highlighted fields");
      } else {
        toast.error(err instanceof ApiError ? err.message : "Could not save settings");
      }
    } finally {
      setSaving(false);
    }
  }

  /** First error at or under `path`. */
  function error(path: string): string | undefined {
    return errors[path] ?? Object.entries(errors).find(([p]) => p.startsWith(`${path}.`))?.[1];
  }

  return { draft, set, save, saving, changed, error, reset: () => { setDraft(pick()); setErrors({}); } };
}

export type Draft = ReturnType<typeof useSettingsDraft>;
