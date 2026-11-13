/**
 * Creative insights: the same creative counted together wherever it runs,
 * which headlines / texts / buttons work, and which ads are tiring. Pure —
 * the data comes from the warehouse (src/lib/queries.ts → creativeData).
 */

export type AdDay = { date: string; spend: number; impressions: number; reach: number; clicks: number; results: number };

export type AdInput = {
  id: string; name: string; status: string; platform: string; format: string;
  creativeExternalId: string; headline: string; body: string; cta: string; thumbUrl: string;
  adSetId: string; adSetName: string; campaignId: string; campaignName: string;
  /** Totals in the page's date range (money already in the reporting currency). */
  spend: number; impressions: number; reach: number; clicks: number; results: number;
  /** Recent days in date order, for the fatigue trend. */
  days: AdDay[];
};

export type Fatigue = { level: "new" | "fresh" | "watch" | "tired"; note: string; ctrChange: number | null; costChange: number | null; freqChange: number | null };

/** An ad needs this many delivery days before a trend means anything. */
export const MIN_DAYS = 10;
export const MIN_IMPRESSIONS = 2000;

const sum = (d: AdDay[]) => d.reduce((a, x) => ({ spend: a.spend + x.spend, impressions: a.impressions + x.impressions, reach: a.reach + x.reach, clicks: a.clicks + x.clicks, results: a.results + x.results }), { spend: 0, impressions: 0, reach: 0, clicks: 0, results: 0 });
const ratio = (a: number, b: number) => (b > 0 ? a / b : null);
const change = (now: number | null, was: number | null) => (now != null && was != null && was > 0 ? now / was - 1 : null);
const pct = (x: number) => `${x > 0 ? "+" : ""}${Math.round(x * 100)}%`;

/**
 * Is the ad tiring? Compares its last 7 delivery days with its first 7:
 * click rate falling together with cost per result or frequency rising.
 */
export function fatigue(days: AdDay[]): Fatigue {
  const live = days.filter((d) => d.impressions > 0);
  const total = sum(live);
  if (live.length < MIN_DAYS || total.impressions < MIN_IMPRESSIONS) return { level: "new", note: "Too new to judge", ctrChange: null, costChange: null, freqChange: null };
  const first = sum(live.slice(0, 7)), last = sum(live.slice(-7));
  const ctrChange = change(ratio(last.clicks, last.impressions), ratio(first.clicks, first.impressions));
  const costChange = change(ratio(last.spend, last.results), ratio(first.spend, first.results));
  const freqChange = change(ratio(last.impressions, last.reach), ratio(first.impressions, first.reach));
  const parts = [ctrChange != null ? `click rate ${pct(ctrChange)}` : "", costChange != null ? `cost per result ${pct(costChange)}` : "", freqChange != null ? `frequency ${pct(freqChange)}` : ""].filter(Boolean);
  const note = `Last 7 days vs its first 7: ${parts.join(", ") || "no change measured"}`;
  const ctrDown = ctrChange ?? 0, costUp = costChange ?? 0, freqUp = freqChange ?? 0;
  // Results dried up entirely after having some.
  const dried = first.results > 0 && last.results === 0 && last.spend > 0;
  const level = (ctrDown <= -0.25 && (costUp >= 0.25 || freqUp >= 0.3)) || (dried && ctrDown <= -0.15) ? "tired" : ctrDown <= -0.15 || costUp >= 0.2 || dried ? "watch" : "fresh";
  return { level, note: dried ? `${note}; no results in the last 7 days` : note, ctrChange, costChange, freqChange };
}

type Tot = { spend: number; impressions: number; reach: number; clicks: number; results: number };
const derive = (t: Tot) => ({ ...t, ctr: t.impressions > 0 ? (t.clicks * 100) / t.impressions : null, cpr: t.results > 0 ? t.spend / t.results : null });
const zero = (): Tot => ({ spend: 0, impressions: 0, reach: 0, clicks: 0, results: 0 });
const addTo = (t: Tot, a: Tot) => { t.spend += a.spend; t.impressions += a.impressions; t.reach += a.reach; t.clicks += a.clicks; t.results += a.results; };

/** The same creative: the platform's creative id, else the same headline + text + image. */
export const creativeKey = (a: Pick<AdInput, "platform" | "creativeExternalId" | "headline" | "body" | "thumbUrl">) =>
  a.creativeExternalId ? `${a.platform}:${a.creativeExternalId}` : `${a.platform}:${a.headline}|${a.body}|${a.thumbUrl}`.toLowerCase();

export type CreativeRow = ReturnType<typeof derive> & {
  key: string; sample: AdInput; ads: number; adSets: number; campaigns: number; active: number; fatigue: Fatigue;
};

/** One row per creative, summed over every ad that uses it; fatigue from all its ads' days together. */
export function byCreative(ads: AdInput[]): CreativeRow[] {
  const groups = new Map<string, AdInput[]>();
  for (const a of ads) groups.set(creativeKey(a), [...(groups.get(creativeKey(a)) ?? []), a]);
  return [...groups.entries()].map(([key, list]) => {
    const t = zero();
    list.forEach((a) => addTo(t, a));
    const days = new Map<string, AdDay>();
    for (const a of list) for (const d of a.days) {
      const cur = days.get(d.date) ?? { date: d.date, spend: 0, impressions: 0, reach: 0, clicks: 0, results: 0 };
      cur.spend += d.spend; cur.impressions += d.impressions; cur.reach += d.reach; cur.clicks += d.clicks; cur.results += d.results;
      days.set(d.date, cur);
    }
    const sample = [...list].sort((a, b) => b.spend - a.spend)[0];
    return {
      ...derive(t), key, sample, ads: list.length, adSets: new Set(list.map((a) => a.adSetId)).size, campaigns: new Set(list.map((a) => a.campaignId)).size,
      active: list.filter((a) => a.status === "ACTIVE").length, fatigue: fatigue([...days.values()].sort((a, b) => a.date.localeCompare(b.date))),
    };
  }).sort((a, b) => b.spend - a.spend);
}

export type ElementRow = ReturnType<typeof derive> & { value: string; ads: number };

/** Results per headline, text, button or format across all ads that use it. */
export function byElement(ads: AdInput[], field: "headline" | "body" | "cta" | "format"): ElementRow[] {
  const out = new Map<string, { t: Tot; ads: number; value: string }>();
  for (const a of ads) {
    const value = (a[field] || "").trim();
    if (!value) continue;
    const cur = out.get(value.toLowerCase()) ?? { t: zero(), ads: 0, value };
    addTo(cur.t, a); cur.ads++;
    out.set(value.toLowerCase(), cur);
  }
  return [...out.values()].map((x) => ({ ...derive(x.t), value: x.value, ads: x.ads })).sort((a, b) => b.spend - a.spend);
}
