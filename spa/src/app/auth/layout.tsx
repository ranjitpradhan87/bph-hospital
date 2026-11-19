import { HeartPulse } from "lucide-react";
import Link from "next/link";

import { siteConfig } from "@/lib/config";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <main className="flex flex-1 flex-col items-center bg-gradient-to-b from-secondary to-background px-4 py-10 sm:py-16">
      <Link href="/" className="mb-8 flex items-center gap-2 text-primary">
        <HeartPulse className="size-7" aria-hidden />
        <span className="font-heading text-xl font-bold">{siteConfig.name}</span>
      </Link>
      {children}
    </main>
  );
}
