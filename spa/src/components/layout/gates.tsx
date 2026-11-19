"use client";

import { HeartPulse } from "lucide-react";
import Link from "next/link";

import { AdminShell } from "@/components/layout/admin-shell";
import { UserMenu } from "@/components/layout/user-menu";
import { RequireAuth } from "@/lib/auth/require-auth";
import { siteConfig } from "@/lib/config";
import type { Me } from "@/types/api";

const isStaff = (user: Me) => user.roles.some((r) => r !== "patient");
const isPatient = (user: Me) => user.permissions.includes("portal.access");

export function StaffGate({ children }: { children: React.ReactNode }) {
  return (
    <RequireAuth allow={isStaff}>
      <AdminShell>{children}</AdminShell>
    </RequireAuth>
  );
}

export function PatientGate({ children }: { children: React.ReactNode }) {
  return (
    <RequireAuth allow={isPatient}>
      <header className="sticky top-0 z-40 border-b bg-background/90 backdrop-blur">
        <div className="mx-auto flex h-14 max-w-5xl items-center justify-between px-4 sm:px-6">
          <Link href="/portal" className="flex items-center gap-2 text-primary">
            <HeartPulse className="size-6" aria-hidden />
            <span className="font-heading font-bold">{siteConfig.name}</span>
          </Link>
          <UserMenu />
        </div>
      </header>
      <main className="flex-1 bg-muted/40">
        <div className="mx-auto max-w-5xl p-4 sm:p-6">{children}</div>
      </main>
    </RequireAuth>
  );
}

/** Shown while the gate streams in (it needs the pathname, known only at request time on [id] routes). */
export function GateFallback() {
  return (
    <div className="mx-auto w-full max-w-5xl space-y-4 p-6" aria-busy="true" aria-label="Loading">
      <div className="h-8 w-56 animate-pulse rounded-md bg-muted" />
      <div className="h-32 w-full animate-pulse rounded-md bg-muted" />
    </div>
  );
}
