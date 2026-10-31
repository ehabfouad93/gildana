"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

/**
 * Captures the page screenshot behind a heatmap. With `auto`, it captures by
 * itself the first time a page/device has no screenshot (once per browser
 * session, so a failure doesn't loop).
 */
export function SnapshotButton({ siteId, path, device, auto = false, label = "Capture page snapshot" }: { siteId: string; path: string; device: string; auto?: boolean; label?: string }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const router = useRouter();

  const capture = useCallback(async () => {
    setBusy(true); setErr("");
    const r = await fetch(`/api/sites/${siteId}/snapshot`, { method: "POST", body: JSON.stringify({ path, device }), headers: { "content-type": "application/json" } }).catch(() => null);
    setBusy(false);
    if (!r || !r.ok) setErr((await r?.json().catch(() => ({})))?.error ?? "Capture failed");
    else router.refresh();
  }, [siteId, path, device, router]);

  useEffect(() => {
    if (!auto) return;
    const key = `gi_snap:${siteId}:${path}:${device}`;
    try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, "1"); } catch { /* storage blocked */ }
    capture();
  }, [auto, siteId, path, device, capture]);

  return (
    <span className="inline-flex items-center gap-2">
      <button type="button" className="btn-ghost" disabled={busy} onClick={capture}>{busy ? "Capturing page…" : label}</button>
      {err && <span className="max-w-xl text-xs text-bad">{err}</span>}
    </span>
  );
}
