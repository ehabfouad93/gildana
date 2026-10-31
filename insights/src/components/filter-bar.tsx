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

type Props = {
  labels: { allCompanies: string; allPlatforms: string; apply: string };
  companies: { id: string; name: string }[];
  accounts?: PickAccount[];
  showPlatform?: boolean;
  range: { from: string; to: string };
};

/**
 * Filters for every report page, kept in the URL so views can be bookmarked
 * and shared: company, platform, any set of ad accounts, and a preset or custom date range.
 */
export function FilterBar({ labels, companies, accounts = [], showPlatform = true, range }: Props) {
  const router = useRouter();
  const pathname = usePathname();
  const sp = useSearchParams();

  const initialPreset = sp.get("from") && sp.get("to") ? "custom" : sp.get("period") || sp.get("days") || "30";
  const [preset, setPreset] = useState(initialPreset);
  const [from, setFrom] = useState(range.from);
  const [to, setTo] = useState(range.to);
  const [company, setCompany] = useState(sp.get("company") ?? "");
  const [platform, setPlatform] = useState(sp.get("platform") ?? "");
  // null = all accounts; otherwise exactly the ticked ones
  const [picked, setPicked] = useState<string[] | null>(() => { const a = (sp.get("acc") ?? "").split(",").filter(Boolean); return a.length ? a : null; });

  const companyName = company === "none" ? "Unassigned" : companies.find((c) => c.id === company)?.name;
  const visible = useMemo(() => accounts.filter((a) => (!platform || SLUG[a.platform] === platform) && (!company || a.company === companyName)), [accounts, platform, company, companyName]);

  const apply = () => {
    const q = new URLSearchParams(sp.toString());
    for (const k of ["company", "platform", "acc", "days", "period", "from", "to"]) q.delete(k);
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
