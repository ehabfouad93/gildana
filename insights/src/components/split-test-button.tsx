"use client";

import { useState, useTransition } from "react";
import { splitAdSetAction } from "@/app/actions/split-test";

/** Picks interests from a Meta ad set and splits them into one paused ad set each, after a preview. */
export function SplitTestButton({ adSetId, interests, dailyBudget, currency }: { adSetId: string; interests: { id: string; value: string }[]; dailyBudget: number | null; currency: string }) {
  const [open, setOpen] = useState(false);
  const [picked, setPicked] = useState<string[]>(interests.map((i) => i.id));
  const [msg, setMsg] = useState<{ ok: boolean; message: string } | null>(null);
  const [pending, start] = useTransition();
  const each = dailyBudget && picked.length ? dailyBudget / picked.length : null;
  const fmt = (n: number) => new Intl.NumberFormat("en-US", { style: "currency", currency, maximumFractionDigits: 2 }).format(n);

  if (msg?.ok) return <p className="text-xs text-good">✓ {msg.message || "Split test created."}</p>;
  if (!open) return <button type="button" className="btn-ghost !py-1 text-xs" onClick={() => setOpen(true)}>Split test these interests</button>;
  return (
    <div className="mt-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm">
      <p className="mb-2">Creates <b>one ad set per interest</b> in the same campaign, with the same ads, optimisation, placements and other targeting — so Meta reports each interest&apos;s cost per result directly. New ad sets are created <b>paused</b>.</p>
      <div className="mb-2 flex flex-wrap gap-2">
        {interests.map((i) => (
          <label key={i.id} className="pill cursor-pointer bg-white">
            <input type="checkbox" className="me-1" checked={picked.includes(i.id)} onChange={(e) => setPicked((p) => (e.target.checked ? [...p, i.id] : p.filter((x) => x !== i.id)))} />
            {i.value}
          </label>
        ))}
      </div>
      <p className="mb-2 text-xs text-muted">{picked.length} ad sets{each ? `, about ${fmt(each)}/day each (the original's budget split evenly)` : ", using the campaign budget"}. Run them for at least 7 days and ~50 results each before deciding.</p>
      {msg && !msg.ok && <p className="mb-2 text-xs text-bad">{msg.message}</p>}
      <div className="flex gap-2">
        <button type="button" className="btn !py-1" disabled={pending || picked.length < 2} onClick={() => start(async () => setMsg(await splitAdSetAction(adSetId, picked)))}>
          {pending ? "Creating…" : `Create ${picked.length} ad sets`}
        </button>
        <button type="button" className="btn-ghost !py-1" onClick={() => setOpen(false)}>Cancel</button>
      </div>
    </div>
  );
}
