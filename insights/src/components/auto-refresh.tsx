"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";

/** Re-renders the page every few seconds while something is still syncing. */
export function AutoRefresh({ active, everyMs = 8000 }: { active: boolean; everyMs?: number }) {
  const router = useRouter();
  useEffect(() => {
    if (!active) return;
    const t = setInterval(() => router.refresh(), everyMs);
    return () => clearInterval(t);
  }, [active, everyMs, router]);
  return null;
}

/** "Select all" for the checkboxes that belong to one bulk form. */
export function SelectAll({ form }: { form: string }) {
  return (
    <input type="checkbox" aria-label="Select all" onChange={(e) => {
      document.querySelectorAll<HTMLInputElement>(`input[type=checkbox][form="${form}"][name=ids]`).forEach((c) => (c.checked = e.target.checked));
    }} />
  );
}
