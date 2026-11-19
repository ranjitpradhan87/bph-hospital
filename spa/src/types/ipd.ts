export type HeadType = "receipt" | "payout" | "charge";
export type PaymentMode = "cash" | "upi" | "card" | "cheque" | "other";
export const PAYMENT_MODES: { value: PaymentMode; label: string }[] = [
  { value: "cash", label: "Cash" }, { value: "upi", label: "UPI" }, { value: "card", label: "Card" }, { value: "cheque", label: "Cheque" }, { value: "other", label: "Other" },
];

export interface IpdBed {
  id: number;
  code: string;
  ward: string | null;
  department_id: number | null;
  department: string | null;
  display_order: number;
  is_active: boolean;
  occupied_by: { admission_id: number; patient_name: string } | null;
}

export interface IpdLookups {
  doctors: { id: number; name: string; specialization: string; is_active: boolean }[];
  departments: { id: number; name: string }[];
  beds: IpdBed[];
}

export interface IpdHead {
  id: number;
  code: string;
  name: string;
  type: HeadType;
  display_order: number;
  is_active: boolean;
}

export interface IpdEntry {
  id: number;
  head_id: number;
  entry_date: string;
  amount: number;
  payment_mode: PaymentMode | null;
  receipt_no: string | null;
  note: string | null;
}

export interface IpdAdmission {
  id: number;
  admission_date: string;
  patient_id: number | null;
  patient_code: string | null;
  patient_name: string;
  bed: string | null;
  bed_id: number | null;
  department_id: number | null;
  department: string | null;
  case_title: string | null;
  /** Registered doctors first, then any typed names. */
  doctors: string | null;
  doctor_ids: number[];
  doctor_list: { id: number; name: string }[];
  /** Typed names of visiting doctors who are not registered. */
  other_doctors: string | null;
  anaesthetist: string | null;
  anaesthetist_id: number | null;
  status: "admitted" | "discharged";
  discharge_date: string | null;
  notes: string | null;
  entries: IpdEntry[];
  totals: Record<string, number>;
  received: number;
  payouts: number;
  charges: number;
  /** Received − paid out. */
  balance: number;
}

export interface IpdSummary {
  month: string;
  admissions_in_month: number;
  currently_admitted: number;
  received: number;
  payouts: number;
  charges: number;
  net: number;
  heads: (IpdHead & { total: number; entries: number })[];
  daily: { date: string; received: number; payouts: number; charges: number }[];
}

export const HEAD_TYPE_LABEL: Record<HeadType, string> = {
  receipt: "Money received",
  payout: "Paid out",
  charge: "Billed service",
};

/** "2026-11-06" → "06.11", the way the paper register writes dates. */
export const shortDate = (d: string) => `${d.slice(8, 10)}.${d.slice(5, 7)}`;

export function addMonths(month: string, delta: number): string {
  const [y, m] = month.split("-").map(Number);
  const d = new Date(Date.UTC(y, m - 1 + delta, 1));
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}`;
}

export const monthLabel = (month: string) =>
  new Intl.DateTimeFormat("en-IN", { month: "long", year: "numeric", timeZone: "UTC" }).format(new Date(`${month}-01T00:00:00Z`));

export interface IpdDay {
  date: string;
  received: number;
  payouts: number;
  charges: number;
  admissions: { id: number; patient_id: number | null; patient_name: string; bed: string | null; department: string | null; case_title: string | null; doctors: string | null; doctor_list: { id: number; name: string }[]; other_doctors: string | null; anaesthetist: string | null; anaesthetist_id: number | null; status: "admitted" | "discharged" }[];
  entries: { id: number; admission_id: number; patient_id: number | null; patient_name: string; head_id: number; head_name: string; head_type: HeadType; amount: number; note: string | null }[];
}

export interface DateRange {
  from: string;
  to: string;
}

const pad = (n: number) => String(n).padStart(2, "0");

/** First and last day of a YYYY-MM month. */
export function monthRange(month: string): DateRange {
  const [y, m] = month.split("-").map(Number);
  return { from: `${month}-01`, to: `${month}-${pad(new Date(Date.UTC(y, m, 0)).getUTCDate())}` };
}

export const isFullMonth = (r: DateRange) => r.from.slice(0, 7) === r.to.slice(0, 7) && monthRange(r.from.slice(0, 7)).from === r.from && monthRange(r.from.slice(0, 7)).to === r.to;

export function shiftDays(date: string, days: number): string {
  const [y, m, d] = date.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

const dayLabel = (d: string, withYear: boolean) =>
  new Intl.DateTimeFormat("en-IN", { day: "numeric", month: "short", ...(withYear ? { year: "numeric" } : {}), timeZone: "UTC" }).format(new Date(`${d}T00:00:00Z`));

export function rangeLabel(r: DateRange): string {
  if (isFullMonth(r)) return monthLabel(r.from.slice(0, 7));
  if (r.from === r.to) return dayLabel(r.from, true);
  return `${dayLabel(r.from, r.from.slice(0, 4) !== r.to.slice(0, 4))} – ${dayLabel(r.to, true)}`;
}
