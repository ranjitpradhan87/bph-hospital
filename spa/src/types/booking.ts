import type { AppointmentStatus, AppointmentType, SlotDisplayStatus } from "@/types/api";

export interface Appointment {
  id: number;
  appointment_number: string;
  appointment_date: string;
  start_time: string;
  end_time: string;
  status: AppointmentStatus;
  appointment_type: AppointmentType;
  payment_status: string;
  payment_mode: string;
  consultation_fee: string;
  reason: string | null;
  queue_number: number | null;
  booking_source: string;
  patient_id: number;
  patient_code: string;
  patient_name: string;
  patient_mobile: string | null;
  doctor_id: number;
  doctor_name: string;
  specialization: string;
  department_id: number;
  department_name: string;
  slot_id: number;
  cancel_reason: string | null;
  rescheduled_from_id: number | null;
  checked_in_at: string | null;
  created_at: string;
  can_cancel: boolean;
  can_reschedule: boolean;
}

export interface AppointmentDetail extends Appointment {
  history: { from_status: string | null; to_status: string; note: string | null; changed_by: string | null; created_at: string }[];
}

export interface SlotRow {
  id: number;
  doctor_id: number;
  doctor_name: string | null;
  department_id: number;
  department_name: string | null;
  slot_date: string;
  start_time: string;
  end_time: string;
  capacity: number;
  booked_count: number;
  remaining: number;
  consultation_fee: string;
  appointment_type: AppointmentType;
  status: string;
  display_status: SlotDisplayStatus;
  is_published: boolean;
  block_reason: string | null;
}

export interface ScheduleBreak {
  id?: number;
  start_time: string;
  end_time: string;
  reason: string | null;
}

export interface Schedule {
  id: number;
  doctor_id: number;
  department_id: number;
  day_of_week: number;
  start_time: string;
  end_time: string;
  slot_duration_min: number;
  capacity_per_slot: number;
  consultation_fee: string | null;
  appointment_type: AppointmentType;
  effective_from: string;
  effective_to: string | null;
  is_active: boolean;
  breaks: ScheduleBreak[];
}

export interface Leave {
  id: number;
  doctor_id: number;
  start_date: string;
  end_date: string;
  reason: string | null;
}

export interface OpenSlot {
  id: number;
  start_time: string;
  end_time: string;
  remaining: number;
  appointment_type: AppointmentType;
  consultation_fee: string;
}

export const todayIST = () => new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kolkata" }).format(new Date());

export function addDays(date: string, days: number): string {
  const [y, m, d] = date.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}
