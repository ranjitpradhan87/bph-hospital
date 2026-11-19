import type { Address } from "@/types/public";

export const DAY_NAMES = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
export const DAY_SHORT = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

const inr = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 0 });

export function formatFee(value: string | number): string {
  return inr.format(Number(value));
}

/** "14:30:00" → "2:30 PM" */
export function formatTime(value: string): string {
  const [h, m] = value.split(":").map(Number);
  const suffix = h >= 12 ? "PM" : "AM";
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, "0")} ${suffix}`;
}

/** "2026-11-15" → "Sun, 15 Nov" (treated as a calendar date, no timezone shift). */
export function formatDay(value: string, opts: Intl.DateTimeFormatOptions = { weekday: "short", day: "numeric", month: "short" }): string {
  const [y, mo, d] = value.split("-").map(Number);
  return new Intl.DateTimeFormat("en-IN", { ...opts, timeZone: "UTC" }).format(new Date(Date.UTC(y, mo - 1, d)));
}

export function formatAddress(address?: Address): string {
  if (!address) return "";
  const cityLine = [address.city, address.state].filter(Boolean).join(", ");
  return [address.line1, address.line2, [cityLine, address.pincode].filter(Boolean).join(" - ")]
    .filter((part) => part && part.trim())
    .join(", ");
}

export function telHref(phone: string): string {
  return `tel:${phone.replace(/[^\d+]/g, "")}`;
}

/** Collapse consecutive working days: [0,1,2,3,4,5] → "Mon – Sat". */
export function formatWorkingDays(days?: number[]): string {
  if (!days?.length) return "";
  const sorted = [...days].sort((a, b) => a - b);
  const ranges: string[] = [];
  let start = sorted[0];
  let prev = sorted[0];
  for (const day of [...sorted.slice(1), Infinity]) {
    if (day !== prev + 1) {
      ranges.push(start === prev ? DAY_SHORT[start] : `${DAY_SHORT[start]} – ${DAY_SHORT[prev]}`);
      start = day;
    }
    prev = day;
  }
  return ranges.join(", ");
}

export function initialsOf(name: string): string {
  return name
    .replace(/^Dr\.?\s+/i, "")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join("");
}
