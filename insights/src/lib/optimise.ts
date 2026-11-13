/**
 * The daily audit and the automation rules. Both are pure: they read an ad
 * account's recent days and return proposals. Neither changes anything —
 * proposals go to the to-do list (src/lib/actions-engine.ts).
 */
import { fatigue, type AdDay } from "@/lib/creative-insights";

export type Ent = {
  id: string; name: string; level: "ADSET" | "AD"; status: string;
  /** Its own daily budget in the account's currency, when it has one. */
  dailyBudget: number | null;
  campaignId: string; campaignName: string; objective: string; adSetId: string;
  /** Up to 30 recent days, in date order (money in the account's currency). */
  days: (AdDay & { value?: number })[];
};

export type Idea = {
  kind: "pause" | "start" | "budget"; level: "ADSET" | "AD"; entityId: string; entityName: string;
  payload?: { from: number; to: number; percent: number };
  reason: string; priority: number;
};

export const METRICS = {
  spend: "Spend", results: "Results", cpr: "Cost per result", ctr: "Click rate %", cpc: "Cost per click", cpm: "CPM",
  frequency: "Frequency", impressions: "Impressions", roas: "ROAS",
} as const;
export type Metric = keyof typeof METRICS;

export type Window = { spend: number; results: number; impressions: number; reach: number; clicks: number; value: number; days: number };

/** Totals of the last `n` calendar days up to `today` (inclusive). */
export function windowOf(e: Pick<Ent, "days">, n: number, today: string): Window {
  const from = new Date(Date.parse(today) - (n - 1) * 86_400_000).toISOString().slice(0, 10);
  const w: Window = { spend: 0, results: 0, impressions: 0, reach: 0, clicks: 0, value: 0, days: 0 };
  for (const d of e.days) {
    if (d.date < from || d.date > today) continue;
    w.spend += d.spend; w.results += d.results; w.impressions += d.impressions; w.reach += d.reach; w.clicks += d.clicks; w.value += d.value ?? 0;
    if (d.impressions > 0) w.days++;
  }
  return w;
}

export function metricOf(w: Window, m: Metric): number | null {
  switch (m) {
    case "spend": return w.spend;
    case "results": return w.results;
    case "impressions": return w.impressions;
    case "cpr": return w.results > 0 ? w.spend / w.results : null;
    case "ctr": return w.impressions > 0 ? (w.clicks * 100) / w.impressions : null;
    case "cpc": return w.clicks > 0 ? w.spend / w.clicks : null;
    case "cpm": return w.impressions > 0 ? (w.spend * 1000) / w.impressions : null;
    case "frequency": return w.reach > 0 ? w.impressions / w.reach : null;
    case "roas": return w.spend > 0 && w.value > 0 ? w.value / w.spend : null;
  }
}

/** The account's own average of a metric over the window, across the given entities. */
export function average(ents: Ent[], m: Metric, n: number, today: string): number | null {
  const w: Window = { spend: 0, results: 0, impressions: 0, reach: 0, clicks: 0, value: 0, days: 0 };
  for (const e of ents) { const x = windowOf(e, n, today); w.spend += x.spend; w.results += x.results; w.impressions += x.impressions; w.reach += x.reach; w.clicks += x.clicks; w.value += x.value; }
  return metricOf(w, m);
}

const budgetStep = (e: Ent, percent: number) => ({ from: e.dailyBudget!, to: Math.round(e.dailyBudget! * (1 + percent / 100) * 100) / 100, percent });

// ───────── audit ─────────

export const AUDIT = {
  /** Results needed before a cost per result is trusted. */
  minResults: 10,
  /** Clearly better / worse than the account's usual cost per result. */
  better: 0.8, worse: 1.3,
  /** Spend with no result that is enough to stop, in usual costs per result. */
  drySpend: 3,
  /** Budget counts as "holding it back" when this share of it was spent. */
  budgetUsed: 0.85,
  scalePercent: 20,
};

/**
 * What a media buyer would flag today, from the last 7 and 14 days:
 * stop what spends without results, pause clear losers and tired ads, give
 * more budget to winners their budget is holding back.
 */
export function audit(adSets: Ent[], ads: Ent[], today: string, fmt: (n: number) => string = (n) => n.toFixed(2)): Idea[] {
  const out: Idea[] = [];
  const live = (e: Ent) => e.status === "ACTIVE";
  const usual = average(adSets, "cpr", 30, today);
  if (usual == null) return out;
  const pausedSets = new Set<string>();

  for (const s of adSets.filter(live)) {
    const w7 = windowOf(s, 7, today), w14 = windowOf(s, 14, today);
    const cpr14 = metricOf(w14, "cpr"), cpr7 = metricOf(w7, "cpr");
    if (w7.results === 0 && w7.spend >= AUDIT.drySpend * usual) {
      out.push({ kind: "pause", level: "ADSET", entityId: s.id, entityName: s.name, priority: 90, reason: `Spent ${fmt(w7.spend)} in 7 days with no result — ${Math.round(w7.spend / usual)}× the account's usual cost per result (${fmt(usual)}).` });
      pausedSets.add(s.id);
    } else if (cpr14 != null && w14.results >= AUDIT.minResults && cpr14 >= usual * AUDIT.worse) {
      out.push({ kind: "pause", level: "ADSET", entityId: s.id, entityName: s.name, priority: 70, reason: `${w14.results} results at ${fmt(cpr14)} each in 14 days — ${Math.round((cpr14 / usual - 1) * 100)}% above the account's usual ${fmt(usual)}.` });
      pausedSets.add(s.id);
    } else if (s.dailyBudget && cpr7 != null && w7.results >= AUDIT.minResults && cpr7 <= usual * AUDIT.better && w7.spend >= AUDIT.budgetUsed * s.dailyBudget * Math.max(1, w7.days)) {
      out.push({ kind: "budget", level: "ADSET", entityId: s.id, entityName: s.name, priority: 60, payload: budgetStep(s, AUDIT.scalePercent), reason: `${w7.results} results at ${fmt(cpr7)} each in 7 days — ${Math.round((1 - cpr7 / usual) * 100)}% below the usual ${fmt(usual)} — and it is spending its whole budget. A small step keeps it out of the learning phase.` });
    }
  }

  const activeAdsBySet = new Map<string, number>();
  for (const a of ads.filter(live)) activeAdsBySet.set(a.adSetId, (activeAdsBySet.get(a.adSetId) ?? 0) + 1);
  for (const a of ads.filter(live)) {
    if (pausedSets.has(a.adSetId) || (activeAdsBySet.get(a.adSetId) ?? 0) < 2) continue; // never suggest pausing an ad set's last active ad
    const w7 = windowOf(a, 7, today), w14 = windowOf(a, 14, today);
    const cpr14 = metricOf(w14, "cpr");
    const f = fatigue(a.days);
    if (w7.results === 0 && w7.spend >= AUDIT.drySpend * usual) {
      out.push({ kind: "pause", level: "AD", entityId: a.id, entityName: a.name, priority: 80, reason: `Spent ${fmt(w7.spend)} in 7 days with no result (usual cost per result ${fmt(usual)}); the ad set has other active ads.` });
    } else if (cpr14 != null && w14.results >= AUDIT.minResults && cpr14 >= usual * AUDIT.worse) {
      out.push({ kind: "pause", level: "AD", entityId: a.id, entityName: a.name, priority: 65, reason: `${w14.results} results at ${fmt(cpr14)} each in 14 days — ${Math.round((cpr14 / usual - 1) * 100)}% above the usual ${fmt(usual)}; the ad set has other active ads.` });
    } else if (f.level === "tired") {
      out.push({ kind: "pause", level: "AD", entityId: a.id, entityName: a.name, priority: 50, reason: `Tired creative. ${f.note}. Replace it with a fresh one.` });
    }
  }
  return out.sort((a, b) => b.priority - a.priority);
}

// ───────── rules ─────────

export type Condition = { metric: Metric; op: ">=" | "<=" | "=" ; value: number; /** "avg": value is a multiple of the account's average of that metric */ of?: "number" | "avg" };
export type RuleSpec = {
  name: string; level: "ADSET" | "AD"; windowDays: number; conditions: Condition[];
  action: { kind: "pause" | "start" | "budget"; percent?: number };
  scope?: { accountIds?: string[]; campaignIds?: string[]; stages?: string[] };
};

/** Ready-made tactics; every one only proposes. */
export const PRESETS: Record<string, RuleSpec & { about: string }> = {
  stop_loss: { name: "Stop Loss", about: "Suggests pausing ad sets that spent twice the usual cost per result in 3 days without a single result.", level: "ADSET", windowDays: 3, conditions: [{ metric: "spend", op: ">=", value: 2, of: "avg" }, { metric: "results", op: "=", value: 0 }], action: { kind: "pause" } },
  revive: { name: "Revive", about: "Suggests switching a paused ad set back on when its last 7 days were at or below the usual cost per result (late results caught up).", level: "ADSET", windowDays: 7, conditions: [{ metric: "cpr", op: "<=", value: 1, of: "avg" }, { metric: "results", op: ">=", value: 3 }], action: { kind: "start" } },
  surf: { name: "Surf", about: "Suggests +20% budget for ad sets 20% cheaper than usual with at least 5 results in 3 days.", level: "ADSET", windowDays: 3, conditions: [{ metric: "cpr", op: "<=", value: 0.8, of: "avg" }, { metric: "results", op: ">=", value: 5 }], action: { kind: "budget", percent: 20 } },
  sunset: { name: "Sunsetting", about: "Suggests −20% budget for ad sets 30% more expensive than usual over 7 days, cutting them down gradually.", level: "ADSET", windowDays: 7, conditions: [{ metric: "cpr", op: ">=", value: 1.3, of: "avg" }, { metric: "results", op: ">=", value: 3 }], action: { kind: "budget", percent: -20 } },
};

const holds = (x: number | null, c: Condition, limit: number) => x != null && (c.op === ">=" ? x >= limit : c.op === "<=" ? x <= limit : Math.abs(x - limit) < 1e-9);

/**
 * Which entities a rule would act on today. Pause and budget rules look at
 * active items; a start rule looks at paused ones.
 */
export function evaluateRule(rule: RuleSpec, ents: Ent[], today: string, fmt: (n: number) => string = (n) => n.toFixed(2)): Idea[] {
  const pool = ents.filter((e) => e.level === rule.level);
  const n = Math.min(30, Math.max(1, rule.windowDays));
  // "avg" for spend means multiples of the usual cost per result.
  const avgOf = (m: Metric) => average(pool, m === "spend" ? "cpr" : m, m === "spend" ? 30 : n, today);
  const out: Idea[] = [];
  for (const e of pool) {
    if (rule.action.kind === "start" ? e.status !== "PAUSED" : e.status !== "ACTIVE") continue;
    if (rule.action.kind === "budget" && !e.dailyBudget) continue;
    const w = windowOf(e, n, today);
    if (rule.action.kind !== "start" && w.impressions === 0) continue;
    const said: string[] = [];
    let ok = rule.conditions.length > 0;
    for (const c of rule.conditions) {
      const avg = c.of === "avg" ? avgOf(c.metric) : null;
      if (c.of === "avg" && avg == null) { ok = false; break; }
      const limit = c.of === "avg" ? c.value * avg! : c.value;
      const x = metricOf(w, c.metric);
      if (!holds(x, c, limit)) { ok = false; break; }
      const money = ["spend", "cpr", "cpc", "cpm"].includes(c.metric);
      said.push(`${METRICS[c.metric].toLowerCase()} ${money ? fmt(x!) : Math.round(x! * 100) / 100} (${c.op} ${money ? fmt(limit) : Math.round(limit * 100) / 100})`);
    }
    if (!ok) continue;
    const percent = Math.max(-20, Math.min(20, rule.action.percent ?? 20));
    out.push({
      kind: rule.action.kind, level: e.level, entityId: e.id, entityName: e.name, priority: 55,
      ...(rule.action.kind === "budget" ? { payload: budgetStep(e, percent) } : {}),
      reason: `Rule “${rule.name}”, last ${n} day${n > 1 ? "s" : ""}: ${said.join(", ")}.`,
    });
  }
  return out;
}
