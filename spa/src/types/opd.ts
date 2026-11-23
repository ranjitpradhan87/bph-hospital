export type VisitStatus = "waiting" | "in_consultation" | "completed" | "cancelled";

export const VISIT_STATUS_LABEL: Record<VisitStatus, string> = {
  waiting: "Waiting", in_consultation: "With doctor", completed: "Done", cancelled: "Cancelled",
};

export interface OpdBill {
  id: number;
  number: string;
  total: number;
  paid: number;
  balance: number;
  status: string;
}

export interface OpdVisitRow {
  id: number;
  visit_date: string;
  token_number: number;
  status: VisitStatus;
  source: "walk_in" | "appointment";
  reason: string | null;
  patient_id: number;
  patient_code: string;
  patient_name: string;
  patient_mobile: string | null;
  doctor_id: number;
  doctor_name: string;
  specialization: string | null;
  department: string | null;
  appointment_id: number | null;
  appointment_number: string | null;
  fee: number;
  lab_count: number;
  bill: OpdBill | null;
  started_at: string | null;
  completed_at: string | null;
  cancel_reason: string | null;
}

export interface Vitals {
  bp?: string;
  pulse?: number;
  temperature?: number;
  spo2?: number;
  weight?: number;
  height?: number;
  respiratory_rate?: number;
}

export interface Medicine {
  id?: number;
  medicine_name: string;
  dosage: string | null;
  frequency: string | null;
  duration: string | null;
  route: string | null;
  instructions: string | null;
}

export interface OpdVisit extends OpdVisitRow {
  patient: { gender: string | null; age: number | null; address: string | null };
  consultation: { chief_complaint: string | null; vitals: Vitals | null; diagnosis: string | null; notes: string | null; advice: string | null; follow_up_date: string | null } | null;
  prescriptions: Medicine[];
  lab_orders: { id: number; order_number: string; status: string; tests: string | null; total: number; paid: number }[];
  payments: { receipt_number: string; amount: number; method: string; reference_no: string | null; paid_at: string | null; received_by: string | null }[];
  hospital: { name: string; phone: string; address: string };
}

export interface OpdSummary {
  visits: number;
  waiting: number;
  in_consultation: number;
  completed: number;
  cancelled: number;
  billed: number;
  collected: number;
  lab_orders: number;
}

export interface OpdDoctor {
  id: number;
  name: string;
  specialization: string;
  fee: number;
}
