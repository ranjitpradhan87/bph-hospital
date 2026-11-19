import { useMemo } from "react";
import { useLocation, useNavigate, useParams as useRouterParams, useSearchParams as useRouterSearchParams } from "react-router-dom";

const SPA_PREFIXES = ["/admin", "/portal", "/login", "/register"];
const isSpaPath = (href: string) => SPA_PREFIXES.some((p) => href === p || href.startsWith(`${p}/`) || href.startsWith(`${p}?`));

export function useRouter() {
  const navigate = useNavigate();
  return useMemo(
    () => ({
      push: (href: string) => (isSpaPath(href) ? navigate(href) : window.location.assign(href)),
      replace: (href: string) => (isSpaPath(href) ? navigate(href, { replace: true }) : window.location.replace(href)),
      back: () => navigate(-1),
      refresh: () => {},
    }),
    [navigate],
  );
}

export const usePathname = () => useLocation().pathname;
export const useParams = <T extends Record<string, string> = Record<string, string>>() => useRouterParams() as unknown as T;
export const useSearchParams = () => useRouterSearchParams()[0];
