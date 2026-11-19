"use client";

import { FlaskConical, IdCard, ReceiptIndianRupee, UserPen } from "lucide-react";
import Link from "next/link";

import { MyAppointments } from "@/app/portal/my-appointments";
import { PageHeader } from "@/components/shared/page-header";
import { buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/lib/auth/auth-provider";

const upcoming = [
  { icon: ReceiptIndianRupee, title: "Bills & payments", text: "Invoices, receipts and payment history.", phase: 9 },
];

export function PortalHome() {
  const { user } = useAuth();
  if (!user) return null;

  return (
    <>
      <PageHeader
        title={`Hello, ${user.full_name.split(" ")[0]}`}
        description="Manage your visits to the hospital."
        actions={<span className="flex gap-2"><Link href="/portal/lab" className={buttonVariants({ variant: "outline" })}><FlaskConical aria-hidden /> Lab reports</Link><Link href="/portal/profile" className={buttonVariants({ variant: "outline" })}><UserPen aria-hidden /> My profile</Link></span>}
      />
      <Card className="mb-6">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 font-heading">
            <IdCard className="size-5 text-primary" aria-hidden /> Patient ID
          </CardTitle>
          <CardDescription>Quote this at the front desk to find your records quickly.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-3">
          <div>
            <p className="text-xs text-muted-foreground">Patient ID</p>
            <p className="font-mono text-lg font-semibold text-primary">{user.patient_code}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Email</p>
            <p className="truncate text-sm font-medium">{user.email ?? "—"}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Mobile</p>
            <p className="text-sm font-medium">{user.mobile ?? "—"}</p>
          </div>
        </CardContent>
      </Card>
      <div className="mb-6"><MyAppointments /></div>
      <div className="grid gap-4 sm:grid-cols-3">
        {upcoming.map(({ icon: Icon, title, text, phase }) => (
          <Card key={title} className="opacity-70">
            <CardHeader>
              <Icon className="mb-1 size-5 text-teal" aria-hidden />
              <CardTitle className="text-base">{title}</CardTitle>
              <CardDescription>{text}</CardDescription>
              <p className="pt-1 text-xs font-medium text-muted-foreground">Available from Phase {phase}</p>
            </CardHeader>
          </Card>
        ))}
      </div>
    </>
  );
}
