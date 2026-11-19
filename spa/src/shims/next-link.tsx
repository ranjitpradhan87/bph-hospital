import { forwardRef } from "react";
import { Link as RouterLink } from "react-router-dom";

/** Paths handled by this React app; everything else (home, doctors, …) is a server-rendered page. */
const SPA_PREFIXES = ["/admin", "/portal", "/login", "/register"];
const isSpaPath = (href: string) => SPA_PREFIXES.some((p) => href === p || href.startsWith(`${p}/`) || href.startsWith(`${p}?`));

type Props = Omit<React.AnchorHTMLAttributes<HTMLAnchorElement>, "href"> & { href: string | { pathname?: string }; prefetch?: boolean; replace?: boolean };

/** Drop-in for next/link: client-side navigation inside the app, a normal link to the public site. */
const Link = forwardRef<HTMLAnchorElement, Props>(function Link({ href, prefetch: _prefetch, replace, ...rest }, ref) {
  const to = typeof href === "string" ? href : (href.pathname ?? "/");
  if (isSpaPath(to)) return <RouterLink ref={ref} to={to} replace={replace} {...rest} />;
  return <a ref={ref} href={to} {...rest} />;
});

export default Link;
