"use client";

import Link from "next/link";
import { useState, useTransition } from "react";
import { mixerLaunchAction, mixerReachAction, mixerSearchAction, type ToolResult } from "@/app/actions/audience-tools";
import { SendForApproval } from "@/components/approval-buttons";

export type Item = { type: string; value: string; externalId: string; note?: string };
export type Source = { id: string; label: string; currency: string; ads: number };

const TONE: Record<string, string> = { interest: "bg-blue-100 text-blue-800", behavior: "bg-violet-100 text-violet-800", audience: "bg-emerald-100 text-emerald-800", lookalike: "bg-amber-100 text-amber-800" };
const same = (a: Item, b: Item) => a.type === b.type && a.externalId === b.externalId;

/** One group of the mix: chips, plus a picker over the account's own items and a Meta search. */
function Group({ title, hint, items, onChange, available, accountId }: { title: string; hint: string; items: Item[]; onChange: (x: Item[]) => void; available: Item[]; accountId: string }) {
  const [q, setQ] = useState("");
  const [found, setFound] = useState<Item[]>([]);
  const [msg, setMsg] = useState("");
  const [pending, start] = useTransition();
  const add = (i: Item) => { if (!items.some((x) => same(x, i))) onChange([...items, i]); };
  const mine = available.filter((a) => !items.some((x) => same(x, a)) && (!q || a.value.toLowerCase().includes(q.toLowerCase()))).slice(0, 12);
  return (
    <div className="rounded-lg border border-line p-3">
      <div className="mb-1 text-sm font-semibold">{title}</div>
      <p className="mb-2 text-xs text-muted">{hint}</p>
      <div className="mb-2 flex min-h-8 flex-wrap gap-1.5">
        {items.map((i) => (
          <button key={`${i.type}${i.externalId}`} type="button" className={`pill ${TONE[i.type] ?? "bg-bg"}`} onClick={() => onChange(items.filter((x) => !same(x, i)))} title="Remove">{i.value}<span className="ms-1">×</span></button>
        ))}
        {!items.length && <span className="text-xs text-muted">Nothing added yet.</span>}
      </div>
      <div className="flex gap-2">
        <input className="input !py-1" placeholder="Filter your interests and audiences, or search Meta…" value={q} onChange={(e) => setQ(e.target.value)} />
        <button type="button" className="btn-ghost !py-1 shrink-0" disabled={pending || q.trim().length < 2}
          onClick={() => start(async () => { const r = await mixerSearchAction(accountId, q); setFound(r.items.map((i) => ({ type: i.type, value: i.value, externalId: i.externalId ?? "", note: [i.size, i.path].filter(Boolean).join(" · ") || undefined }))); setMsg(r.ok ? (r.items.length ? "" : "Meta found nothing for that.") : r.message); })}>{pending ? "Searching…" : "Search Meta"}</button>
      </div>
      {msg && <p className="mt-1 text-xs text-bad">{msg}</p>}
      <div className="mt-2 flex flex-wrap gap-1.5">
        {mine.map((i) => <button key={`m${i.type}${i.externalId}`} type="button" className="pill bg-bg text-ink hover:bg-line" onClick={() => add(i)}>+ {i.value}{i.note && <span className="ms-1 text-muted">{i.note}</span>}</button>)}
        {found.filter((f) => !items.some((x) => same(x, f))).map((i) => <button key={`f${i.type}${i.externalId}`} type="button" className="pill border border-accent bg-panel text-ink" onClick={() => add(i)} title={i.note}>+ {i.value}{i.note && <span className="ms-1 text-muted">{i.note}</span>}</button>)}
      </div>
    </div>
  );
}

/** Builds an audience (OR inside a group, AND between groups, minus exclusions), checks its reach and launches it as an ad set. */
export function MixerForm({ accountId, available, sources, initial, defaultExclusions, canApprove = false }: { accountId: string; available: Item[]; sources: Source[]; initial: Item[]; defaultExclusions: Record<string, Item[]>; canApprove?: boolean }) {
  const [groups, setGroups] = useState<Item[][]>([initial]);
  const [stage, setStage] = useState("prospecting");
  const [exclusions, setExclusions] = useState<Item[]>(defaultExclusions.prospecting ?? []);
  const [sourceId, setSourceId] = useState(sources[0]?.id ?? "");
  const [name, setName] = useState("");
  const [budget, setBudget] = useState("");
  const [days, setDays] = useState("0");
  const [start, setStart] = useState("paused");
  const [where, setWhere] = useState("same");
  const [reach, setReach] = useState<ToolResult | null>(null);
  const [res, setRes] = useState<ToolResult | null>(null);
  const [pending, go] = useTransition();
  const source = sources.find((s) => s.id === sourceId);
  const mix = { accountId, sourceAdSetId: sourceId, groups, exclusions };
  const filled = groups.some((g) => g.length);

  if (res?.ok) {
    return (
      <div className="card border-green-200 bg-green-50">
        <p className="font-medium text-good">✓ {res.message}</p>
        <div className="mt-3 flex gap-2"><Link className="btn" href="/campaigns">Open campaigns</Link><button type="button" className="btn-ghost" onClick={() => setRes(null)}>Build another</button></div>
      </div>
    );
  }
  return (
    <div className="space-y-4">
      <div className="card space-y-3">
        <h2 className="font-semibold">1. Who to reach</h2>
        <p className="text-xs text-muted">People must match <b>every</b> group, and inside a group <b>any one</b> item is enough. One group = a simple “any of these” audience.</p>
        {groups.map((g, i) => (
          <div key={i}>
            {i > 0 && <div className="my-1 text-center text-xs font-semibold text-muted">AND</div>}
            <Group title={`Group ${i + 1}`} hint="Any of these (OR)" items={g} accountId={accountId} available={available}
              onChange={(x) => setGroups(groups.map((y, k) => (k === i ? x : y)))} />
          </div>
        ))}
        <div className="flex gap-2">
          {groups.length < 4 && <button type="button" className="btn-ghost !py-1" onClick={() => setGroups([...groups, []])}>+ AND another group (narrow)</button>}
          {groups.length > 1 && <button type="button" className="btn-ghost !py-1" onClick={() => setGroups(groups.slice(0, -1))}>Remove last group</button>}
        </div>
        <div className="flex flex-wrap items-center gap-2 pt-2 text-sm">
          <span className="text-muted">This ad set is for</span>
          <select className="input w-auto !py-1" value={stage} onChange={(e) => { setStage(e.target.value); setExclusions(defaultExclusions[e.target.value] ?? []); }}>
            <option value="prospecting">Prospecting (new people)</option><option value="reengagement">Re-engagement</option><option value="retargeting">Retargeting</option><option value="retention">Retention</option>
          </select>
          <span className="text-xs text-muted">— fills the exclusions with that stage&apos;s defaults (set on Ready-made audiences).</span>
        </div>
        <Group title="Exclude" hint="Nobody matching any of these is reached" items={exclusions} accountId={accountId} available={available} onChange={setExclusions} />
        <div className="flex flex-wrap items-center gap-3">
          <button type="button" className="btn-ghost" disabled={pending || !filled || !sourceId} onClick={() => go(async () => setReach(await mixerReachAction(mix)))}>{pending ? "Asking Meta…" : "Check reach"}</button>
          {reach && <span className={`text-sm ${reach.ok ? "" : "text-bad"}`}>{reach.message}</span>}
        </div>
      </div>

      <div className="card space-y-3">
        <h2 className="font-semibold">2. Launch it as an ad set</h2>
        <div><label className="label">Copy ads and settings from</label>
          <select className="input" value={sourceId} onChange={(e) => setSourceId(e.target.value)}>{sources.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}</select>
          <p className="mt-1 text-xs text-muted">Uses this ad set&apos;s ads ({source?.ads ?? 0}), optimisation, placements, location and age — only the audience changes. Advantage+ audience is switched off.</p></div>
        <div className="grid gap-3 sm:grid-cols-3">
          <div><label className="label">Ad set name (optional)</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} maxLength={200} placeholder="Named after the mix" /></div>
          <div><label className="label">Daily budget ({source?.currency ?? ""})</label><input className="input" type="number" min="1" step="any" value={budget} onChange={(e) => setBudget(e.target.value)} /></div>
          <div><label className="label">Run for (days, 0 = no end)</label><input className="input" type="number" min="0" max="90" value={days} onChange={(e) => setDays(e.target.value)} /></div>
        </div>
        <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
          <label className="flex items-center gap-1"><input type="radio" checked={where === "same"} onChange={() => setWhere("same")} /> In the same campaign</label>
          <label className="flex items-center gap-1"><input type="radio" checked={where === "new"} onChange={() => setWhere("new")} /> In a new campaign</label>
          <span className="text-line">|</span>
          <label className="flex items-center gap-1"><input type="radio" checked={start === "paused"} onChange={() => setStart("paused")} /> Create paused</label>
          <label className="flex items-center gap-1"><input type="radio" checked={start === "now"} onChange={() => setStart("now")} /> Start now</label>
        </div>
        {start === "now" && <p className="text-sm text-bad">It starts spending as soon as Meta approves the ads.</p>}
        {res && !res.ok && <p className="text-sm text-bad">{res.message}</p>}
        <button type="button" className="btn" disabled={pending || !filled || !sourceId || !(Number(budget) > 0)}
          onClick={() => { if (start === "now" && !confirm("Create this ad set and start it now? It will spend from the ad account.")) return; go(async () => setRes(await mixerLaunchAction({ ...mix, name, dailyBudget: Number(budget), days: Number(days) || 0, startNow: start === "now", newCampaign: where === "new" }))); }}>
          {pending ? "Working…" : "Create ad set"}
        </button>
        {canApprove && <div><SendForApproval kind="mixer" disabled={!filled || !sourceId || !(Number(budget) > 0)} payload={() => ({ ...mix, name, dailyBudget: Number(budget), days: Number(days) || 0, startNow: start === "now", newCampaign: where === "new" })} /></div>}
      </div>
    </div>
  );
}
