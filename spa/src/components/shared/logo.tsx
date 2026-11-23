/** The Bishnupriya Seva Sadan logo (served from /img, so it is also what the public site uses). */
export function Logo({ className = "h-9" }: { className?: string }) {
  return <img src="/img/logo.svg" alt="Bishnupriya Seva Sadan" className={`w-auto ${className}`} />;
}
