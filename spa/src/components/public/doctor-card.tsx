import { CalendarCheck, GraduationCap } from "lucide-react";
import Link from "next/link";

import { buttonVariants } from "@/components/ui/button";
import { formatDay, formatFee, initialsOf } from "@/lib/public-format";
import { cn } from "@/lib/utils";
import { mediaUrl } from "@/lib/media";
import type { PublicDoctor } from "@/types/public";

export function DoctorAvatar({ doctor, className }: { doctor: Pick<PublicDoctor, "full_name" | "photo_url">; className?: string }) {
  if (doctor.photo_url) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- doctor photos are admin-uploaded URLs
      <img src={mediaUrl(doctor.photo_url) ?? undefined} alt={doctor.full_name} className={cn("rounded-2xl object-cover", className)} />
    );
  }
  return (
    <div
      aria-hidden
      className={cn(
        "grid place-items-center rounded-2xl bg-gradient-to-br from-primary/15 to-teal/20 font-heading font-bold text-primary",
        className,
      )}
    >
      {initialsOf(doctor.full_name)}
    </div>
  );
}

export function AvailabilityLine({ doctor }: { doctor: PublicDoctor }) {
  if (!doctor.next_available_date) {
    return <p className="text-sm text-muted-foreground">No open slots right now</p>;
  }
  return (
    <p className="flex items-center gap-1.5 text-sm font-medium text-success">
      <CalendarCheck className="size-4" aria-hidden />
      Next available {formatDay(doctor.next_available_date)}{" "}
      <span className="font-normal text-muted-foreground">
        · {doctor.available_slot_count} slot{doctor.available_slot_count === 1 ? "" : "s"}
      </span>
    </p>
  );
}

export function DoctorCard({ doctor }: { doctor: PublicDoctor }) {
  return (
    <article className="flex flex-col gap-4 rounded-2xl border bg-card p-5 transition-shadow hover:shadow-lg hover:shadow-primary/5">
      <div className="flex gap-4">
        <DoctorAvatar doctor={doctor} className="size-20 shrink-0 text-2xl" />
        <div className="min-w-0">
          <h3 className="font-heading text-lg leading-tight font-semibold">
            <Link href={`/doctors/${doctor.id}`} className="hover:text-primary">
              {doctor.full_name}
            </Link>
          </h3>
          <p className="text-sm font-medium text-teal">{doctor.specialization}</p>
          <p className="mt-1 flex items-start gap-1.5 text-sm text-muted-foreground">
            <GraduationCap className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span className="line-clamp-2">{doctor.qualification}</span>
          </p>
        </div>
      </div>
      <dl className="grid grid-cols-2 gap-2 rounded-xl bg-muted/60 p-3 text-sm">
        <div>
          <dt className="text-xs text-muted-foreground">Experience</dt>
          <dd className="font-medium">{doctor.experience_years} yrs</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Consultation</dt>
          <dd className="font-medium">{formatFee(doctor.consultation_fee)}</dd>
        </div>
        {doctor.departments.length > 0 && (
          <div className="col-span-2">
            <dt className="sr-only">Department</dt>
            <dd className="flex flex-wrap gap-1">
              {doctor.departments.map((d) => (
                <Link key={d.id} href={`/departments/${d.slug}`} className="rounded-full bg-background px-2 py-0.5 text-xs font-medium hover:text-primary">
                  {d.name}
                </Link>
              ))}
            </dd>
          </div>
        )}
      </dl>
      <AvailabilityLine doctor={doctor} />
      <div className="mt-auto grid grid-cols-2 gap-2">
        <Link href={`/doctors/${doctor.id}`} className={buttonVariants({ variant: "outline" })}>
          View profile
        </Link>
        <Link href={`/doctors/${doctor.id}#availability`} className={buttonVariants()}>
          Book appointment
        </Link>
      </div>
    </article>
  );
}
