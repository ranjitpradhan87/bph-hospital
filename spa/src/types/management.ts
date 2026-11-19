/** Mirrors backend/app/schemas/management.py. */
import type { AppointmentStatus, AppointmentType, BillStatus, BloodGroup, Gender } from "@/types/api";

export interface DepartmentAdmin {
  id: number;
  name: string;
  slug: string;
  short_description: string | null;
  description: string | null;
  icon: string | null;
  image_url: string | null;
  display_order: number;
  is_active: boolean;
  doctor_count: number;
  staff_count: number;
}

export type DoctorStatus = "active" | "on_leave" | "inactive";

export interface DoctorAdmin {
  id: number;
  doctor_code: string;
  user_id: number;
  full_name: string;
  email: string | null;
  mobile: string | null;
  gender: Gender | null;
  qualification: string;
  specialization: string;
  experience_years: number;
  registration_number: string;
  consultation_fee: string;
  /** This doctor's own advance; null means the hospital default. */
  advance_amount: string | null;
  default_advance: number;
  languages: string | null;
  bio: string | null;
  photo_url: string | null;
  is_featured: boolean;
  status: DoctorStatus;
  login_active: boolean;
  departments: { id: number; name: string; slug: string; is_primary: boolean }[];
  upcoming_appointments: number;
  created_at: string;
}

export interface DoctorStats {
  period_from: string;
  period_to: string;
  total: number;
  by_status: Record<AppointmentStatus, number>;
  completion_rate: number | null;
  unique_patients: number;
  upcoming: number;
  open_slots_next_30_days: number;
}

export interface AppointmentRow {
  id: number;
  appointment_number: string;
  appointment_date: string;
  start_time: string;
  end_time: string;
  status: AppointmentStatus;
  appointment_type: AppointmentType;
  patient_id: number;
  patient_code: string;
  patient_name: string;
  doctor_id: number;
  doctor_name: string;
  department_name: string;
  consultation_fee: string;
}

export type EmploymentStatus = "active" | "on_leave" | "suspended" | "resigned" | "terminated";

export interface StaffMember {
  id: number;
  staff_code: string;
  user_id: number;
  full_name: string;
  email: string | null;
  mobile: string | null;
  gender: Gender | null;
  date_of_birth: string | null;
  address: string | null;
  photo_url: string | null;
  department_id: number | null;
  department_name: string | null;
  designation: string;
  joining_date: string;
  employment_status: EmploymentStatus;
  roles: string[];
  login_active: boolean;
  salary: string | null;
  created_at: string;
}

export type ShiftType = "morning" | "evening" | "night" | "general";

export interface Shift {
  id: number;
  staff_id: number;
  shift_date: string;
  start_time: string;
  end_time: string;
  shift_type: ShiftType;
  notes: string | null;
}

export type AttendanceStatus = "present" | "absent" | "half_day" | "leave" | "holiday";

export interface AttendanceRow {
  staff_id: number;
  staff_code: string;
  full_name: string;
  designation: string;
  department_name: string | null;
  attendance_date: string;
  status: AttendanceStatus | null;
  check_in: string | null;
  check_out: string | null;
  remarks: string | null;
}

export interface PatientListItem {
  id: number;
  patient_code: string;
  full_name: string;
  mobile: string | null;
  gender: Gender | null;
  age: number | null;
  /** Booked online; hospital staff still need to fill in the rest. */
  details_pending: boolean;
  last_visit: string | null;
  created_at: string;
}

export interface Patient {
  id: number;
  patient_code: string;
  full_name: string;
  mobile: string | null;
  email: string | null;
  date_of_birth: string | null;
  gender: Gender | null;
  details_pending: boolean;
  /** Has an online login that has not been given a password yet. */
  password_pending: boolean;
  blood_group: BloodGroup | null;
  address: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  has_login: boolean;
  consent_at: string | null;
  created_at: string;
}

export interface BillRow {
  id: number;
  bill_number: string;
  bill_date: string;
  total_amount: string;
  paid_amount: string;
  balance: string;
  status: BillStatus;
}

export type RecordType = "note" | "observation" | "document" | "allergy" | "history";
export type RecordVisibility = "clinical" | "doctor_only" | "patient_visible";

export interface MedicalRecord {
  id: number;
  patient_id: number;
  record_type: RecordType;
  title: string;
  content: string | null;
  data: Record<string, string | number> | null;
  visibility: RecordVisibility;
  file_name: string | null;
  mime_type: string | null;
  file_size: number | null;
  recorded_by: string | null;
  created_at: string;
}
