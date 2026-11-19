"use client";

import { ImageUp, Loader2, Trash2 } from "lucide-react";
import { useId, useRef, useState } from "react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { ApiError, api } from "@/lib/api";
import { mediaUrl } from "@/lib/media";
import { cn } from "@/lib/utils";

/** Upload-or-remove control for a public image URL (logo, department image, photos). */
export function ImageField({ label, value, onChange, hint, round, error }: {
  label: string;
  value: string | null;
  onChange: (url: string | null) => void;
  hint?: string;
  round?: boolean;
  error?: string;
}) {
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  async function upload(file: File) {
    setBusy(true);
    try {
      const form = new FormData();
      form.append("file", file);
      onChange((await api<{ url: string }>("/uploads/images", { method: "POST", body: form })).url);
    } catch (err) {
      toast.error(err instanceof ApiError ? err.message : "Upload failed");
    } finally {
      setBusy(false);
      if (input.current) input.current.value = "";
    }
  }

  return (
    <div className="grid gap-1.5">
      <span className="text-sm font-medium">{label}</span>
      <div className="flex flex-wrap items-center gap-4">
        <div className={cn("grid size-20 shrink-0 place-items-center overflow-hidden border bg-muted/40", round ? "rounded-full" : "rounded-lg")}>
          {value ? (
            // eslint-disable-next-line @next/next/no-img-element -- uploaded media served by the API
            <img src={mediaUrl(value) ?? undefined} alt={`Current ${label.toLowerCase()}`} className="size-full object-cover" />
          ) : (
            <span className="text-xs text-muted-foreground">None</span>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          <input ref={input} id={id} type="file" accept="image/png,image/jpeg,image/webp" className="sr-only"
                 onChange={(e) => e.target.files?.[0] && upload(e.target.files[0])} />
          <Button type="button" variant="outline" size="sm" onClick={() => input.current?.click()} disabled={busy}>
            {busy ? <Loader2 className="animate-spin" aria-hidden /> : <ImageUp aria-hidden />} {value ? "Replace" : "Upload"}
          </Button>
          {value && (
            <Button type="button" variant="ghost" size="sm" onClick={() => onChange(null)}>
              <Trash2 aria-hidden /> Remove
            </Button>
          )}
        </div>
      </div>
      {error ? <p role="alert" className="text-xs text-destructive">{error}</p>
        : <p className="text-xs text-muted-foreground">{hint ?? "PNG, JPEG or WebP, up to 5 MB."}</p>}
    </div>
  );
}
