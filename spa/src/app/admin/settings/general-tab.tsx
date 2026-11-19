"use client";

import { ImageSetting, Section, SaveBar, TextSetting, fid } from "@/app/admin/settings/fields";
import { type Values, useSettingsDraft } from "@/app/admin/settings/use-settings-draft";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Input } from "@/components/ui/input";
import { DAY_NAMES, DAY_SHORT } from "@/lib/public-format";
import { cn } from "@/lib/utils";
import type { SettingRow } from "@/types/api";

const KEYS = [
  "hospital.name", "hospital.tagline", "hospital.logo_url", "hospital.phone", "hospital.emergency_phone",
  "hospital.email", "hospital.address", "hospital.map_embed_url", "hospital.working_days", "hospital.working_hours",
] as const;

type Address = { line1: string; line2: string; city: string; state: string; pincode: string; country: string };
type Hours = { start: string; end: string };

export function GeneralTab({ values, onSaved }: { values: Values; onSaved: (rows: SettingRow[]) => void }) {
  const draft = useSettingsDraft(values, KEYS, onSaved);
  const address = draft.draft["hospital.address"] as Address;
  const days = (draft.draft["hospital.working_days"] as number[]) ?? [];
  const hours = draft.draft["hospital.working_hours"] as Hours;
  const setAddress = (field: keyof Address, v: string) => draft.set("hospital.address", { ...address, [field]: v });

  return (
    <div className="grid gap-4">
      <Section title="Identity" description="Shown in the website header, footer, invoices and emails.">
        <TextSetting draft={draft} path="hospital.name" label="Hospital name" />
        <TextSetting draft={draft} path="hospital.tagline" label="Tagline" />
        <ImageSetting draft={draft} path="hospital.logo_url" label="Logo" hint="Shown in the website header instead of the name. A wide PNG with a transparent background works best." />
      </Section>

      <Section title="Contact" description="Phone numbers become tap-to-call links on mobile.">
        <TextSetting draft={draft} path="hospital.phone" label="Appointments & enquiries phone" type="tel" placeholder="080 4000 1234" />
        <TextSetting draft={draft} path="hospital.emergency_phone" label="Emergency phone (24×7)" type="tel" />
        <TextSetting draft={draft} path="hospital.email" label="Public email" type="email" wide />
      </Section>

      <Section title="Address & map">
        <TextSetting draft={draft} path="hospital.address.line1" label="Address line 1" wide value={address.line1} onChange={(v) => setAddress("line1", v)} />
        <TextSetting draft={draft} path="hospital.address.line2" label="Address line 2" wide value={address.line2} onChange={(v) => setAddress("line2", v)} />
        <TextSetting draft={draft} path="hospital.address.city" label="City" value={address.city} onChange={(v) => setAddress("city", v)} />
        <TextSetting draft={draft} path="hospital.address.state" label="State" value={address.state} onChange={(v) => setAddress("state", v)} />
        <TextSetting draft={draft} path="hospital.address.pincode" label="PIN code" value={address.pincode} onChange={(v) => setAddress("pincode", v)} />
        <FormField id="s-country" label="Country">
          <Input id="s-country" value={address.country} disabled />
        </FormField>
        <TextSetting
          draft={draft}
          path="hospital.map_embed_url"
          label="Google Maps embed link"
          wide
          hint={'Optional. In Google Maps: Share → Embed a map → copy the link inside src="…". Without it, the map is found from the address.'}
        />
      </Section>

      <Section title="OPD hours" description="Shown on the website. Doctor-specific hours are set in their schedules (Phase 6).">
        <div className="grid gap-1.5 sm:col-span-2">
          <span id="days-label" className="text-sm font-medium">Working days</span>
          <div role="group" aria-labelledby="days-label" className="flex flex-wrap gap-2">
            {DAY_SHORT.map((label, day) => {
              const on = days.includes(day);
              return (
                <button
                  key={day}
                  type="button"
                  aria-pressed={on}
                  aria-label={DAY_NAMES[day]}
                  onClick={() => draft.set("hospital.working_days", on ? days.filter((d) => d !== day) : [...days, day].sort())}
                  className={cn(
                    "h-9 min-w-14 rounded-lg border px-3 text-sm font-medium transition-colors",
                    on ? "border-primary bg-primary text-primary-foreground" : "bg-background hover:border-primary/50",
                  )}
                >
                  {label}
                </button>
              );
            })}
          </div>
          {draft.error("hospital.working_days") && (
            <p role="alert" className="text-xs text-destructive">{draft.error("hospital.working_days")}</p>
          )}
        </div>
        {(["start", "end"] as const).map((field) => {
          const path = `hospital.working_hours.${field}`;
          const error = field === "end" ? draft.error("hospital.working_hours") : draft.error(path);
          return (
            <FormField key={field} id={fid(path)} label={field === "start" ? "Opens at" : "Closes at"} error={error}>
              <Input
                {...fieldAria(fid(path), error)}
                type="time"
                className="w-36"
                value={hours[field]}
                onChange={(e) => draft.set("hospital.working_hours", { ...hours, [field]: e.target.value })}
              />
            </FormField>
          );
        })}
      </Section>

      <SaveBar draft={draft} />
    </div>
  );
}
