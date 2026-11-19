import { z } from "zod";

/** Client-side mirrors of backend/app/schemas/common.py rules. The API re-validates everything. */

export function normalizeMobile(value: string): string {
  let digits = value.replace(/\D/g, "");
  if (digits.length === 12 && digits.startsWith("91")) digits = digits.slice(2);
  else if (digits.length === 11 && digits.startsWith("0")) digits = digits.slice(1);
  return digits;
}

export const mobileSchema = z
  .string()
  .trim()
  .min(1, "Mobile number is required")
  .transform(normalizeMobile)
  .refine((v) => /^[6-9]\d{9}$/.test(v), "Enter a valid 10-digit Indian mobile number");

export const passwordSchema = z
  .string()
  .min(8, "At least 8 characters")
  .max(128, "At most 128 characters")
  .refine((v) => /[A-Za-z]/.test(v) && /\d/.test(v), "Include at least one letter and one number");

export const nameSchema = z.string().trim().min(2, "Enter the full name").max(150);

export const emailSchema = z.string().trim().min(1, "Email is required").email("Enter a valid email address");
