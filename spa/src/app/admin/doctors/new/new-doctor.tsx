"use client";

import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { toast } from "sonner";

import { DoctorForm } from "@/app/admin/doctors/doctor-form";
import { refreshPublicContent } from "@/app/admin/settings/actions";
import { PageHeader } from "@/components/shared/page-header";
import { getAccessToken } from "@/lib/api";
import { RequireAuth } from "@/lib/auth/require-auth";

export function NewDoctorPage() {
  const router = useRouter();
  return (
    <RequireAuth allow={(u) => u.permissions.includes("doctors.manage")}>
      <div className="mx-auto max-w-4xl">
        <Link href="/admin/doctors" className="mb-4 flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" aria-hidden /> Doctors
        </Link>
        <PageHeader title="Add doctor" description="Creates their public profile and a login with the Doctor role." />
        <DoctorForm
          doctor={null}
          onSaved={(d) => {
            toast.success(`${d.full_name} added as ${d.doctor_code}`);
            refreshPublicContent(getAccessToken());
            router.replace(`/admin/doctors/${d.id}`);
          }}
        />
      </div>
    </RequireAuth>
  );
}
