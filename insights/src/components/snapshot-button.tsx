"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

export function SnapshotButton({ siteId, path, device }: { siteId: string; path: string; device: string }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");
  const router = useRouter();
  return (
    <span className="inline-flex items-center gap-2">
      <button type="button" className="btn-ghost" disabled={busy} onClick={async () => {
        setBusy(true); setErr("");
        const r = await fetch(`/api/sites/${siteId}/snapshot`, { method: "POST", body: JSON.stringify({ path, device }), headers: { "content-type": "application/json" } });
        setBusy(false);
        if (!r.ok) setErr((await r.json().catch(() => ({}))).error ?? "Capture failed");
        else router.refresh();
      }}>{busy ? "Capturing…" : "Capture page snapshot"}</button>
      {err && <span className="text-xs text-bad">{err}</span>}
    </span>
  );
}
