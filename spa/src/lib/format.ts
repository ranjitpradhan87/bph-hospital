const ROLE_LABELS: Record<string, string> = {
  super_admin: "Super Admin",
  admin: "Admin",
  doctor: "Doctor",
  receptionist: "Receptionist",
  accountant: "Accountant",
  nurse: "Nurse",
  patient: "Patient",
};

export function roleLabel(code: string): string {
  return ROLE_LABELS[code] ?? code.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join("");
}

const dateTime = new Intl.DateTimeFormat("en-IN", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Kolkata" });

/** API datetimes are ISO strings in UTC (with an offset); a bare timestamp is treated as UTC. */
export function formatDateTime(value: string | null): string {
  if (!value) return "—";
  const date = new Date(/(Z|[+-]\d{2}:?\d{2})$/i.test(value) ? value : `${value}Z`);
  return Number.isNaN(date.getTime()) ? "—" : dateTime.format(date);
}
