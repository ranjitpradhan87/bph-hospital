"use client";

import { useCallback, useEffect, useState } from "react";

import { ApiError, api } from "@/lib/api";

type Query = Record<string, string | number | boolean | undefined | null>;

interface State<T> {
  key: string | null;
  nonce: number;
  data?: T;
  error?: ApiError;
}

/**
 * Minimal GET hook. Keeps the previous data while a new request is in flight (so tables don't
 * flash empty when paging) and exposes `reload()` for after mutations. Pass `null` to skip.
 */
export function useApi<T>(path: string | null, query?: Query) {
  const queryJson = JSON.stringify(query ?? {});
  const key = path ? `${path}?${queryJson}` : null;
  const [nonce, setNonce] = useState(0);
  const [state, setState] = useState<State<T>>({ key: null, nonce: -1 });

  useEffect(() => {
    if (!path) return;
    let cancelled = false;
    api<T>(path, { query: JSON.parse(queryJson) })
      .then((data) => !cancelled && setState({ key, nonce, data }))
      .catch((err) => {
        if (cancelled) return;
        const error = err instanceof ApiError ? err : new ApiError(0, "Unable to reach the server");
        setState((prev) => ({ key, nonce, data: prev.data, error }));
      });
    return () => {
      cancelled = true;
    };
  }, [path, queryJson, key, nonce]);

  const reload = useCallback(() => setNonce((n) => n + 1), []);
  const loading = key !== null && (state.key !== key || state.nonce !== nonce);
  return { data: state.data, error: loading ? undefined : state.error, loading, reload };
}
