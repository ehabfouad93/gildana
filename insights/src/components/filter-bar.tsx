"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useMemo, useRef, useState } from "react";

export type PickAccount = { id: string; name: string; platform: string; externalId: string; company: string };

const PLATFORM_LABEL: Record<string, string> = { META: "Meta", GOOGLE: "Google Ads", LINKEDIN: "LinkedIn", SNAPCHAT: "Snapchat", TIKTOK: "TikTok" };
const SLUG: Record<string, string> = { META: "meta", GOOGLE: "google", LINKEDIN: "linkedin", SNAPCHAT: "snapchat", TIKTOK: "tiktok" };
const PRESETS: [string, string][] = [
  ["7", "Last 7 days"], ["14", "Last 14 days"], ["30", "Last 30 days"], ["90", "Last 90 days"], ["180", "Last 6 months"], ["365", "Last 12 months"],
  ["this_month", "This month"], ["last_month", "Last month"], ["this_year", "This year"], ["last_year", "Last year"], ["custom", "Custom range…"],
];

export type EntityPick = { level: "CAMPAIGN" | "ADSET" | "AD"; items: { id: string; name: string }[] };
const ENTITY_PARAM = { CAMPAIGN: "camp", ADSET: "set", AD: "ad" } as const;
const ENTITY_LABEL = { CAMPAIGN: "Campaigns", ADSET: "Ad sets", AD: "Ads" } as const;

type Props = {
  labels: { allCompanies: string; allPlatforms: string; apply: string };
  companies: { id: string; name: string }[];
  accounts?: PickAccount[];
  showPlatform?: boolean;
  range: { from: string; to: string };
  /** Shows the campaign / ad set / ad picker; null = on with nothing picked, undefined = off. */
  entity?: EntityPick | null;
  /** Tags of the workspace; with `entity` on, adds a tag / funnel-stage filter. */
  tags?: { id: string; name: string }[];
};

const STAGE_OPTIONS: [string, string][] = [["prospecting", "Prospecting"], ["reengagement", "Re-engagement"], ["retargeting", "Retargeting"], ["retention", "Retention"]];

/**
 * Filters for every report page, kept in the URL so views can be bookmarked
 * and shared: company, platform, any set of ad accounts, and a preset or custom date range.
 */
export function FilterBar({ labels, companies, accounts = [], showPlatform = true, range, entity, tags = [] }: Props) {
  const router = useRouter();
  const pathname = usePathname();
  const sp = useSearchParams();

  // With no date in the URL the page used the workspace's default range; show which one.
  const shownDays = String(Math.round((Date.parse(range.to) - Date.parse(range.from)) / 86_400_000) + 1);
  const initialPreset = sp.get("from") && sp.get("to") ? "custom" : sp.get("period") || sp.get("days") || (PRESETS.some(([k]) => k === shownDays) ? shownDays : "custom");
  const [preset, setPreset] = useState(initialPreset);
  const [from, setFrom] = useState(range.from);
  const [to, setTo] = useState(range.to);
  const [company, setCompany] = useState(sp.get("company") ?? "");
  const [platform, setPlatform] = useState(sp.get("platform") ?? "");
  // null = all accounts; otherwise exactly the ticked ones
  const [picked, setPicked] = useState<string[] | null>(() => { const a = (sp.get("acc") ?? "").split(",").filter(Boolean); return a.length ? a : null; });

  const [ent, setEnt] = useState<EntityPick>(entity ?? { level: "CAMPAIGN", items: [] });
  const [tag, setTag] = useState(sp.get("tag") ?? "");
  const [stage, setStage] = useState(sp.get("stage") ?? "");
  const companyName = company === "none" ? "Unassigned" : companies.find((c) => c.id === company)?.name;
  const visible = useMemo(() => accounts.filter((a) => (!platform || SLUG[a.platform] === platform) && (!company || a.company === companyName)), [accounts, platform, company, companyName]);

  const apply = () => {
    const q = new URLSearchParams(sp.toString());
    for (const k of ["company", "platform", "acc", "days", "period", "from", "to"]) q.delete(k);
    if (entity !== undefined) {
      for (const k of [...Object.values(ENTITY_PARAM), "tag", "stage"]) q.delete(k);
      if (ent.items.length) q.set(ENTITY_PARAM[ent.level], ent.items.map((x) => x.id).join(","));
      else { if (tag) q.set("tag", tag); if (stage) q.set("stage", stage); }
    }
    if (company) q.set("company", company);
    if (platform) q.set("platform", platform);
    const accs = (picked ?? []).filter((id) => visible.some((a) => a.id === id));
    if (picked && accs.length && accs.length < visible.length) q.set("acc", accs.join(","));
    if (preset === "custom") { if (from && to) { q.set("from", from <= to ? from : to); q.set("to", from <= to ? to : from); } }
    else if (/^\d+$/.test(preset)) q.set("days", preset);
    else q.set("period", preset);
    router.push(`${pathname}?${q.toString()}`);
  };

  return (
    <div className="no-print flex flex-wrap items-end gap-2">
      {(companies.length > 1 || accounts.some((a) => a.company === "Unassigned")) && (
        <select value={company} onChange={(e) => setCompany(e.target.value)} className="input w-auto" aria-label="Company">
          <option value="">{labels.allCompanies}</option>
          {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          {accounts.some((a) => a.company === "Unassigned") && <option value="none">Unassigned accounts</option>}
        </select>
      )}
      {showPlatform && (
        <select value={platform} onChange={(e) => setPlatform(e.target.value)} className="input w-auto" aria-label="Platform">
          <option value="">{labels.allPlatforms}</option>
          {Object.entries(SLUG).map(([p, slug]) => <option key={p} value={slug}>{PLATFORM_LABEL[p]}</option>)}
        </select>
      )}
      {accounts.length > 1 && <AccountPicker accounts={visible} picked={picked} setPicked={setPicked} />}
      {entity !== undefined && <EntityPicker value={ent} onChange={setEnt} query={{ company, platform, acc: (picked ?? []).join(",") }} />}
      {entity !== undefined && !ent.items.length && (
        <select value={stage} onChange={(e) => setStage(e.target.value)} className="input w-auto" aria-label="Funnel stage">
          <option value="">All funnel stages</option>
          {STAGE_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      )}
      {entity !== undefined && !ent.items.length && tags.length > 0 && (
        <select value={tag} onChange={(e) => setTag(e.target.value)} className="input w-auto" aria-label="Tag">
          <option value="">All tags</option>
          {tags.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
      )}
      <select value={preset} onChange={(e) => setPreset(e.target.value)} className="input w-auto" aria-label="Date range">
        {PRESETS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
      </select>
      {preset === "custom" && (
        <span className="flex items-center gap-1">
          <input type="date" className="input w-auto" value={from} max={to} onChange={(e) => setFrom(e.target.value)} aria-label="From" />
          <span className="text-muted">→</span>
          <input type="date" className="input w-auto" value={to} min={from} onChange={(e) => setTo(e.target.value)} aria-label="To" />
        </span>
      )}
      <button type="button" className="btn" onClick={apply}>{labels.apply}</button>
    </div>
  );
}

function AccountPicker({ accounts, picked, setPicked }: { accounts: PickAccount[]; picked: string[] | null; setPicked: (x: string[] | null) => void }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const box = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const close = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false); };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);
  const chosen = (picked ?? []).filter((id) => accounts.some((a) => a.id === id));
  const all = picked === null || chosen.length === accounts.length;
  const label = all || !chosen.length ? "All ad accounts" : chosen.length === 1 ? accounts.find((a) => a.id === chosen[0])?.name ?? "1 account" : `${chosen.length} ad accounts`;
  const shown = accounts.filter((a) => !q || `${a.name} ${a.externalId} ${a.company}`.toLowerCase().includes(q.toLowerCase()));
  const groups = [...new Set(shown.map((a) => a.platform))];
  const toggle = (id: string) => {
    const base = all ? accounts.map((a) => a.id) : chosen;
    setPicked(base.includes(id) ? base.filter((x) => x !== id) : [...base, id]);
  };
  return (
    <div className="relative" ref={box}>
      <button type="button" className="input flex w-auto min-w-48 items-center justify-between gap-2" onClick={() => setOpen(!open)} aria-expanded={open}>
        <span className="truncate">{label}</span><span className="text-muted">▾</span>
      </button>
      {open && (
        <div className="absolute end-0 z-30 mt-1 w-80 rounded-lg border border-line bg-panel p-2 shadow-lg">
          <input className="input mb-2 !py-1" placeholder="Search accounts…" value={q} onChange={(e) => setQ(e.target.value)} autoFocus />
          <div className="mb-2 flex gap-2 text-xs">
            <button type="button" className="text-accent hover:underline" onClick={() => setPicked(null)}>Select all</button>
            <button type="button" className="text-muted hover:underline" onClick={() => setPicked([])}>Clear</button>
            <span className="ms-auto text-muted">{all ? accounts.length : chosen.length} of {accounts.length}</span>
          </div>
          <div className="max-h-72 overflow-auto">
            {groups.map((g) => (
              <div key={g} className="mb-1">
                <div className="px-1 text-[11px] font-semibold uppercase tracking-wide text-muted">{PLATFORM_LABEL[g] ?? g}</div>
                {shown.filter((a) => a.platform === g).map((a) => (
                  <label key={a.id} className="flex cursor-pointer items-start gap-2 rounded px-1 py-1 text-sm hover:bg-bg">
                    <input type="checkbox" className="mt-1" checked={all || chosen.includes(a.id)} onChange={() => toggle(a.id)} />
                    <span className="min-w-0"><span className="block truncate">{a.name}</span><span className="block truncate text-xs text-muted">{a.company} · {a.externalId}</span></span>
                  </label>
                ))}
              </div>
            ))}
            {!shown.length && <p className="p-2 text-sm text-muted">No accounts match.</p>}
          </div>
        </div>
      )}
    </div>
  );
}

/** Searchable picker for campaigns, ad sets or ads (one kind at a time); results come from /api/entities. */
function EntityPicker({ value, onChange, query }: { value: EntityPick; onChange: (v: EntityPick) => void; query: { company: string; platform: string; acc: string } }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const [found, setFound] = useState<{ id: string; name: string; sub: string }[]>([]);
  const [loading, setLoading] = useState(false);
  const box = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const close = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false); };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);
  useEffect(() => {
    if (!open) return;
    const ctrl = new AbortController();
    const t = setTimeout(async () => {
      setLoading(true);
      try {
        const u = new URLSearchParams({ level: value.level === "AD" ? "ad" : value.level === "ADSET" ? "adset" : "campaign", q, ...query });
        const r = await fetch(`/api/entities?${u}`, { signal: ctrl.signal });
        setFound(r.ok ? (await r.json()).items : []);
      } catch { /* aborted or offline: keep the last list */ }
      setLoading(false);
    }, 250);
    return () => { clearTimeout(t); ctrl.abort(); };
  }, [open, q, value.level, query.company, query.platform, query.acc]); // eslint-disable-line react-hooks/exhaustive-deps

  const has = (id: string) => value.items.some((x) => x.id === id);
  const toggle = (x: { id: string; name: string }) => onChange({ ...value, items: has(x.id) ? value.items.filter((i) => i.id !== x.id) : [...value.items, { id: x.id, name: x.name }] });
  const n = value.items.length;
  const label = !n ? "All campaigns, ad sets & ads" : n === 1 ? value.items[0].name : `${n} ${ENTITY_LABEL[value.level].toLowerCase()}`;
  return (
    <div className="relative" ref={box}>
      <button type="button" className={`input flex w-auto min-w-52 max-w-72 items-center justify-between gap-2 ${n ? "!border-accent" : ""}`} onClick={() => setOpen(!open)} aria-expanded={open}>
        <span className="truncate">{label}</span><span className="text-muted">▾</span>
      </button>
      {open && (
        <div className="absolute end-0 z-30 mt-1 w-96 max-w-[90vw] rounded-lg border border-line bg-panel p-2 shadow-lg">
          <div className="mb-2 flex rounded-lg bg-bg p-0.5 text-sm">
            {(Object.keys(ENTITY_LABEL) as EntityPick["level"][]).map((l) => (
              <button key={l} type="button" className={`flex-1 rounded-md px-2 py-1 ${value.level === l ? "bg-panel font-medium shadow-sm" : "text-muted"}`}
                onClick={() => value.level !== l && onChange({ level: l, items: [] })}>{ENTITY_LABEL[l]}</button>
            ))}
          </div>
          <input className="input mb-2 !py-1" placeholder={`Search ${ENTITY_LABEL[value.level].toLowerCase()}…`} value={q} onChange={(e) => setQ(e.target.value)} autoFocus />
          {n > 0 && (
            <div className="mb-2 flex flex-wrap gap-1">
              {value.items.map((x) => (
                <button key={x.id} type="button" className="pill max-w-full bg-accent text-white" onClick={() => toggle(x)} title="Remove"><span className="truncate">{x.name}</span><span className="ms-1">×</span></button>
              ))}
              <button type="button" className="text-xs text-muted hover:underline" onClick={() => onChange({ ...value, items: [] })}>Clear</button>
            </div>
          )}
          <div className="max-h-72 overflow-auto">
            {found.map((x) => (
              <label key={x.id} className="flex cursor-pointer items-start gap-2 rounded px-1 py-1 text-sm hover:bg-bg">
                <input type="checkbox" className="mt-1" checked={has(x.id)} onChange={() => toggle(x)} />
                <span className="min-w-0"><span className="block truncate">{x.name}</span><span className="block truncate text-xs text-muted">{x.sub}</span></span>
              </label>
            ))}
            {!found.length && <p className="p-2 text-sm text-muted">{loading ? "Searching…" : "Nothing matches."}</p>}
          </div>
          <p className="mt-2 text-[11px] text-muted">Tick one or more, then press Apply. Showing up to 40 — type to search.</p>
        </div>
      )}
    </div>
  );
}
