"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth/auth-provider";
import { passwordSchema } from "@/lib/validation";

const schema = z
  .object({
    current_password: z.string().min(1, "Enter your current password"),
    new_password: passwordSchema,
    confirm: z.string(),
  })
  .refine((v) => v.new_password === v.confirm, { path: ["confirm"], message: "Passwords do not match" });
type Values = z.infer<typeof schema>;

export function ChangePasswordDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const { clearSession } = useAuth();
  const router = useRouter();
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<Values>({ resolver: zodResolver(schema) });

  async function onSubmit({ current_password, new_password }: Values) {
    try {
      await api("/auth/change-password", { method: "POST", body: { current_password, new_password } });
      // All sessions were revoked server-side, including this one.
      reset();
      onOpenChange(false);
      clearSession();
      toast.success("Password changed. Please sign in with your new password.");
      router.replace("/login");
    } catch (err) {
      const message = err instanceof ApiError ? err.message : "Could not change password";
      setError(err instanceof ApiError && err.status === 401 ? "current_password" : "root", { message });
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Change password</DialogTitle>
          <DialogDescription>You&apos;ll be signed out of all devices.</DialogDescription>
        </DialogHeader>
        <form id="change-password" onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4">
          {errors.root && <p role="alert" className="text-sm text-destructive">{errors.root.message}</p>}
          <FormField id="current_password" label="Current password" error={errors.current_password?.message}>
            <Input
              {...fieldAria("current_password", errors.current_password?.message)}
              type="password"
              autoComplete="current-password"
              {...register("current_password")}
            />
          </FormField>
          <FormField id="new_password" label="New password" error={errors.new_password?.message}>
            <Input
              {...fieldAria("new_password", errors.new_password?.message)}
              type="password"
              autoComplete="new-password"
              {...register("new_password")}
            />
          </FormField>
          <FormField id="confirm" label="Confirm new password" error={errors.confirm?.message}>
            <Input {...fieldAria("confirm", errors.confirm?.message)} type="password" autoComplete="new-password" {...register("confirm")} />
          </FormField>
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form="change-password" disabled={isSubmitting}>
            {isSubmitting && <Loader2 className="animate-spin" aria-hidden />}
            Change password
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
