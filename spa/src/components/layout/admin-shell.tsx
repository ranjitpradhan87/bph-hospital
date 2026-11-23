"use client";

import {
  BedDouble,
  ClipboardList,
  CalendarClock,
  FlaskConical,
  CalendarCheck2,
  CalendarDays,
  IdCard,
  HeartPulse,
  Inbox,
  LayoutDashboard,
  type LucideIcon,
  Menu,
  ReceiptIndianRupee,
  Settings,
  ShieldCheck,
  Stethoscope,
  UserRound,
  Users,
  Building2,
  BarChart3,
  X,
} from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";

import { UserMenu } from "@/components/layout/user-menu";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth/auth-provider";
import { siteConfig } from "@/lib/config";
import { cn } from "@/lib/utils";

interface NavItem {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Shown when the user holds any of these. Empty = every staff member. */
  anyOf: string[];
  /** Not built yet: rendered disabled with the phase it ships in. */
  phase?: number;
}

const NAV: NavItem[] = [
  { href: "/admin", label: "Dashboard", icon: LayoutDashboard, anyOf: [] },
  { href: "/admin/appointments", label: "Appointments", icon: CalendarDays, anyOf: ["appointments.read", "appointments.read_own"] },
  { href: "/admin/slots", label: "Schedules & slots", icon: CalendarClock, anyOf: ["slots.read"] },
  { href: "/admin/opd", label: "OPD", icon: ClipboardList, anyOf: ["opd.read"] },
  { href: "/admin/ipd", label: "IPD register", icon: BedDouble, anyOf: ["ipd.read"] },
  { href: "/admin/lab", label: "Lab", icon: FlaskConical, anyOf: ["lab.read"] },
  { href: "/admin/patients", label: "Patients", icon: UserRound, anyOf: ["patients.read"] },
  { href: "/admin/doctors", label: "Doctors", icon: Stethoscope, anyOf: ["doctors.read", "doctors.manage"] },
  { href: "/admin/departments", label: "Departments", icon: Building2, anyOf: ["departments.manage"] },
  { href: "/admin/staff", label: "Staff", icon: IdCard, anyOf: ["staff.read", "staff.manage"] },
  { href: "/admin/attendance", label: "Attendance", icon: CalendarCheck2, anyOf: ["attendance.manage"] },
  { href: "/admin/billing", label: "Billing", icon: ReceiptIndianRupee, anyOf: ["billing.read"] },
  { href: "/admin/reports", label: "Reports", icon: BarChart3, anyOf: ["reports.view"] },
  { href: "/admin/users", label: "Users", icon: Users, anyOf: ["users.manage"] },
  { href: "/admin/roles", label: "Roles & permissions", icon: ShieldCheck, anyOf: ["roles.manage"] },
  { href: "/admin/enquiries", label: "Enquiries", icon: Inbox, anyOf: ["enquiries.manage"] },
  { href: "/admin/settings", label: "Settings", icon: Settings, anyOf: ["settings.manage"] },
];

function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const { canAny } = useAuth();
  const items = NAV.filter((i) => i.anyOf.length === 0 || canAny(...i.anyOf));

  return (
    <nav aria-label="Main" className="grid gap-0.5 p-3">
      {items.map(({ href, label, icon: Icon, phase }) => {
        const active = href === "/admin" ? pathname === href : pathname.startsWith(href);
        if (phase) {
          return (
            <span
              key={href}
              aria-disabled="true"
              className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-sidebar-foreground/45"
            >
              <Icon className="size-4" aria-hidden />
              <span className="flex-1">{label}</span>
              <span className="rounded bg-sidebar-accent px-1.5 text-[10px] font-medium">Phase {phase}</span>
            </span>
          );
        }
        return (
          <Link
            key={href}
            href={href}
            onClick={onNavigate}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors",
              active
                ? "bg-sidebar-primary text-sidebar-primary-foreground"
                : "text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground",
            )}
          >
            <Icon className="size-4" aria-hidden />
            {label}
          </Link>
        );
      })}
    </nav>
  );
}

function Brand() {
  return (
    <Link href="/admin" className="flex h-14 items-center gap-2 px-5 text-primary">
      <HeartPulse className="size-6" aria-hidden />
      <span className="font-heading font-bold">{siteConfig.name}</span>
    </Link>
  );
}

export function AdminShell({ children }: { children: React.ReactNode }) {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="flex min-h-svh flex-1">
      <aside className="sticky top-0 hidden h-svh w-64 shrink-0 flex-col border-r bg-sidebar lg:flex">
        <Brand />
        <div className="flex-1 overflow-y-auto">
          <SidebarNav />
        </div>
      </aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <button className="absolute inset-0 bg-black/30" aria-label="Close navigation" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 flex w-72 flex-col border-r bg-sidebar shadow-xl">
            <div className="flex items-center justify-between pr-2">
              <Brand />
              <Button variant="ghost" size="icon" onClick={() => setMobileOpen(false)} aria-label="Close navigation">
                <X />
              </Button>
            </div>
            <div className="flex-1 overflow-y-auto">
              <SidebarNav onNavigate={() => setMobileOpen(false)} />
            </div>
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-40 flex h-14 items-center gap-2 border-b bg-background/90 px-4 backdrop-blur sm:px-6">
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            onClick={() => setMobileOpen(true)}
            aria-label="Open navigation"
          >
            <Menu />
          </Button>
          <div className="flex-1" />
          <UserMenu />
        </header>
        <main className="flex-1 bg-muted/40 p-4 sm:p-6">{children}</main>
      </div>
    </div>
  );
}
