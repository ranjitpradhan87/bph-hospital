/** Uploaded media is served by the same origin, so stored paths work as they are. */
export function mediaUrl(url: string | null | undefined): string | null {
  return url || null;
}
