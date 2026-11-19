import { Loader2 } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";

import { Button, buttonVariants } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError, api } from "@/lib/api";
import { homeFor, useAuth } from "@/lib/auth/auth-provider";
import { passwordSchema } from "@/lib/validation";
import type { TokenResponse } from "@/types/api";

/** Opened from the text message sent after a booking: choose a password, then go straight to the portal. */
export default function SetPasswordPage() {
  const params = useSearchParams();
  const router = useRouter();
  const { signIn } = useAuth();
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const link = { u: Number(params.get("u")), e: Number(params.get("e")), s: params.get("s") ?? "" };
  const broken = !link.u || !link.e || !link.s;

  async function submit(ev: React.FormEvent) {
    ev.preventDefault();
    setError("");
    const check = passwordSchema.safeParse(password);
    if (!check.success) return setError(check.error.issues[0]?.message ?? "Choose a stronger password");
    if (password !== confirm) return setError("The two passwords do not match");
    setBusy(true);
    try {
      const session = await api<TokenResponse>("/auth/set-password", { method: "POST", body: { ...link, password } });
      signIn(session);
      router.replace(homeFor(session.user));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not save the password");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="w-full max-w-md">
      <CardHeader>
        <CardTitle className="font-heading text-2xl">Set your password</CardTitle>
        <CardDescription>Choose a password for your patient account. Next time, sign in with your mobile number and this password.</CardDescription>
      </CardHeader>
      {broken ? (
        <CardContent><p role="alert" className="text-sm text-destructive">This link is not complete. Open it again from the text message, or sign in.</p></CardContent>
      ) : (
        <form onSubmit={submit} noValidate>
          <CardContent className="grid gap-4">
            <div className="grid gap-1.5"><Label htmlFor="sp-pw">New password</Label>
              <Input id="sp-pw" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} autoFocus />
              <p className="text-xs text-muted-foreground">At least 8 characters with letters and numbers.</p></div>
            <div className="grid gap-1.5"><Label htmlFor="sp-pw2">Confirm password</Label>
              <Input id="sp-pw2" type="password" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} /></div>
            {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
            <Button type="submit" disabled={busy || !password}>{busy && <Loader2 className="animate-spin" aria-hidden />} Save password and continue</Button>
          </CardContent>
        </form>
      )}
      <CardFooter><Link href="/login" className={buttonVariants({ variant: "link" })}>Back to sign in</Link></CardFooter>
    </Card>
  );
}
