/** The public pages are rendered live by Laravel, so there is nothing to refresh after an edit. */
export async function refreshPublicContent(_unused?: unknown): Promise<boolean> {
  return true;
}
