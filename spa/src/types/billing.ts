export type BillStatus = "pending" | "partially_paid" | "paid" | "cancelled";

export const BILL_STATUS_LABEL: Record<BillStatus, string> = {
  pending: "Unpaid",
  partially_paid: "Part paid",
  paid: "Paid",
  cancelled: "Cancelled",
};

export interface BillRow {
  id: number;
  bill_number: string;
  bill_date: string;
  patient_id: number;
  patient_name: string;
  patient_code: string;
  total: number;
  paid: number;
  balance: number;
  status: BillStatus;
  /** "lab" bills come from a lab order and are cancelled there. */
  source: "lab" | "counter";
  lab_order_number: string | null;
}

export interface BillList {
  items: BillRow[];
  total: number;
  page: number;
  page_size: number;
  summary: { bills: number; billed: number; collected: number; outstanding: number };
}

export interface BillItem {
  id: number;
  description: string;
  quantity: number;
  unit_price: number;
  line_total: number;
}

export interface BillPayment {
  id: number;
  receipt_number: string;
  amount: number;
  method: string;
  reference_no: string | null;
  paid_at: string;
  received_by: string | null;
  notes: string | null;
}

export interface BillDetail extends BillRow {
  patient_mobile: string | null;
  subtotal: number;
  discount: number;
  notes: string | null;
  cancel_reason: string | null;
  appointment_id: number | null;
  items: BillItem[];
  payments: BillPayment[];
}

// ---------- reports ----------

export interface ReportOverview {
  from: string;
  to: string;
  financial: boolean;
  appointments: {
    total: number;
    by_status: Record<string, number>;
    by_doctor: { name: string; total: number; completed: number; lost: number }[];
    by_department: { name: string; total: number }[];
  };
  lab: { orders: number; in_patient: number; lab_only: number; billed?: number; top_tests: { name: string; count: number; amount?: number }[] };
  ipd: { admitted: number; discharged: number; in_hospital_now: number; received?: number; payouts?: number; charges?: number };
  collections?: {
    total: number;
    by_source: { bills: number; online_advance: number; ipd: number };
    by_method: { method: string; amount: number }[];
    daily: { date: string; bills: number; online_advance: number; ipd: number; total: number }[];
  };
  outstanding?: { amount: number; bills: number; top: { id: number; bill_number: string; bill_date: string; patient_name: string; patient_code: string; balance: number }[] };
}
