import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

interface FormFieldProps {
  id: string;
  label: string;
  error?: string;
  hint?: string;
  optional?: boolean;
  className?: string;
  children: React.ReactNode;
}

/** Label + control + hint/error, wired for screen readers via aria-describedby on the control. */
export function FormField({ id, label, error, hint, optional, className, children }: FormFieldProps) {
  return (
    <div className={cn("grid gap-1.5", className)}>
      <Label htmlFor={id}>
        {label}
        {optional && <span className="font-normal text-muted-foreground"> (optional)</span>}
      </Label>
      {children}
      {error ? (
        <p id={`${id}-error`} role="alert" className="text-xs text-destructive">
          {error}
        </p>
      ) : hint ? (
        <p id={`${id}-hint`} className="text-xs text-muted-foreground">
          {hint}
        </p>
      ) : null}
    </div>
  );
}

/** Props to spread on the control inside a FormField. */
export function fieldAria(id: string, error?: string, hint?: string) {
  return {
    id,
    "aria-invalid": error ? true : undefined,
    "aria-describedby": error ? `${id}-error` : hint ? `${id}-hint` : undefined,
  } as const;
}
