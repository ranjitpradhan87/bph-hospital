"use client";

import { ShieldAlert } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect } from "react";

import { buttonVariants } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { homeFor, useAuth } from "@/lib/auth/auth-provider";
import type { Me } from "@/types/api";

interface RequireAuthProps {
  children: React.ReactNode;
  /** Access check on top of being signed in. UX only — the API enforces permissions itself. */
  allow: (user: Me) => boolean;
}

export function RequireAuth({ children, allow }: RequireAuthProps) {
  const { status, user } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (status === "anonymous") router.replace(`/login?next=${encodeURIComponent(pathname)}`);
  }, [status, router, pathname]);

  if (status !== "authenticated" || !user) {
    return (
      <div className="mx-auto w-full max-w-5xl space-y-4 p-6" aria-busy="true" aria-label="Loading">
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (!allow(user)) {
    return (
      <div className="mx-auto flex max-w-md flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
        <ShieldAlert className="size-10 text-destructive" aria-hidden />
        <h1 className="font-heading text-xl font-semibold">You don&apos;t have access to this area</h1>
        <p className="text-sm text-muted-foreground">Your account doesn&apos;t include the permissions this page needs.</p>
        <Link href={homeFor(user)} className={buttonVariants()}>
          Go to my dashboard
        </Link>
      </div>
    );
  }

  return children;
}
