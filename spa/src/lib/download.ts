import { ApiError } from "@/lib/api";
import { siteConfig } from "@/lib/config";

/** Download an authenticated file (e.g. a medical document) using the session cookie. */
export async function downloadFile(path: string, fallbackName: string): Promise<void> {
  const res = await fetch(`${siteConfig.apiUrl}${path}`, { credentials: "same-origin", headers: { Accept: "*/*" } });
  if (!res.ok) {
    const detail = await res.json().then((d) => d?.detail).catch(() => null);
    throw new ApiError(res.status, typeof detail === "string" ? detail : "Download failed", detail);
  }
  const name = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(res.headers.get("content-disposition") ?? "")?.[1];
  const url = URL.createObjectURL(await res.blob());
  const a = Object.assign(document.createElement("a"), { href: url, download: name ? decodeURIComponent(name) : fallbackName });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10_000);
}
