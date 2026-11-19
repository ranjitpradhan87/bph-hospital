"use client";

import { ImageUp, Loader2, Save, Trash2, Undo2 } from "lucide-react";
import { useRef, useState } from "react";
import { toast } from "sonner";

import type { Draft } from "@/app/admin/settings/use-settings-draft";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { mediaUrl } from "@/lib/media";

/** Path → DOM id, e.g. "hospital.address.city" → "s-hospital-address-city". */
export const fid = (path: string) => `s-${path.replace(/[^a-z0-9]+/gi, "-")}`;

export function Section({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent className="grid gap-4 sm:grid-cols-2">{children}</CardContent>
    </Card>
  );
}

export function TextSetting({
  draft, path, label, hint, multiline, wide, type = "text", placeholder, value, onChange,
}: {
  draft: Draft;
  path: string;
  label: string;
  hint?: string;
  multiline?: boolean;
  wide?: boolean;
  type?: string;
  placeholder?: string;
  /** For nested values; defaults to draft.draft[path]. */
  value?: string;
  onChange?: (v: string) => void;
}) {
  const id = fid(path);
  const error = draft.error(path);
  const current = value ?? ((draft.draft[path] as string | null) ?? "");
  const change = onChange ?? ((v: string) => draft.set(path, v));
  const aria = fieldAria(id, error, hint);
  return (
    <FormField id={id} label={label} error={error} hint={hint} className={wide ? "sm:col-span-2" : undefined}>
      {multiline ? (
        <Textarea {...aria} rows={3} value={current} placeholder={placeholder} onChange={(e) => change(e.target.value)} />
      ) : (
        <Input {...aria} type={type} value={current} placeholder={placeholder} onChange={(e) => change(e.target.value)} />
      )}
    </FormField>
  );
}

export function NumberSetting({ draft, path, label, hint, min, max, suffix }: {
  draft: Draft; path: string; label: string; hint?: string; min?: number; max?: number; suffix?: string;
}) {
  const id = fid(path);
  const error = draft.error(path);
  const raw = draft.draft[path];
  return (
    <FormField id={id} label={label} error={error} hint={hint}>
      <div className="flex items-center gap-2">
        <Input
          {...fieldAria(id, error, hint)}
          type="number"
          inputMode="numeric"
          min={min}
          max={max}
          className="w-32"
          value={raw === "" || raw === null || raw === undefined ? "" : String(raw)}
          onChange={(e) => draft.set(path, e.target.value === "" ? "" : Number(e.target.value))}
        />
        {suffix && <span className="text-sm text-muted-foreground">{suffix}</span>}
      </div>
    </FormField>
  );
}

export function SwitchSetting({ draft, path, label, description, disabled, disabledReason }: {
  draft: Draft; path: string; label: string; description?: string; disabled?: boolean; disabledReason?: string;
}) {
  const id = fid(path);
  return (
    <div className="flex items-start justify-between gap-4 rounded-lg border p-3 sm:col-span-2">
      <div className="grid gap-0.5">
        <Label htmlFor={id}>{label}</Label>
        {description && <p className="text-xs text-muted-foreground">{description}</p>}
        {disabled && disabledReason && <p className="text-xs font-medium text-amber-700 dark:text-warning">{disabledReason}</p>}
      </div>
      <Switch id={id} checked={Boolean(draft.draft[path])} disabled={disabled} onCheckedChange={(v) => draft.set(path, v)} />
    </div>
  );
}

export function ImageSetting({ draft, path, label, hint }: { draft: Draft; path: string; label: string; hint?: string }) {
  const input = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const url = draft.draft[path] as string | null;
  const error = draft.error(path);

  async function upload(file: File) {
    setUploading(true);
    try {
      const form = new FormData();
      form.append("file", file);
      const res = await api<{ url: string }>("/uploads/images", { method: "POST", body: form });
      draft.set(path, res.url);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Upload failed");
    } finally {
      setUploading(false);
      if (input.current) input.current.value = "";
    }
  }

  return (
    <div className="grid gap-1.5 sm:col-span-2">
      <span className="text-sm font-medium">{label}</span>
      <div className="flex flex-wrap items-center gap-4">
        <div className="grid h-24 w-40 place-items-center overflow-hidden rounded-lg border bg-muted/40">
          {url ? (
            // eslint-disable-next-line @next/next/no-img-element -- uploaded media served by the API
            <img src={mediaUrl(url) ?? undefined} alt={`Current ${label.toLowerCase()}`} className="size-full object-contain" />
          ) : (
            <span className="text-xs text-muted-foreground">None</span>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          <input
            ref={input}
            type="file"
            accept="image/png,image/jpeg,image/webp"
            className="sr-only"
            id={fid(path)}
            onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])}
          />
          <Button variant="outline" onClick={() => input.current?.click()} disabled={uploading}>
            {uploading ? <Loader2 className="animate-spin" aria-hidden /> : <ImageUp aria-hidden />}
            {url ? "Replace" : "Upload"}
          </Button>
          {url && (
            <Button variant="ghost" onClick={() => draft.set(path, null)}>
              <Trash2 aria-hidden /> Remove
            </Button>
          )}
        </div>
      </div>
      {error ? (
        <p role="alert" className="text-xs text-destructive">{error}</p>
      ) : (
        <p className="text-xs text-muted-foreground">{hint ?? "PNG, JPEG or WebP, up to 5 MB."}</p>
      )}
    </div>
  );
}

/** Sticky save bar shown while a tab has unsaved changes. */
export function SaveBar({ draft }: { draft: Draft }) {
  if (!draft.changed.length) return null;
  return (
    <div className="sticky bottom-4 z-30 flex items-center justify-between gap-3 rounded-xl border bg-background/95 p-3 shadow-lg backdrop-blur">
      <p className="text-sm text-muted-foreground">
        {draft.changed.length} unsaved change{draft.changed.length === 1 ? "" : "s"}
      </p>
      <div className="flex gap-2">
        <Button variant="ghost" onClick={draft.reset} disabled={draft.saving}>
          <Undo2 aria-hidden /> Discard
        </Button>
        <Button onClick={draft.save} disabled={draft.saving}>
          {draft.saving ? <Loader2 className="animate-spin" aria-hidden /> : <Save aria-hidden />} Save changes
        </Button>
      </div>
    </div>
  );
}
