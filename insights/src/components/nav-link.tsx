"use client";

import Link, { useLinkStatus } from "next/link";
import { usePathname } from "next/navigation";

/** Small spinner while the clicked page loads. */
function Pending() {
  const { pending } = useLinkStatus();
  return pending ? <span className="ms-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-white/30 border-t-white align-middle" aria-hidden /> : null;
}

export function NavLink({ href, children }: { href: string; children: React.ReactNode }) {
  const path = usePathname();
  const active = path === href || (href !== "/" && path.startsWith(href + "/"));
  return (
    <Link
      href={href}
      className={`block rounded-lg px-3 py-2 text-sm transition ${active ? "bg-white/10 font-medium text-white" : "text-side-ink hover:bg-white/5 hover:text-white"}`}
    >
      {children}
      <Pending />
    </Link>
  );
}
