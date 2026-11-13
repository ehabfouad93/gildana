/**
 * Interest suggestions read from an account's own history — no platform
 * recommendations and no estimates. An interest has an exact result only from
 * ad sets (or split-test arms) that targeted it alone; everything here is
 * derived from those exact results and from which ad sets used what.
 */

export type HistoryInterest = { type: string; value: string; externalId: string };

export type HistorySet = {
  id: string; name: string; accountId: string; campaignId: string; campaignName: string; objective: string;
  interests: HistoryInterest[];
  /** Advantage+ audience on: Meta may deliver beyond the interests. */
  widened: boolean;
  spend: number; results: number;
  firstDate: string | null; lastDate: string | null;
};

export type History = { currency: string; sets: HistorySet[]; today: string };

export type Action = "scale" | "stop" | "test" | "reuse" | "retest" | "keep" | "new";

export type Suggestion = {
  type: string; value: string; externalId: string;
  action: Action;
  reason: string;
  /** Exact figures, when the interest has run alone. */
  spend: number | null; results: number | null; cpr: number | null;
  /** Higher first. */
  priority: number;
  /** Ad set whose ads and settings are the natural base for a test of this interest. */
  sourceAdSetId: string | null;
};

// ── thresholds ──
/** Results needed before an exact cost per result is trusted. */
export const MIN_RESULTS = 10;
/** "Clearly better / worse" than the benchmark. */
export const BETTER = 0.8, WORSE = 1.3;
/** Spend with no result that is enough to stop: this many benchmark costs per result. */
export const DRY_SPEND = 3;
/** An exact result older than this is worth measuring again. */
export const STALE_DAYS = 90;

export const ACTION_LABEL: Record<Action, string> = {
  scale: "Scale", stop: "Stop", test: "Test alone", reuse: "Use here", retest: "Test again", keep: "Keep", new: "New idea",
};

const key = (i: HistoryInterest) => `${i.type}|${i.value}`.toLowerCase();
const cpr = (spend: number, results: number) => (results > 0 ? spend / results : null);
const days = (a: string, b: string) => Math.round((Date.parse(b) - Date.parse(a)) / 86_400_000);

export type ExactStat = HistoryInterest & { spend: number; results: number; cpr: number | null; adSets: number; lastDate: string | null; objectives: string[]; campaigns: string[]; bestSetId: string | null; widened: boolean };

/** Exact all-time result of every interest that has run alone. */
export function exactStats(h: History): Map<string, ExactStat> {
  const out = new Map<string, ExactStat>();
  for (const s of h.sets) {
    if (s.interests.length !== 1 || s.spend <= 0) continue;
    const i = s.interests[0];
    const cur = out.get(key(i)) ?? { ...i, spend: 0, results: 0, cpr: null, adSets: 0, lastDate: null, objectives: [], campaigns: [], bestSetId: null, widened: false };
    cur.spend += s.spend; cur.results += s.results; cur.adSets++;
    if (s.lastDate && (!cur.lastDate || s.lastDate > cur.lastDate)) { cur.lastDate = s.lastDate; cur.bestSetId = s.id; }
    if (!cur.objectives.includes(s.objective)) cur.objectives.push(s.objective);
    if (!cur.campaigns.includes(s.campaignId)) cur.campaigns.push(s.campaignId);
    cur.widened ||= s.widened;
    cur.cpr = cpr(cur.spend, cur.results);
    out.set(key(i), cur);
  }
  return out;
}

/** Cost per result of everything in the history, overall and per campaign objective. */
export function benchmarks(h: History): { all: number | null; byObjective: Map<string, number> } {
  let spend = 0, results = 0;
  const by = new Map<string, { s: number; r: number }>();
  for (const x of h.sets) {
    spend += x.spend; results += x.results;
    const o = by.get(x.objective) ?? { s: 0, r: 0 };
    o.s += x.spend; o.r += x.results;
    by.set(x.objective, o);
  }
  return { all: cpr(spend, results), byObjective: new Map([...by.entries()].flatMap(([k, v]) => (v.r > 0 ? [[k, v.s / v.r] as [string, number]] : []))) };
}

/**
 * The suggestions. `campaignId` narrows "use here" to winners that this
 * campaign does not use yet; `fmt` formats money for the reasons.
 */
export function advise(h: History, o: { campaignId?: string; fmt?: (n: number) => string } = {}): Suggestion[] {
  const fmt = o.fmt ?? ((n: number) => n.toFixed(2));
  const exact = exactStats(h);
  const bench = benchmarks(h);
  const out: Suggestion[] = [];
  const inCampaign = new Set(h.sets.filter((s) => s.campaignId === o.campaignId).flatMap((s) => s.interests.map(key)));
  const campaignObjective = h.sets.find((s) => s.campaignId === o.campaignId)?.objective;
  const viewSets = o.campaignId ? h.sets.filter((s) => s.campaignId === o.campaignId) : h.sets;
  const viewKeys = new Set(viewSets.flatMap((s) => s.interests.map(key)));

  for (const [k, e] of exact) {
    const b = bench.byObjective.get(e.objectives[0]) ?? bench.all;
    const base = { type: e.type, value: e.value, externalId: e.externalId, spend: e.spend, results: e.results, cpr: e.cpr, sourceAdSetId: e.bestSetId };
    const tail = e.widened ? " (Advantage+ audience was on, so Meta may have gone beyond it)" : "";
    const stale = e.lastDate ? days(e.lastDate, h.today) > STALE_DAYS : false;
    const said = `${e.results} result${e.results === 1 ? "" : "s"} for ${fmt(e.spend)} alone${e.cpr != null ? ` — ${fmt(e.cpr)} each` : ""}`;
    const vs = b != null ? `, against your usual ${fmt(b)}` : "";
    let action: Action = "keep", reason = `${said}${vs}.`, priority = 1;

    if (b != null && e.results === 0 && e.spend >= DRY_SPEND * b) {
      action = "stop"; priority = 8;
      reason = `Spent ${fmt(e.spend)} alone with no result — ${Math.round(e.spend / b)}× your usual cost per result of ${fmt(b)}.`;
    } else if (b != null && e.cpr != null && e.results >= MIN_RESULTS && e.cpr >= b * WORSE) {
      action = "stop"; priority = 7 + Math.min(1, e.cpr / b - WORSE);
      reason = `${said}${vs}: ${Math.round((e.cpr / b - 1) * 100)}% more expensive.`;
    } else if (b != null && e.cpr != null && e.results >= MIN_RESULTS && e.cpr <= b * BETTER) {
      action = stale ? "retest" : "scale"; priority = (stale ? 5 : 9) + Math.min(1, 1 - e.cpr / b);
      reason = `${said}${vs}: ${Math.round((1 - e.cpr / b) * 100)}% cheaper.${stale ? ` Last ran ${e.lastDate} — worth confirming it still holds.` : ""}`;
    } else if (e.results < MIN_RESULTS) {
      action = "retest"; priority = 4;
      reason = `Only ${said} — too little to judge (needs about ${MIN_RESULTS} results).`;
    } else if (stale) {
      action = "retest"; priority = 3;
      reason = `${said}${vs}, but it last ran ${e.lastDate}.`;
    }
    // Inside one campaign, a winner it doesn't use is a "use here"; ones from elsewhere that aren't winners are left out.
    if (o.campaignId && !viewKeys.has(k)) {
      if (action !== "scale" || (campaignObjective && !e.objectives.includes(campaignObjective))) continue;
      action = "reuse"; priority = 8.5;
      reason = `Proven in another campaign: ${said}${vs}. This campaign doesn't use it yet.`;
    }
    out.push({ ...base, action, reason: reason + tail, priority });
  }

  // Never run alone: ranked by the real results of the ad sets that used them — shown as the ad set's, not the interest's.
  const mixed = new Map<string, { i: HistoryInterest; sets: HistorySet[] }>();
  for (const s of viewSets) {
    if (s.interests.length < 2) continue;
    for (const i of s.interests) {
      if (exact.has(key(i))) continue;
      const cur = mixed.get(key(i)) ?? { i, sets: [] };
      cur.sets.push(s);
      mixed.set(key(i), cur);
    }
  }
  for (const { i, sets } of mixed.values()) {
    const withSpend = sets.filter((s) => s.spend > 0);
    const best = [...withSpend].sort((a, b) => (cpr(a.spend, a.results) ?? Infinity) - (cpr(b.spend, b.results) ?? Infinity))[0] ?? sets[0];
    const b = bench.byObjective.get(best.objective) ?? bench.all;
    const bestCpr = cpr(best.spend, best.results);
    // More ad sets, and better ones, first.
    const quality = b != null && bestCpr != null ? Math.min(2, b / bestCpr) : 0.5;
    const others = best.interests.length - 1;
    const reason = bestCpr != null
      ? `Never run alone. Used in ${sets.length} ad set${sets.length > 1 ? "s" : ""}; the best, “${best.name}”, got ${best.results} results at ${fmt(bestCpr)} each${b != null ? ` (usual ${fmt(b)})` : ""} — shared with ${others} other interest${others > 1 ? "s" : ""}, so its own result is unknown.`
      : `Never run alone. Used in ${sets.length} ad set${sets.length > 1 ? "s" : ""} together with other interests, so its own result is unknown.`;
    out.push({ type: i.type, value: i.value, externalId: i.externalId, action: "test", reason, spend: null, results: null, cpr: null, priority: 6 + quality / 2 + Math.min(1, sets.length / 10), sourceAdSetId: best.id });
  }

  return out.sort((a, b) => b.priority - a.priority || a.value.localeCompare(b.value));
}

// ───────── AI opinions (Claude / OpenAI), written from the same history ─────────

export const AI_SYSTEM = `You are a senior Meta Ads media buyer. You are given one advertiser's own historical results as JSON.
"exact" lists interests that ran alone in an ad set (their result is exact). "mixed" lists ad sets that combined several interests (only the ad set's result is known — never attribute it to one interest).
Recommend which interests to scale, stop, test alone, test again, or try as new ideas closely related to proven winners.
Base every recommendation on the numbers given; quote them in the reason. Do not invent results. Keep reasons to one or two short sentences.
Answer with JSON only, no prose, in this shape:
{"summary": "2-3 sentences", "suggestions": [{"interest": "exact interest name", "action": "scale|stop|test|retest|new", "reason": "…", "priority": 1}]}
priority is 1 (do first) to 3. At most 15 suggestions. Write in the language requested.`;

/** Compact history for the prompt: exact interests, mixed ad sets, benchmarks. */
export function advicePrompt(h: History, o: { campaignId?: string; language?: string; context?: string } = {}): string {
  const exact = [...exactStats(h).values()].sort((a, b) => b.spend - a.spend).slice(0, 80)
    .map((e) => ({ interest: e.value, type: e.type, spend: Math.round(e.spend), results: e.results, cost_per_result: e.cpr != null ? +e.cpr.toFixed(2) : null, ad_sets: e.adSets, last_ran: e.lastDate, objective: e.objectives[0], advantage_plus_audience: e.widened }));
  const sets = (o.campaignId ? h.sets.filter((s) => s.campaignId === o.campaignId) : h.sets).filter((s) => s.interests.length > 1 && s.spend > 0)
    .sort((a, b) => b.spend - a.spend).slice(0, 80)
    .map((s) => ({ ad_set: s.name, campaign: s.campaignName, objective: s.objective, interests: s.interests.map((i) => i.value), spend: Math.round(s.spend), results: s.results, cost_per_result: cpr(s.spend, s.results) != null ? +cpr(s.spend, s.results)!.toFixed(2) : null, last_ran: s.lastDate }));
  const b = benchmarks(h);
  return JSON.stringify({
    language: o.language ?? "English", currency: h.currency, today: h.today, context: o.context ?? "",
    usual_cost_per_result: b.all != null ? +b.all.toFixed(2) : null,
    usual_cost_per_result_by_objective: Object.fromEntries([...b.byObjective.entries()].map(([k, v]) => [k, +v.toFixed(2)])),
    exact, mixed: sets,
  });
}

export type AiAdvice = { summary: string; suggestions: Suggestion[] };

const AI_ACTIONS: Action[] = ["scale", "stop", "test", "retest", "new"];

/** Reads the model's JSON answer; interests the history knows get their Meta id and exact figures, others are marked as new ideas. */
export function parseAiAdvice(text: string, h: History): AiAdvice {
  const start = text.indexOf("{"), end = text.lastIndexOf("}");
  if (start < 0 || end <= start) throw new Error("The AI answer was not in the expected format.");
  let data: { summary?: unknown; suggestions?: unknown };
  try { data = JSON.parse(text.slice(start, end + 1)); } catch { throw new Error("The AI answer was not in the expected format."); }
  const exact = exactStats(h);
  const known = new Map<string, { i: HistoryInterest; setId: string }>();
  for (const s of h.sets) for (const i of s.interests) if (!known.has(i.value.toLowerCase())) known.set(i.value.toLowerCase(), { i, setId: s.id });
  const suggestions: Suggestion[] = [];
  for (const raw of Array.isArray(data.suggestions) ? data.suggestions.slice(0, 20) : []) {
    const r = raw as Record<string, unknown>;
    const name = String(r.interest ?? "").trim().slice(0, 120);
    if (!name) continue;
    const hit = known.get(name.toLowerCase());
    const e = hit ? exact.get(key(hit.i)) : undefined;
    let action = AI_ACTIONS.includes(r.action as Action) ? (r.action as Action) : "test";
    if (!hit) action = "new";
    suggestions.push({
      type: hit?.i.type ?? "interest", value: hit?.i.value ?? name, externalId: hit?.i.externalId ?? "",
      action, reason: String(r.reason ?? "").slice(0, 600),
      spend: e?.spend ?? null, results: e?.results ?? null, cpr: e?.cpr ?? null,
      priority: 4 - Math.min(3, Math.max(1, Number(r.priority) || 2)), sourceAdSetId: e?.bestSetId ?? hit?.setId ?? null,
    });
  }
  return { summary: String(data.summary ?? "").slice(0, 1500), suggestions: suggestions.sort((a, b) => b.priority - a.priority) };
}

// ───────── reading a split test ─────────

export type ArmResult = { id: string; value: string; spend: number; results: number };
export type TestVerdict = {
  state: "too_early" | "winner" | "no_clear_winner";
  winnerId: string | null;
  /** Arms clearly worse than the winner (or dry), safe to pause. */
  loserIds: string[];
  note: string;
};

/**
 * Winner of a split test, from each arm's exact results. An arm has had its
 * chance once it reached MIN_RESULTS, or spent DRY_SPEND × the best arm's
 * cost per result without getting there.
 */
export function splitTestStatus(arms: ArmResult[], fmt: (n: number) => string = (n) => n.toFixed(2)): TestVerdict {
  const withCpr = arms.map((a) => ({ ...a, cpr: cpr(a.spend, a.results) }));
  const proven = withCpr.filter((a) => a.results >= MIN_RESULTS && a.cpr != null).sort((a, b) => a.cpr! - b.cpr!);
  const best = proven[0];
  if (!best) {
    const top = Math.max(0, ...arms.map((a) => a.results));
    return { state: "too_early", winnerId: null, loserIds: [], note: `Too early — no interest has ${MIN_RESULTS} results yet (the most so far is ${top}).` };
  }
  const hadChance = (a: (typeof withCpr)[number]) => a.results >= MIN_RESULTS || a.spend >= DRY_SPEND * best.cpr!;
  const waiting = withCpr.filter((a) => !hadChance(a));
  const losers = withCpr.filter((a) => a.id !== best.id && hadChance(a) && (a.cpr == null || a.cpr >= best.cpr! * WORSE));
  if (waiting.length) {
    return { state: "too_early", winnerId: null, loserIds: losers.map((a) => a.id), note: `Too early — ${waiting.map((a) => a.value).join(", ")} still need${waiting.length === 1 ? "s" : ""} more results. Leading: ${best.value} at ${fmt(best.cpr!)} each.` };
  }
  const second = proven[1];
  if (second && best.cpr! > second.cpr! * BETTER) {
    return { state: "no_clear_winner", winnerId: null, loserIds: losers.map((a) => a.id), note: `No clear winner — ${best.value} (${fmt(best.cpr!)}) and ${second.value} (${fmt(second.cpr!)}) are within 20% of each other.` };
  }
  return { state: "winner", winnerId: best.id, loserIds: losers.map((a) => a.id), note: `Winner: ${best.value} at ${fmt(best.cpr!)} each${second ? `, ${Math.round((1 - best.cpr! / second.cpr!) * 100)}% cheaper than the next (${second.value})` : ""}.` };
}
