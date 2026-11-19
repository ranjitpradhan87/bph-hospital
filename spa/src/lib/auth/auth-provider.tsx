import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

import { api, subscribeUnauthorized } from "@/lib/api";
import type { Me, TokenResponse } from "@/types/api";

type Status = "loading" | "authenticated" | "anonymous";

interface AuthContextValue {
  status: Status;
  user: Me | null;
  /** Store the session returned by login/register. */
  signIn: (session: TokenResponse) => void;
  signOut: () => Promise<void>;
  /** Drop the local session without calling the API (e.g. after the server ended it). */
  clearSession: () => void;
  can: (...permissions: string[]) => boolean;
  canAny: (...permissions: string[]) => boolean;
  isStaff: boolean;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<Status>("loading");
  const [user, setUser] = useState<Me | null>(null);

  const apply = useCallback((session: { user: Me } | null) => {
    setUser(session?.user ?? null);
    setStatus(session ? "authenticated" : "anonymous");
  }, []);

  useEffect(() => {
    subscribeUnauthorized(() => apply(null));
    // The session lives in an httpOnly cookie; ask the API who we are.
    api<Me>("/auth/me", { auth: false })
      .then((me) => apply({ user: me }))
      .catch(() => apply(null));
    return () => subscribeUnauthorized(null);
  }, [apply]);

  const signOut = useCallback(async () => {
    try {
      await api("/auth/logout", { method: "POST", auth: false });
    } finally {
      apply(null);
    }
  }, [apply]);

  const value = useMemo<AuthContextValue>(() => {
    const perms = new Set(user?.permissions ?? []);
    return {
      status,
      user,
      signIn: apply,
      signOut,
      clearSession: () => apply(null),
      can: (...p) => p.every((x) => perms.has(x)),
      canAny: (...p) => p.some((x) => perms.has(x)),
      isStaff: !!user && user.roles.some((r) => r !== "patient"),
    };
  }, [status, user, apply, signOut]);

  return <AuthContext value={value}>{children}</AuthContext>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside <AuthProvider>");
  return ctx;
}

/** Where a user lands after signing in. */
export function homeFor(user: Me): string {
  return user.roles.some((r) => r !== "patient") ? "/admin" : "/portal";
}
