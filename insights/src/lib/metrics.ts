/**
 * Derived metrics. Every ratio is computed from summed raw counts, never by
 * averaging ratios — averaging per-day CTRs gives a wrong answer whenever
 * daily volumes differ.
 */
export type Totals = {
  spend: number;
  impressions: number;
  reach: number;
  clicks: number;
  linkClicks: number;
  conversions: number;
  leads: number;
  purchases: number;
  value: number;
  videoViews: number;
};

export const ZERO: Totals = {
  spend: 0, impressions: 0, reach: 0, clicks: 0, linkClicks: 0,
  conversions: 0, leads: 0, purchases: 0, value: 0, videoViews: 0,
};

const div = (a: number, b: number) => (b > 0 ? a / b : null);

export function derive(t: Totals) {
  return {
    ...t,
    ctr: div(t.clicks * 100, t.impressions),
    cpc: div(t.spend, t.clicks),
    cpm: div(t.spend * 1000, t.impressions),
    cpl: div(t.spend, t.leads),
    cpa: div(t.spend, t.conversions),
    roas: t.value > 0 ? div(t.value, t.spend) : null,
    convRate: div(t.conversions * 100, t.clicks),
    frequency: div(t.impressions, t.reach),
  };
}

export type Derived = ReturnType<typeof derive>;

export function add(a: Totals, b: Partial<Totals>): Totals {
  const out = { ...a };
  for (const k of Object.keys(ZERO) as (keyof Totals)[]) out[k] += Number(b[k] ?? 0);
  return out;
}

/** Percentage change, or null when there is no base to compare against. */
export function delta(cur: number | null, prev: number | null): number | null {
  if (cur == null || prev == null || prev === 0) return null;
  return ((cur - prev) / Math.abs(prev)) * 100;
}

/** Metrics where a rise is bad news, so the UI colours their deltas inversely. */
export const LOWER_IS_BETTER = new Set(["cpc", "cpm", "cpl", "cpa", "spend", "frequency"]);
