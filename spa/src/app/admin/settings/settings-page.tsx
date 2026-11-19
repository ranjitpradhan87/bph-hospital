"use client";

import { useState } from "react";

import { HolidaysTab } from "@/app/admin/settings/holidays-tab";
import { GeneralTab } from "@/app/admin/settings/general-tab";
import { BillingTab, BookingTab, NotificationsTab, RegistrationTab } from "@/app/admin/settings/policy-tabs";
import type { Values } from "@/app/admin/settings/use-settings-draft";
import { WebsiteTab } from "@/app/admin/settings/website-tab";
import { PageHeader } from "@/components/shared/page-header";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { RequireAuth } from "@/lib/auth/require-auth";
import { useApi } from "@/lib/use-api";
import type { SettingRow } from "@/types/api";

const TABS = [
  { value: "general", label: "General", Component: GeneralTab },
  { value: "website", label: "Website content", Component: WebsiteTab },
  { value: "booking", label: "Booking policy", Component: BookingTab },
  { value: "billing", label: "Billing", Component: BillingTab },
  { value: "notifications", label: "Notifications", Component: NotificationsTab },
  { value: "registration", label: "Registration", Component: RegistrationTab },
] as const;

export function SettingsPage() {
  return (
    <RequireAuth allow={(u) => u.permissions.includes("settings.manage")}>
      <SettingsView />
    </RequireAuth>
  );
}

function SettingsView() {
  const loaded = useApi<SettingRow[]>("/settings");
  // Saved rows replace the loaded ones so every tab sees the latest values.
  const [saved, setSaved] = useState<SettingRow[] | null>(null);
  const rows = saved ?? loaded.data;
  const values: Values | null = rows ? Object.fromEntries(rows.map((r) => [r.key, r.value])) : null;

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Hospital settings" description="Contact details, website content and the policies the system enforces." />
      {loaded.error && <p role="alert" className="text-sm text-destructive">{loaded.error.message}</p>}
      {!values ? (
        <div className="grid gap-4" aria-busy="true" aria-label="Loading settings">
          <Skeleton className="h-9 w-full max-w-xl" />
          <Skeleton className="h-64 w-full" />
        </div>
      ) : (
        <Tabs defaultValue="general" className="gap-4">
          <div className="-mx-1 overflow-x-auto px-1 pb-1">
            <TabsList>
              {TABS.map((t) => (
                <TabsTrigger key={t.value} value={t.value}>{t.label}</TabsTrigger>
              ))}
              <TabsTrigger value="holidays">Holidays</TabsTrigger>
            </TabsList>
          </div>
          {TABS.map(({ value, Component }) => (
            <TabsContent key={value} value={value} keepMounted>
              <Component values={values} onSaved={setSaved} />
            </TabsContent>
          ))}
          <TabsContent value="holidays">
            <HolidaysTab />
          </TabsContent>
        </Tabs>
      )}
    </div>
  );
}
