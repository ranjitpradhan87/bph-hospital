import type { AttendanceStatus } from "@/types/management";

export const ATTENDANCE_LABELS: Record<AttendanceStatus, string> = {
  present: "Present",
  absent: "Absent",
  half_day: "Half day",
  leave: "Leave",
  holiday: "Holiday",
};
