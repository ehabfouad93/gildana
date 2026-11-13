"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";

type Opt = { id: string; name: string; adSetId?: string };

function Multi({ label, all, options, picked, onChange }: { label: string; all: string; options: Opt[]; picked: string[]; onChange: (x: string[]) => void }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const box = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const close = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false); };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);
  const shown = options.filter((o) => !q || o.name.toLowerCase().includes(q.toLowerCase()));
  const text = !picked.length ? all : picked.length === 1 ? options.find((o) => o.id === picked[0])?.name ?? `1 ${label}` : `${picked.length} ${label}s`;
  return (
    <div className="relative" ref={box}>
      <button type="button" className={`input flex w-52 items-center justify-between gap-2 !py-1 text-sm ${picked.length ? "!border-accent" : ""}`} onClick={() => setOpen(!open)} aria-expanded={open}>
        <span className="truncate">{text}</span><span className="text-muted">▾</span>
      </button>
      {open && (
        <div className="absolute end-0 z-30 mt-1 w-80 max-w-[90vw] rounded-lg border border-line bg-panel p-2 shadow-lg">
          {options.length > 8 && <input className="input mb-2 !py-1" placeholder={`Search ${label}s…`} value={q} onChange={(e) => setQ(e.target.value)} autoFocus />}
          <div className="max-h-64 overflow-auto">
            {shown.map((o) => (
              <label key={o.id} className="flex cursor-pointer items-center gap-2 rounded px-1 py-1 text-sm hover:bg-bg">
                <input type="checkbox" checked={picked.includes(o.id)} onChange={() => onChange(picked.includes(o.id) ? picked.filter((x) => x !== o.id) : [...picked, o.id])} />
                <span className="truncate">{o.name}</span>
              </label>
            ))}
            {!shown.length && <p className="p-2 text-sm text-muted">Nothing matches.</p>}
          </div>
          {picked.length > 0 && <button type="button" className="mt-1 text-xs text-muted hover:underline" onClick={() => onChange([])}>Clear</button>}
        </div>
      )}
    </div>
  );
}

/** Narrows a campaign's page to some of its ad sets or ads; the choice lives in the URL (?set= / ?ad=). */
export function CampaignEntityFilter({ adSets, ads, adSetLabel }: { adSets: Opt[]; ads: Opt[]; adSetLabel: string }) {
  const router = useRouter();
  const pathname = usePathname();
  const sp = useSearchParams();
  const init = (k: string) => (sp.get(k) ?? "").split(",").filter(Boolean);
  const [sets, setSets] = useState<string[]>(init("set"));
  const [picked, setPicked] = useState<string[]>(init("ad"));
  // With ad sets chosen, only their ads are offered.
  const adOptions = sets.length ? ads.filter((a) => a.adSetId && sets.includes(a.adSetId)) : ads;
  const apply = (s: string[], a: string[]) => {
    const q = new URLSearchParams(sp.toString());
    q.delete("set"); q.delete("ad");
    const validAds = a.filter((id) => adOptions.some((o) => o.id === id) || !s.length);
    if (s.length) q.set("set", s.join(","));
    if (validAds.length) q.set("ad", validAds.join(","));
    router.push(`${pathname}?${q.toString()}`, { scroll: false });
  };
  const active = !!(sp.get("set") || sp.get("ad"));
  return (
    <div className="no-print ms-auto flex flex-wrap items-center gap-2 pb-1">
      <Multi label={adSetLabel.toLowerCase().replace(/s$/, "")} all={`All ${adSetLabel.toLowerCase()}`} options={adSets} picked={sets} onChange={(x) => { setSets(x); setPicked((p) => p.filter((id) => !x.length || ads.find((a) => a.id === id && a.adSetId && x.includes(a.adSetId)))); }} />
      <Multi label="ad" all="All ads" options={adOptions} picked={picked} onChange={setPicked} />
      <button type="button" className="btn !py-1" onClick={() => apply(sets, picked)}>Apply</button>
      {active && <button type="button" className="btn-ghost !py-1" onClick={() => { setSets([]); setPicked([]); apply([], []); }}>Clear</button>}
    </div>
  );
}
