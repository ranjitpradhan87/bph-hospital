export type LabStatus = "ordered" | "sample_collected" | "processing" | "completed" | "cancelled";
export type LabFlag = "normal" | "high" | "low" | "critical";
export type PayMethod = "cash" | "upi" | "card" | "cheque" | "other";

export const LAB_STATUS_LABEL: Record<LabStatus, string> = {
  ordered: "Ordered",
  sample_collected: "Sample collected",
  processing: "Processing",
  completed: "Report ready",
  cancelled: "Cancelled",
};

export const PAY_METHODS: { value: PayMethod; label: string }[] = [
  { value: "cash", label: "Cash" }, { value: "upi", label: "UPI" }, { value: "card", label: "Card" }, { value: "cheque", label: "Cheque" }, { value: "other", label: "Other" },
];

export interface LabTest {
  id: number;
  code: string;
  name: string;
  category: string;
  sample_type: string | null;
  price: number;
  turnaround_hours: number | null;
  unit: string | null;
  reference_range: string | null;
  is_active: boolean;
  display_order: number;
}

export interface LabBill {
  number: string | null;
  subtotal: number;
  discount: number;
  total: number;
  paid: number;
  balance: number;
  status: string | null;
}

export interface LabOrderRow {
  id: number;
  order_number: string;
  status: LabStatus;
  created_at: string;
  patient_id: number;
  patient_code: string;
  patient_name: string;
  doctor_id: number | null;
  doctor_name: string | null;
  tests: string | null;
  bill: LabBill;
  /** Set when the patient was admitted at the time: the cost is on their IPD register. */
  admission_id: number | null;
  ipd_bed: string | null;
}

export interface LabItem {
  id: number;
  test_id: number | null;
  test_name: string;
  price: number;
  result_value: string | null;
  result_unit: string | null;
  reference_range: string | null;
  flag: LabFlag | null;
  result_notes: string | null;
  resulted_at: string | null;
}

export interface LabPayment {
  id: number;
  receipt_number: string;
  amount: number;
  method: string;
  reference_no: string | null;
  paid_at: string;
  received_by: string | null;
}

export interface LabOrder extends LabOrderRow {
  patient_mobile: string | null;
  admission_id: number | null;
  notes: string | null;
  sample_collected_at: string | null;
  completed_at: string | null;
  cancel_reason: string | null;
  items: LabItem[];
  payments: LabPayment[];
}

export interface LabLookups {
  doctors: { id: number; name: string }[];
  tests: LabTest[];
}

/** A patient's own view of an order: results appear only once the report is released. */
export interface PortalLabOrder extends LabOrderRow {
  completed_at: string | null;
  items: Pick<LabItem, "id" | "test_name" | "result_value" | "result_unit" | "reference_range" | "flag" | "result_notes">[];
}
