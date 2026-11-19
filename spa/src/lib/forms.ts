import type { FieldValues, Path, UseFormSetError } from "react-hook-form";
import { toast } from "sonner";

import { ApiError } from "@/lib/api";

/** Map an API error onto a react-hook-form: 422 field errors go to their fields, anything else to `root`. */
export function applyApiError<T extends FieldValues>(err: unknown, setError: UseFormSetError<T>, fields: readonly string[]) {
  if (err instanceof ApiError) {
    const fieldErrors = Object.entries(err.fieldErrors).filter(([name]) => fields.includes(name));
    for (const [name, message] of fieldErrors) setError(name as Path<T>, { message });
    if (!fieldErrors.length) setError("root", { message: err.message });
  } else {
    setError("root", { message: "Unable to reach the server. Please try again." });
  }
  toast.error("Couldn't save — please check the form");
}

/** "" → null for optional text inputs before sending to the API. */
export function blankToNull<T extends Record<string, unknown>>(values: T): T {
  return Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === "" ? null : v])) as T;
}

export const inr = new Intl.NumberFormat("en-IN", { style: "currency", currency: "INR", maximumFractionDigits: 2 });
