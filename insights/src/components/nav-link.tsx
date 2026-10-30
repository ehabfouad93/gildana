"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

export function NavLink({ href, children }: { href: string; children: React.ReactNode }) {
  const path = usePathname();
  const active = path === href || (href !== "/" && path.startsWith(href + "/"));
  return (
    <Link
      href={href}
      className={`block rounded-lg px-3 py-2 text-sm transition ${active ? "bg-white/10 font-medium text-white" : "text-side-ink hover:bg-white/5 hover:text-white"}`}
    >
      {children}
    </Link>
  );
}
