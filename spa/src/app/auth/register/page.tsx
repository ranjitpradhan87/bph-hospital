"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Loader2 } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { z } from "zod";

import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { ApiError, api } from "@/lib/api";
import { homeFor, useAuth } from "@/lib/auth/auth-provider";
import { emailSchema, mobileSchema, nameSchema, passwordSchema } from "@/lib/validation";
import { BLOOD_GROUPS, type TokenResponse } from "@/types/api";

const today = () => new Date().toISOString().slice(0, 10);

const schema = z
  .object({
    full_name: nameSchema,
    email: emailSchema,
    mobile: mobileSchema,
    date_of_birth: z
      .string()
      .min(1, "Date of birth is required")
      .refine((v) => v < today(), "Date of birth must be in the past"),
    gender: z.enum(["male", "female", "other"], { message: "Select a gender" }),
    blood_group: z.union([z.enum(BLOOD_GROUPS), z.literal("")]).optional(),
    address: z.string().trim().max(500).optional(),
    password: passwordSchema,
    confirm_password: z.string(),
    consent: z.boolean().refine((v) => v, "You must agree to continue"),
  })
  .refine((v) => v.password === v.confirm_password, {
    path: ["confirm_password"],
    message: "Passwords do not match",
  });

type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

export default function RegisterPage() {
  const router = useRouter();
  const { status, user, signIn } = useAuth();
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: { gender: undefined, blood_group: "", consent: false },
  });

  useEffect(() => {
    if (status === "authenticated" && user) router.replace(homeFor(user));
  }, [status, user, router]);

  async function onSubmit(values: Output) {
    setFormError(null);
    try {
      const session = await api<TokenResponse>("/auth/register", {
        method: "POST",
        auth: false,
        body: {
          full_name: values.full_name,
          email: values.email,
          mobile: values.mobile,
          date_of_birth: values.date_of_birth,
          gender: values.gender,
          blood_group: values.blood_group || null,
          address: values.address || null,
          password: values.password,
          consent: values.consent,
        },
      });
      signIn(session);
      toast.success(`Welcome! Your patient ID is ${session.user.patient_code}`);
      router.replace("/portal");
    } catch (err) {
      if (err instanceof ApiError) {
        const fields = err.fieldErrors;
        for (const [name, message] of Object.entries(fields)) {
          setError(name as keyof Input, { message });
        }
        if (!Object.keys(fields).length) setFormError(err.message);
      } else {
        setFormError("Unable to reach the server. Please try again.");
      }
    }
  }

  const e = errors;
  return (
    <Card className="w-full max-w-2xl">
      <CardHeader>
        <CardTitle className="font-heading text-xl">Create your patient account</CardTitle>
        <CardDescription>Book appointments, view bills and manage your visits online.</CardDescription>
      </CardHeader>
      <form onSubmit={handleSubmit(onSubmit)} noValidate>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          {formError && (
            <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive sm:col-span-2">
              {formError}
            </p>
          )}
          <FormField id="full_name" label="Full name" error={e.full_name?.message} className="sm:col-span-2">
            <Input {...fieldAria("full_name", e.full_name?.message)} autoComplete="name" {...register("full_name")} />
          </FormField>
          <FormField id="email" label="Email" error={e.email?.message}>
            <Input {...fieldAria("email", e.email?.message)} type="email" autoComplete="email" {...register("email")} />
          </FormField>
          <FormField id="mobile" label="Mobile number" error={e.mobile?.message}>
            <Input
              {...fieldAria("mobile", e.mobile?.message)}
              type="tel"
              inputMode="tel"
              autoComplete="tel-national"
              placeholder="98765 43210"
              {...register("mobile")}
            />
          </FormField>
          <FormField id="date_of_birth" label="Date of birth" error={e.date_of_birth?.message}>
            <Input
              {...fieldAria("date_of_birth", e.date_of_birth?.message)}
              type="date"
              autoComplete="bday"
              {...register("date_of_birth")}
            />
          </FormField>
          <FormField id="gender" label="Gender" error={e.gender?.message}>
            <NativeSelect {...fieldAria("gender", e.gender?.message)} defaultValue="" {...register("gender")}>
              <option value="" disabled>
                Select…
              </option>
              <option value="female">Female</option>
              <option value="male">Male</option>
              <option value="other">Other</option>
            </NativeSelect>
          </FormField>
          <FormField id="blood_group" label="Blood group" optional error={e.blood_group?.message}>
            <NativeSelect {...fieldAria("blood_group")} {...register("blood_group")}>
              <option value="">Not sure</option>
              {BLOOD_GROUPS.map((g) => (
                <option key={g} value={g}>
                  {g}
                </option>
              ))}
            </NativeSelect>
          </FormField>
          <FormField id="address" label="Address" optional error={e.address?.message} className="sm:col-span-2">
            <Textarea {...fieldAria("address")} rows={2} autoComplete="street-address" {...register("address")} />
          </FormField>
          <FormField
            id="password"
            label="Password"
            error={e.password?.message}
            hint="At least 8 characters with a letter and a number"
          >
            <Input
              {...fieldAria("password", e.password?.message, "hint")}
              type="password"
              autoComplete="new-password"
              {...register("password")}
            />
          </FormField>
          <FormField id="confirm_password" label="Confirm password" error={e.confirm_password?.message}>
            <Input
              {...fieldAria("confirm_password", e.confirm_password?.message)}
              type="password"
              autoComplete="new-password"
              {...register("confirm_password")}
            />
          </FormField>
          <div className="grid gap-1.5 sm:col-span-2">
            <label className="flex items-start gap-2.5 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 size-4 accent-primary"
                aria-invalid={e.consent ? true : undefined}
                aria-describedby={e.consent ? "consent-error" : undefined}
                {...register("consent")}
              />
              <span className="text-muted-foreground">
                I consent to the hospital processing my personal and health data to provide care, as described in
                the privacy notice (Digital Personal Data Protection Act, 2023).
              </span>
            </label>
            {e.consent && (
              <p id="consent-error" role="alert" className="text-xs text-destructive">
                {e.consent.message}
              </p>
            )}
          </div>
        </CardContent>
        <CardFooter className="mt-6 flex-col gap-3 sm:flex-row sm:justify-between">
          <p className="text-sm text-muted-foreground">
            Already registered?{" "}
            <Link href="/login" className="font-medium text-primary hover:underline">
              Sign in
            </Link>
          </p>
          <Button type="submit" size="lg" className="w-full sm:w-auto" disabled={isSubmitting}>
            {isSubmitting && <Loader2 className="animate-spin" aria-hidden />}
            Create account
          </Button>
        </CardFooter>
      </form>
    </Card>
  );
}
