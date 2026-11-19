import { siteConfig } from "@/lib/config";

/** Error shape returned by the API (`{"detail": ...}`), normalised for the UI. */
export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public detail?: unknown,
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** Field-level messages from a 422 response, keyed by field name. */
  get fieldErrors(): Record<string, string> {
    if (!Array.isArray(this.detail)) return {};
    return Object.fromEntries(
      this.detail
        .filter((e) => Array.isArray(e?.loc))
        .map((e) => [String(e.loc[e.loc.length - 1]), String(e.msg).replace(/^Value error, /, "")]),
    );
  }
}

type Query = Record<string, string | number | boolean | undefined | null>;

export interface RequestOptions extends Omit<RequestInit, "body"> {
  body?: unknown;
  query?: Query;
  /**
   * When true (default), a 401 means the session ended: the auth provider is told so it can
   * send the user to the sign-in page. Pass false for calls where 401 is an expected answer
   * (login, "who am I").
   */
  auth?: boolean;
}

// ---- session auth: the browser holds an httpOnly session cookie; mutating calls carry the CSRF token ----

let onUnauthorized: (() => void) | null = null;

/** The auth provider subscribes so an expired session updates the UI everywhere. */
export function subscribeUnauthorized(listener: (() => void) | null) {
  onUnauthorized = listener;
}

function csrfToken(): string | null {
  const match = /(?:^|; )XSRF-TOKEN=([^;]*)/.exec(document.cookie);
  return match ? decodeURIComponent(match[1]) : null;
}

/** Any response sets the XSRF-TOKEN cookie; this just makes sure we have one before a write. */
async function ensureCsrf(force = false): Promise<void> {
  if (!force && csrfToken()) return;
  await fetch(`${siteConfig.apiUrl}/health`, { credentials: "same-origin", headers: { Accept: "application/json" } }).catch(() => {});
}

function buildUrl(path: string, query?: Query): string {
  const url = new URL(`${siteConfig.apiUrl}${path}`, window.location.origin);
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== "") url.searchParams.set(key, String(value));
  }
  return url.pathname + url.search;
}

function messageFrom(detail: unknown, fallback: string): string {
  if (typeof detail === "string") return detail;
  // Validation errors: [{loc, msg, type}]
  if (Array.isArray(detail) && detail[0]?.msg) return String(detail[0].msg).replace(/^Value error, /, "");
  return fallback;
}

export async function api<T>(path: string, options: RequestOptions = {}, retried = false): Promise<T> {
  const { body, query, auth = true, headers, method = "GET", ...init } = options;
  const writes = method !== "GET" && method !== "HEAD";
  if (writes) await ensureCsrf();

  const token = csrfToken();
  let res: Response;
  try {
    res = await fetch(buildUrl(path, query), {
      ...init,
      method,
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        ...(body !== undefined && !(body instanceof FormData) ? { "Content-Type": "application/json" } : {}),
        ...(token ? { "X-XSRF-TOKEN": token } : {}),
        ...headers,
      },
      body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, "Unable to reach the server");
  }

  if (res.status === 419 && writes && !retried) {
    await ensureCsrf(true); // the CSRF token expired with the session: fetch a fresh one and retry once
    return api<T>(path, options, true);
  }
  if (res.status === 204) return undefined as T;
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    if (res.status === 401 && auth) onUnauthorized?.();
    const detail = data?.detail;
    throw new ApiError(res.status, messageFrom(detail, res.statusText || "Request failed"), detail);
  }
  return data as T;
}

/** Kept for components written against the old token-based client; sessions need no token. */
export const getAccessToken = (): null => null;
