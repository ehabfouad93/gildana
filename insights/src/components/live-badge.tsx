"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

const ago = (iso: string | null, now: number) => {
  if (!iso) return "not synced yet";
  const m = Math.max(0, Math.round((now - Date.parse(iso)) / 60_000));
  return m < 1 ? "just now" : m < 60 ? `${m} min ago` : m < 1440 ? `${Math.round(m / 60)} h ago` : `${Math.round(m / 1440)} d ago`;
};

/**
 * "● Live · updated 3 min ago". While a refresh is running the page reloads
 * itself every few seconds, and once more when it finishes.
 */
export function LiveBadge({ updatedAt, syncing }: { updatedAt: string | null; syncing: boolean }) {
  const router = useRouter();
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const tick = setInterval(() => setNow(Date.now()), 30_000);
    const poll = syncing ? setInterval(() => router.refresh(), 6_000) : null;
    // Even when idle, pick up the next automatic refresh without a manual reload.
    const idle = syncing ? null : setInterval(() => router.refresh(), 120_000);
    return () => { clearInterval(tick); if (poll) clearInterval(poll); if (idle) clearInterval(idle); };
  }, [syncing, router]);
  return (
    <span className="no-print inline-flex items-center gap-1.5 rounded-full bg-panel px-2.5 py-1 text-xs text-muted ring-1 ring-line" title="Ad platforms update their reporting every 15–30 minutes; this dashboard refreshes automatically.">
      <span className={`h-2 w-2 rounded-full ${syncing ? "animate-pulse bg-blue-500" : "bg-green-500"}`} />
      {syncing ? "Updating…" : "Live"} · updated {ago(updatedAt, now)}
    </span>
  );
}
