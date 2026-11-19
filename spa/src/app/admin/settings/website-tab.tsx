"use client";

import { ArrowDown, ArrowUp, Plus, Trash2 } from "lucide-react";

import { ImageSetting, Section, SaveBar, TextSetting, fid } from "@/app/admin/settings/fields";
import { type Draft, type Values, useSettingsDraft } from "@/app/admin/settings/use-settings-draft";
import { ContentIcon } from "@/components/public/content-icon";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import type { SettingRow } from "@/types/api";

const KEYS = [
  "content.hero", "hospital.hero_image_url", "content.about",
  "content.services", "content.facilities", "content.testimonials",
] as const;

// Must match CONTENT_ICONS in backend/app/core/settings_registry.py.
const ICONS = [
  "stethoscope", "heart-pulse", "brain", "bone", "baby", "scan-face", "venus", "smile", "siren", "microscope",
  "scan", "pill", "video", "bed-double", "scissors", "ambulance", "accessibility", "credit-card",
];
const MAX_ITEMS = 12;

type Hero = { title: string; subtitle: string };
type About = { summary: string; mission: string; vision: string; values: string[] };
type Item = { icon: string; title: string; description: string };
type Testimonial = { name: string; quote: string; context: string };

export function WebsiteTab({ values, onSaved }: { values: Values; onSaved: (rows: SettingRow[]) => void }) {
  const draft = useSettingsDraft(values, KEYS, onSaved);
  const hero = draft.draft["content.hero"] as Hero;
  const about = draft.draft["content.about"] as About;

  return (
    <div className="grid gap-4">
      <Section title="Home page hero" description="The first thing visitors see.">
        <TextSetting draft={draft} path="content.hero.title" label="Headline" wide value={hero.title} onChange={(v) => draft.set("content.hero", { ...hero, title: v })} />
        <TextSetting draft={draft} path="content.hero.subtitle" label="Supporting text" wide multiline value={hero.subtitle} onChange={(v) => draft.set("content.hero", { ...hero, subtitle: v })} />
        <ImageSetting draft={draft} path="hospital.hero_image_url" label="Hero photo" hint="Optional. A landscape photo of the hospital (4:3). Without one, an illustration is shown." />
      </Section>

      <Section title="About us">
        <TextSetting draft={draft} path="content.about.summary" label="Summary" wide multiline value={about.summary} onChange={(v) => draft.set("content.about", { ...about, summary: v })} />
        <TextSetting draft={draft} path="content.about.mission" label="Mission" multiline value={about.mission} onChange={(v) => draft.set("content.about", { ...about, mission: v })} />
        <TextSetting draft={draft} path="content.about.vision" label="Vision" multiline value={about.vision} onChange={(v) => draft.set("content.about", { ...about, vision: v })} />
        <StringList
          draft={draft}
          path="content.about.values"
          label="Values"
          items={about.values}
          max={8}
          onChange={(list) => draft.set("content.about", { ...about, values: list })}
        />
      </Section>

      <ItemEditor draft={draft} path="content.services" title="Medical services" description="Shown on the home page and the Services page." />
      <ItemEditor draft={draft} path="content.facilities" title="Facilities" description="Shown on the home page and the Facilities page." />
      <TestimonialEditor draft={draft} />

      <SaveBar draft={draft} />
    </div>
  );
}

function move<T>(list: T[], from: number, to: number): T[] {
  const next = [...list];
  const [item] = next.splice(from, 1);
  next.splice(to, 0, item);
  return next;
}

function RowControls({ index, count, label, onMove, onRemove }: {
  index: number; count: number; label: string; onMove: (to: number) => void; onRemove: () => void;
}) {
  return (
    <div className="flex gap-1">
      <Button variant="ghost" size="icon-sm" aria-label={`Move ${label} up`} disabled={index === 0} onClick={() => onMove(index - 1)}>
        <ArrowUp />
      </Button>
      <Button variant="ghost" size="icon-sm" aria-label={`Move ${label} down`} disabled={index === count - 1} onClick={() => onMove(index + 1)}>
        <ArrowDown />
      </Button>
      <Button variant="ghost" size="icon-sm" aria-label={`Remove ${label}`} onClick={onRemove}>
        <Trash2 />
      </Button>
    </div>
  );
}

function StringList({ draft, path, label, items, max, onChange }: {
  draft: Draft; path: string; label: string; items: string[]; max: number; onChange: (list: string[]) => void;
}) {
  return (
    <fieldset className="grid gap-2 sm:col-span-2">
      <legend className="mb-1.5 text-sm font-medium">{label}</legend>
      {items.map((value, i) => {
        const id = fid(`${path}.${i}`);
        const error = draft.error(`${path}.${i}`);
        return (
          <div key={i} className="grid gap-1">
            <div className="flex items-center gap-2">
              <Input {...fieldAria(id, error)} aria-label={`${label} ${i + 1}`} value={value} onChange={(e) => onChange(items.map((v, j) => (j === i ? e.target.value : v)))} />
              <RowControls index={i} count={items.length} label={`value ${i + 1}`} onMove={(to) => onChange(move(items, i, to))} onRemove={() => onChange(items.filter((_, j) => j !== i))} />
            </div>
            {error && <p id={`${id}-error`} role="alert" className="text-xs text-destructive">{error}</p>}
          </div>
        );
      })}
      {items.length < max && (
        <Button variant="outline" size="sm" className="w-fit" onClick={() => onChange([...items, ""])}>
          <Plus aria-hidden /> Add value
        </Button>
      )}
    </fieldset>
  );
}

function ItemEditor({ draft, path, title, description }: { draft: Draft; path: "content.services" | "content.facilities"; title: string; description: string }) {
  const items = (draft.draft[path] as Item[]) ?? [];
  const update = (i: number, patch: Partial<Item>) => draft.set(path, items.map((it, j) => (j === i ? { ...it, ...patch } : it)));

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description} Up to {MAX_ITEMS} items, shown in this order.</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {draft.error(path) && !items.some((_, i) => draft.error(`${path}.${i}`)) && (
          <p role="alert" className="text-xs text-destructive">{draft.error(path)}</p>
        )}
        {items.map((item, i) => {
          const base = `${path}.${i}`;
          return (
            <div key={i} className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[10rem_1fr_auto]">
              <FormField id={fid(`${base}.icon`)} label="Icon" error={draft.error(`${base}.icon`)}>
                <div className="flex items-center gap-2">
                  <span className="grid size-8 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                    <ContentIcon icon={item.icon} className="size-4" />
                  </span>
                  <NativeSelect {...fieldAria(fid(`${base}.icon`), draft.error(`${base}.icon`))} value={item.icon} onChange={(e) => update(i, { icon: e.target.value })}>
                    {ICONS.map((icon) => (
                      <option key={icon} value={icon}>{icon.replace(/-/g, " ")}</option>
                    ))}
                  </NativeSelect>
                </div>
              </FormField>
              <div className="grid gap-3">
                <FormField id={fid(`${base}.title`)} label="Title" error={draft.error(`${base}.title`)}>
                  <Input {...fieldAria(fid(`${base}.title`), draft.error(`${base}.title`))} value={item.title} onChange={(e) => update(i, { title: e.target.value })} />
                </FormField>
                <FormField id={fid(`${base}.description`)} label="Description" error={draft.error(`${base}.description`)}>
                  <Textarea {...fieldAria(fid(`${base}.description`), draft.error(`${base}.description`))} rows={2} value={item.description} onChange={(e) => update(i, { description: e.target.value })} />
                </FormField>
              </div>
              <RowControls
                index={i}
                count={items.length}
                label={item.title || `item ${i + 1}`}
                onMove={(to) => draft.set(path, move(items, i, to))}
                onRemove={() => draft.set(path, items.filter((_, j) => j !== i))}
              />
            </div>
          );
        })}
        {items.length < MAX_ITEMS && (
          <Button variant="outline" className="w-fit" onClick={() => draft.set(path, [...items, { icon: "stethoscope", title: "", description: "" }])}>
            <Plus aria-hidden /> Add item
          </Button>
        )}
      </CardContent>
    </Card>
  );
}

function TestimonialEditor({ draft }: { draft: Draft }) {
  const path = "content.testimonials";
  const items = (draft.draft[path] as Testimonial[]) ?? [];
  const update = (i: number, patch: Partial<Testimonial>) => draft.set(path, items.map((it, j) => (j === i ? { ...it, ...patch } : it)));

  return (
    <Card>
      <CardHeader>
        <CardTitle>Patient testimonials</CardTitle>
        <CardDescription>
          Publish only genuine feedback, with the patient&apos;s written consent, and never include medical details that
          could identify them. The section is hidden while this list is empty.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-3">
        {items.map((t, i) => {
          const base = `${path}.${i}`;
          return (
            <div key={i} className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_auto]">
              <div className="grid gap-3 sm:grid-cols-2">
                <FormField id={fid(`${base}.name`)} label="Name as published" error={draft.error(`${base}.name`)} hint="e.g. first name and initial">
                  <Input {...fieldAria(fid(`${base}.name`), draft.error(`${base}.name`), "hint")} value={t.name} onChange={(e) => update(i, { name: e.target.value })} />
                </FormField>
                <FormField id={fid(`${base}.context`)} label="Context" optional error={draft.error(`${base}.context`)} hint="e.g. Orthopedics patient">
                  <Input {...fieldAria(fid(`${base}.context`), draft.error(`${base}.context`), "hint")} value={t.context} onChange={(e) => update(i, { context: e.target.value })} />
                </FormField>
                <FormField id={fid(`${base}.quote`)} label="Quote" error={draft.error(`${base}.quote`)} className="sm:col-span-2">
                  <Textarea {...fieldAria(fid(`${base}.quote`), draft.error(`${base}.quote`))} rows={2} value={t.quote} onChange={(e) => update(i, { quote: e.target.value })} />
                </FormField>
              </div>
              <RowControls
                index={i}
                count={items.length}
                label={t.name || `testimonial ${i + 1}`}
                onMove={(to) => draft.set(path, move(items, i, to))}
                onRemove={() => draft.set(path, items.filter((_, j) => j !== i))}
              />
            </div>
          );
        })}
        {items.length < MAX_ITEMS && (
          <Button variant="outline" className="w-fit" onClick={() => draft.set(path, [...items, { name: "", quote: "", context: "" }])}>
            <Plus aria-hidden /> Add testimonial
          </Button>
        )}
      </CardContent>
    </Card>
  );
}
