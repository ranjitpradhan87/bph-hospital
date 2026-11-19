/** Mirrors backend enums in backend/app/models/enums.py and schemas in backend/app/schemas. */

export type SlotDisplayStatus = "available" | "partially_booked" | "fully_booked" | "blocked" | "cancelled";

export type AppointmentStatus =
  | "pending"
  | "confirmed"
  | "checked_in"
  | "in_consultation"
  | "completed"
  | "cancelled"
  | "no_show";

export type AppointmentType = "in_person" | "online";

export type BillStatus = "pending" | "partially_paid" | "paid" | "refunded" | "cancelled";

export type PaymentMethod = "cash" | "upi" | "card" | "bank_transfer" | "online";

export type RoleCode = "super_admin" | "admin" | "doctor" | "receptionist" | "accountant" | "nurse" | "patient";

export type Gender = "male" | "female" | "other";

export const BLOOD_GROUPS = ["A+", "A-", "B+", "B-", "AB+", "AB-", "O+", "O-"] as const;
export type BloodGroup = (typeof BLOOD_GROUPS)[number];

export interface HealthResponse {
  status: "ok";
  database: "ok";
}

export interface Me {
  id: number;
  full_name: string;
  email: string | null;
  mobile: string | null;
  roles: string[];
  permissions: string[];
  patient_id: number | null;
  patient_code: string | null;
  doctor_id: number | null;
  staff_id: number | null;
}

export interface TokenResponse {
  user: Me;
}

export interface Page<T> {
  items: T[];
  total: number;
  page: number;
  page_size: number;
}

export interface Permission {
  id: number;
  code: string;
  module: string;
  description: string;
}

export interface Role {
  id: number;
  code: string;
  name: string;
  description: string;
  is_system: boolean;
  permissions: string[];
  user_count: number;
}

export interface UserAccount {
  id: number;
  full_name: string;
  email: string | null;
  mobile: string | null;
  is_active: boolean;
  roles: string[];
  last_login_at: string | null;
  created_at: string;
}

// ---------- Phase 4: dashboard, settings, enquiries ----------

export interface DashboardSummary {
  as_of: string;
  patients_total: number;
  patients_new_this_month: number;
  doctors_active: number;
  staff_active: number;
  appointments: {
    today_total: number;
    today_by_status: Record<AppointmentStatus, number>;
    upcoming: number;
    completed_this_month: number;
    cancelled_this_month: number;
  };
  revenue: { today: number; this_month: number; pending_amount: number; pending_bills: number } | null;
  enquiries_new: number;
}

export interface DepartmentStat {
  department_id: number;
  name: string;
  total: number;
  completed: number;
  cancelled: number;
  no_show: number;
}

export interface DayPoint {
  date: string;
  total: number;
  completed: number;
  cancelled: number;
}

export interface MonthPoint {
  month: string;
  collected: number;
  refunded: number;
}

export interface SettingRow {
  key: string;
  group: string;
  value: unknown;
  public: boolean;
  editable: boolean;
  updated_at: string | null;
}

export interface Holiday {
  id: number;
  holiday_date: string;
  name: string;
}

export type EnquiryStatus = "new" | "in_progress" | "closed";

export interface Enquiry {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  subject: string | null;
  message: string;
  status: EnquiryStatus;
  created_at: string;
}
