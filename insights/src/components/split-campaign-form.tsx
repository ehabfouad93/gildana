"use client";

import Link from "next/link";
import { useActionState, useRef, useState } from "react";
import { SendForApproval } from "@/components/approval-buttons";
import { createSplitCampaignAction } from "@/app/actions/split-test";

export type PickOption = { key: string; value: string; type: string; known: boolean };
export type SourceOption = { id: string; label: string; currency: string; dailyBudget: number | null; ads: number; demo: boolean; canWrite: boolean };

/** Builds a split-test campaign: one ad set per ticked interest, copied from a source ad set. */
export function SplitCampaignForm({ picks, sources, defaultSource, defaultName, canApprove = false }: { picks: PickOption[]; sources: SourceOption[]; defaultSource: string; defaultName: string; canApprove?: boolean }) {
  const nameRef = useRef<HTMLInputElement>(null);
  const [res, action, pending] = useActionState(createSplitCampaignAction, null);
  const [chosen, setChosen] = useState<string[]>(picks.slice(0, 10).map((p) => p.key));
  const [sourceId, setSourceId] = useState(defaultSource);
  const source = sources.find((s) => s.id === sourceId);
  const [budget, setBudget] = useState("");
  const [days, setDays] = useState("7");
  const [start, setStart] = useState("paused");
  const cur = source?.currency ?? "USD";
  const fmt = (n: number) => new Intl.NumberFormat("en-US", { style: "currency", currency: cur, maximumFractionDigits: 0 }).format(n);
  const perDay = Number(budget) || 0;
  const total = perDay * chosen.length * (Number(days) || 0);

  if (res?.ok) {
    return (
      <div className="card border-green-200 bg-green-50">
        <p className="font-medium text-good">✓ {res.message}</p>
        <div className="mt-3 flex gap-2"><Link className="btn" href="/campaigns">Open campaigns</Link><Link className="btn-ghost" href="/audiences?v=suggest">Back to suggestions</Link></div>
      </div>
    );
  }
  return (
    <form action={action} className="space-y-4">
      <div className="card">
        <h2 className="mb-1 font-semibold">1. Interests to test <span className="text-sm font-normal text-muted">({chosen.length} ticked, max 10)</span></h2>
        <p className="mb-3 text-xs text-muted">Each ticked interest gets its own ad set, so Meta reports its result exactly.</p>
        <div className="flex flex-wrap gap-2">
          {picks.map((p) => (
            <label key={p.key} className={`pill cursor-pointer !px-3 !py-1.5 ${chosen.includes(p.key) ? "bg-accent text-white" : "bg-bg text-ink"}`}>
              <input type="checkbox" className="me-1.5" name="pick" value={p.key} checked={chosen.includes(p.key)}
                onChange={(e) => setChosen((c) => (e.target.checked ? [...c, p.key] : c.filter((x) => x !== p.key)))} />
              {p.value}{!p.known && <span className="ms-1 opacity-70">· new</span>}
            </label>
          ))}
        </div>
        {picks.some((p) => !p.known) && <p className="mt-2 text-xs text-muted">“New” interests aren&apos;t in your account yet; they are looked up on Meta when the campaign is created, and left out if Meta has no match.</p>}
      </div>

      <div className="card space-y-3">
        <h2 className="font-semibold">2. Copy ads and settings from</h2>
        <select className="input" name="sourceAdSetId" value={sourceId} onChange={(e) => setSourceId(e.target.value)}>
          {sources.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
        </select>
        <p className="text-xs text-muted">The new ad sets use this ad set&apos;s ads ({source?.ads ?? 0}), optimisation, placements, location and age — only the interest changes, and Advantage+ audience is switched off so Meta stays inside each interest.</p>
        {source && !source.canWrite && <p className="text-sm text-amber-700">This Meta login can only read. Reconnect it with “Connect with split tests” on the <Link className="underline" href="/connections">Ad accounts</Link> page first.</p>}
      </div>

      <div className="card space-y-3">
        <h2 className="font-semibold">3. Budget and timing</h2>
        <div className="grid gap-3 sm:grid-cols-3">
          <div><label className="label">Campaign name</label><input className="input" name="name" ref={nameRef} defaultValue={defaultName} maxLength={200} /></div>
          <div><label className="label">Daily budget per ad set ({cur})</label><input className="input" name="dailyBudget" type="number" min="1" step="any" required value={budget} onChange={(e) => setBudget(e.target.value)} placeholder={source?.dailyBudget ? String(Math.round(source.dailyBudget)) : ""} /></div>
          <div><label className="label">Run for (days, 0 = no end)</label><input className="input" name="days" type="number" min="0" max="90" value={days} onChange={(e) => setDays(e.target.value)} /></div>
        </div>
        <div className="flex flex-wrap gap-4 text-sm">
          <label className="flex items-center gap-1"><input type="radio" name="start" value="paused" checked={start === "paused"} onChange={() => setStart("paused")} /> Create paused — I&apos;ll review and start it in Ads Manager</label>
          <label className="flex items-center gap-1"><input type="radio" name="start" value="now" checked={start === "now"} onChange={() => setStart("now")} /> Start now</label>
        </div>
        <p className="rounded-lg bg-bg p-3 text-sm">
          {chosen.length} ad set{chosen.length === 1 ? "" : "s"} × {perDay ? fmt(perDay) : "—"}/day{Number(days) > 0 ? ` × ${days} days = up to ${perDay ? fmt(total) : "—"}` : " with no end date"}.
          {start === "now" ? <b className="text-bad"> It starts spending as soon as Meta approves the ads.</b> : " Nothing is spent until you switch it on."}
          {" "}For a fair test, give each interest enough budget for about 10 results.
        </p>
        {source?.demo && <p className="text-xs text-muted">Demo login: the campaign is simulated, nothing is sent to Meta.</p>}
      </div>

      {res && !res.ok && <p className="text-sm text-bad">{res.message}</p>}
      <div className="flex gap-2">
        <button className="btn" disabled={pending || !chosen.length || chosen.length > 10 || !source}>{pending ? "Creating…" : `Create campaign with ${chosen.length} ad set${chosen.length === 1 ? "" : "s"}`}</button>
        <Link className="btn-ghost" href="/audiences?v=suggest">Cancel</Link>
      </div>
      {canApprove && <SendForApproval kind="split_campaign" disabled={!chosen.length || chosen.length > 10 || !source || !(perDay > 0)} payload={() => ({ sourceAdSetId: sourceId, pick: chosen, name: nameRef.current?.value ?? defaultName, dailyBudget: perDay, days: Number(days) || 0, start })} />}
    </form>
  );
}
