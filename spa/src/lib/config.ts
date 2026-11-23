declare global {
  interface Window {
    __BPH__?: { name?: string };
  }
}

export const siteConfig = {
  name: (typeof window !== "undefined" && window.__BPH__?.name) || "Bishnupriya Sevasadan",
  description: "Book appointments with trusted specialists and manage your care online.",
  /** Same origin as the site: the session cookie and CSRF token just work. */
  apiUrl: "/api/v1",
} as const;
