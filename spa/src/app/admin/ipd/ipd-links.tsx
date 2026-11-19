import { Fragment } from "react";
import Link from "next/link";

import { useAuth } from "@/lib/auth/auth-provider";

interface Linked {
  bed?: string | null;
  department?: string | null;
  case_title?: string | null;
  doctor_list: { id: number; name: string }[];
  /** Typed names of visiting doctors who are not registered. */
  other_doctors: string | null;
  anaesthetist: string | null;
  anaesthetist_id: number | null;
}

/** A doctor's name, linking to their record when they are registered and the viewer may open it. */
export function DoctorLink({ id, name }: { id: number | null; name: string }) {
  const { can } = useAuth();
  if (!id || !(can("doctors.read") || can("doctors.manage"))) return <>{name}</>;
  return <Link href={`/admin/doctors/${id}`} className="text-primary hover:underline" onClick={(e) => e.stopPropagation()}>{name}</Link>;
}

/** Treating doctors: registered ones link to their records, visiting ones are plain text. */
export function DoctorNames({ list, other }: { list: Linked["doctor_list"]; other: string | null }) {
  const parts: React.ReactNode[] = list.map((d) => <DoctorLink key={d.id} id={d.id} name={d.name} />);
  if (other) parts.push(<Fragment key="other">{other}</Fragment>);
  return <>{parts.map((p, i) => <Fragment key={i}>{i > 0 && ", "}{p}</Fragment>)}</>;
}

/** The one-line summary under a patient: bed · department · case · doctors · anaesthesia. */
export function AdmissionMeta({ a }: { a: Linked }) {
  const parts: React.ReactNode[] = [];
  if (a.bed) parts.push(`Bed ${a.bed}`);
  if (a.department) parts.push(a.department);
  if (a.case_title) parts.push(a.case_title);
  if (a.doctor_list.length || a.other_doctors) parts.push(<DoctorNames list={a.doctor_list} other={a.other_doctors} />);
  if (a.anaesthetist) parts.push(<>Anaesthesia: <DoctorLink id={a.anaesthetist_id} name={a.anaesthetist} /></>);
  return <>{parts.map((p, i) => <Fragment key={i}>{i > 0 && " · "}{p}</Fragment>)}</>;
}
