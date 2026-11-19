import { Navigate, useLocation } from "react-router-dom";

import { EmptyState } from "@/components/shared/empty-state";
import { buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useAuth } from "@/lib/auth/auth-provider";
import { BookPage } from "@/app/portal/book-page";
import { CalendarCheck } from "lucide-react";
import Link from "next/link";

/** The public booking page: no sign-in needed. Signed-in patients are sent to their portal's version. */
export function GuestBookPage() {
  const { status, user, isStaff } = useAuth();
  const { search } = useLocation();

  if (status === "loading") return <Skeleton className="h-64 w-full max-w-3xl" />;
  if (status === "authenticated" && user && !isStaff) return <Navigate to={`/portal/book${search}`} replace />;
  if (status === "authenticated" && isStaff) {
    return (
      <EmptyState icon={CalendarCheck} title="This page is for patients"
        description="You are signed in as hospital staff. To book for a patient, open Appointments in the admin panel."
        action={<Link href="/admin/appointments" className={buttonVariants()}>Go to Appointments</Link>} />
    );
  }
  return <div className="w-full max-w-3xl"><BookPage guest /></div>;
}
