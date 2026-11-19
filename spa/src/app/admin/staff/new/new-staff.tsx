"use client";

import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { StaffForm } from "@/app/admin/staff/staff-form";
import { PageHeader } from "@/components/shared/page-header";
import { RequireAuth } from "@/lib/auth/require-auth";

export function NewStaffPage() {
  const router = useRouter();
  return (
    <RequireAuth allow={(u) => u.permissions.includes("staff.manage")}>
      <div className="mx-auto max-w-4xl">
        <Link href="/admin/staff" className="mb-4 flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" aria-hidden /> Staff
        </Link>
        <PageHeader title="Add staff member" description="Creates their employee record and a login with the roles you choose." />
        <StaffForm
          staff={null}
          onSaved={(s) => {
            toast.success(`${s.full_name} added as ${s.staff_code}`);
            router.replace(`/admin/staff/${s.id}`);
          }}
        />
      </div>
    </RequireAuth>
  );
}
