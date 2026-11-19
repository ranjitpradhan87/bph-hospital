"use client";

import { NumberSetting, SaveBar, Section, SwitchSetting, TextSetting, fid } from "@/app/admin/settings/fields";
import { type Values, useSettingsDraft } from "@/app/admin/settings/use-settings-draft";
import { FormField, fieldAria } from "@/components/shared/form-field";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import type { SettingRow } from "@/types/api";

type TabProps = { values: Values; onSaved: (rows: SettingRow[]) => void };

export function BookingTab({ values, onSaved }: TabProps) {
  const draft = useSettingsDraft(values, [
    "booking.default_slot_duration_min", "booking.advance_booking_days", "booking.min_lead_minutes",
    "booking.cancellation_cutoff_hours", "booking.reschedule_cutoff_hours", "booking.max_reschedules", "booking.auto_confirm",
  ], onSaved);
  const durationId = fid("booking.default_slot_duration_min");
  return (
    <div className="grid gap-4">
      <Section title="Slots" description="Defaults for new schedules; each doctor's schedule can override the duration.">
        <FormField id={durationId} label="Default slot length" error={draft.error("booking.default_slot_duration_min")}>
          <NativeSelect
            {...fieldAria(durationId, draft.error("booking.default_slot_duration_min"))}
            className="w-40"
            value={String(draft.draft["booking.default_slot_duration_min"])}
            onChange={(e) => draft.set("booking.default_slot_duration_min", Number(e.target.value))}
          >
            {[10, 15, 20, 30, 60].map((m) => (
              <option key={m} value={m}>{m} minutes</option>
            ))}
          </NativeSelect>
        </FormField>
        <NumberSetting draft={draft} path="booking.advance_booking_days" label="Open booking up to" suffix="days ahead" min={1} max={180} />
        <NumberSetting draft={draft} path="booking.min_lead_minutes" label="Minimum notice" suffix="minutes" min={0} max={1440} hint="Same-day slots starting sooner than this aren't offered." />
        <SwitchSetting draft={draft} path="booking.auto_confirm" label="Confirm online bookings automatically" description="When off, online bookings stay Pending until the front desk confirms them." />
      </Section>
      <Section title="Cancellation & rescheduling" description="Patients can change bookings online only within these limits; staff can always override.">
        <NumberSetting draft={draft} path="booking.cancellation_cutoff_hours" label="Cancel up to" suffix="hours before" min={0} max={168} />
        <NumberSetting draft={draft} path="booking.reschedule_cutoff_hours" label="Reschedule up to" suffix="hours before" min={0} max={168} />
        <NumberSetting draft={draft} path="booking.max_reschedules" label="Reschedules allowed per booking" min={0} max={10} />
      </Section>
      <SaveBar draft={draft} />
    </div>
  );
}

export function BillingTab({ values, onSaved }: TabProps) {
  const draft = useSettingsDraft(values, ["billing.tax_enabled", "billing.default_tax_pct", "billing.invoice_prefix"], onSaved);
  return (
    <div className="grid gap-4">
      <Section title="Billing" description="Applied when bills are created (Phase 9).">
        <FormField id="s-currency" label="Currency">
          <Input id="s-currency" value="INR (₹)" disabled className="w-40" />
        </FormField>
        <TextSetting draft={draft} path="billing.invoice_prefix" label="Invoice number prefix" hint="2–6 letters, e.g. INV → INV-2026-000123" />
        <SwitchSetting draft={draft} path="billing.tax_enabled" label="Charge tax on bills" description="Most clinical services are GST-exempt; enable only if you bill taxable items." />
        <NumberSetting draft={draft} path="billing.default_tax_pct" label="Default tax rate" suffix="%" min={0} max={28} />
      </Section>
      <SaveBar draft={draft} />
    </div>
  );
}

export function NotificationsTab({ values, onSaved }: TabProps) {
  const draft = useSettingsDraft(values, [
    "notifications.email_enabled", "notifications.sms_enabled", "notifications.whatsapp_enabled", "notifications.reminder_hours_before",
  ], onSaved);
  return (
    <div className="grid gap-4">
      <Section title="Channels" description="Booking confirmations, reminders, cancellations and payment receipts.">
        <SwitchSetting draft={draft} path="notifications.email_enabled" label="Email" description="Sent through the SMTP server configured on the API." />
        <SwitchSetting draft={draft} path="notifications.sms_enabled" label="SMS" disabled disabledReason="No SMS provider is connected yet." />
        <SwitchSetting draft={draft} path="notifications.whatsapp_enabled" label="WhatsApp" disabled disabledReason="No WhatsApp Business provider is connected yet." />
        <NumberSetting draft={draft} path="notifications.reminder_hours_before" label="Send appointment reminders" suffix="hours before" min={1} max={72} />
      </Section>
      <SaveBar draft={draft} />
    </div>
  );
}

export function RegistrationTab({ values, onSaved }: TabProps) {
  const draft = useSettingsDraft(values, ["registration.patient_self_signup", "registration.require_email_verification"], onSaved);
  return (
    <div className="grid gap-4">
      <Section title="Patient registration">
        <SwitchSetting
          draft={draft}
          path="registration.patient_self_signup"
          label="Allow patients to register online"
          description="When off, the registration page refuses sign-ups and patients are registered at the front desk."
        />
        <SwitchSetting
          draft={draft}
          path="registration.require_email_verification"
          label="Require email verification"
          disabled
          disabledReason="Available once email delivery is set up (Phase 10)."
        />
      </Section>
      <SaveBar draft={draft} />
    </div>
  );
}
