"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Eye, EyeOff, Loader2 } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";

import { FormField, fieldAria } from "@/components/shared/form-field";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { ApiError, api } from "@/lib/api";
import { homeFor, useAuth } from "@/lib/auth/auth-provider";
import type { Me, TokenResponse } from "@/types/api";

const schema = z.object({
  identifier: z.string().trim().min(3, "Enter your email or mobile number"),
  password: z.string().min(1, "Enter your password"),
});
type Values = z.infer<typeof schema>;

/**
 * Where to go after sign-in. Only same-site relative paths are honoured (no open redirects), and
 * only if the area suits the user — e.g. a receptionist is never sent to the patient portal.
 */
function destinationFor(user: Me): string {
  const next = new URLSearchParams(window.location.search).get("next");
  const home = homeFor(user);
  if (!next || !next.startsWith("/") || next.startsWith("//")) return home;
  const area = next.startsWith("/admin") ? "/admin" : next.startsWith("/portal") ? "/portal" : null;
  return area && area !== home ? home : next;
}

export default function LoginPage() {
  const router = useRouter();
  const { status, user, signIn } = useAuth();
  const [showPassword, setShowPassword] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<Values>({ resolver: zodResolver(schema) });

  useEffect(() => {
    if (status === "authenticated" && user) router.replace(destinationFor(user));
  }, [status, user, router]);

  async function onSubmit(values: Values) {
    setFormError(null);
    try {
      const session = await api<TokenResponse>("/auth/login", { method: "POST", body: values, auth: false });
      signIn(session);
      router.replace(destinationFor(session.user));
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : "Unable to reach the server. Please try again.");
    }
  }

  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <CardTitle className="font-heading text-xl">Sign in</CardTitle>
        <CardDescription>Patients and hospital staff sign in here.</CardDescription>
      </CardHeader>
      <form onSubmit={handleSubmit(onSubmit)} noValidate>
        <CardContent className="grid gap-4">
          {formError && (
            <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {formError}
            </p>
          )}
          <FormField id="identifier" label="Email or mobile number" error={errors.identifier?.message}>
            <Input
              {...fieldAria("identifier", errors.identifier?.message)}
              autoComplete="username"
              inputMode="email"
              autoFocus
              {...register("identifier")}
            />
          </FormField>
          <FormField id="password" label="Password" error={errors.password?.message}>
            <div className="relative">
              <Input
                {...fieldAria("password", errors.password?.message)}
                type={showPassword ? "text" : "password"}
                autoComplete="current-password"
                className="pr-9"
                {...register("password")}
              />
              <button
                type="button"
                onClick={() => setShowPassword((s) => !s)}
                className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                aria-label={showPassword ? "Hide password" : "Show password"}
              >
                {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
              </button>
            </div>
          </FormField>
        </CardContent>
        <CardFooter className="mt-6 flex-col gap-3">
          <Button type="submit" className="w-full" size="lg" disabled={isSubmitting}>
            {isSubmitting && <Loader2 className="animate-spin" aria-hidden />}
            Sign in
          </Button>
          <p className="text-sm text-muted-foreground">
            New patient?{" "}
            <Link href="/register" className="font-medium text-primary hover:underline">
              Create an account
            </Link>
          </p>
        </CardFooter>
      </form>
    </Card>
  );
}
