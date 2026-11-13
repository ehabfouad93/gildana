import "server-only";
import { fatigue } from "@/lib/creative-insights";
import { previousRange, type Range } from "@/lib/dates";
import type { Derived } from "@/lib/metrics";
import { PLATFORM_META } from "@/lib/platforms";
import { audienceInsights, breakdown, campaignTable, creativeData, daily, splits, stageTable, topAdSets, totals, type Scope } from "@/lib/queries";

/**
 * The numbers an AI answer may use: a compact, already-scoped picture of the
 * accounts the asker can see for the chosen dates. The model gets this and the
 * question — it never queries anything itself.
 */

const r2 = (n: number | null | undefined) => (n == null || !Number.isFinite(n) ? null : Math.round(n * 100) / 100);
const kpi = (m: Derived) => ({ spend: r2(m.spend), impressions: m.impressions, clicks: m.clicks, ctr_pct: r2(m.ctr), cpc: r2(m.cpc), cpm: r2(m.cpm), leads: m.leads, cost_per_lead: r2(m.cpl), conversions: m.conversions, cost_per_conversion: r2(m.cpa), roas: r2(m.roas), frequency: r2(m.frequency) });

export async function dataPack(scope: Scope, range: Range, context: { workspace: string; filters: string }) {
  const prev = previousRange(range);
  const [now, before, sp, camps, campsBefore, sets, ads, series, stages, aud, age, gender, country, placement] = await Promise.all([
    totals(scope, range), totals(scope, prev), splits(scope, range), campaignTable(scope, range), campaignTable(scope, prev), topAdSets(scope, range, 25),
    creativeData(scope, range), daily(scope, range), stageTable(scope, range), audienceInsights(scope, range, { types: ["interest", "behavior", "audience", "lookalike"] }),
    breakdown(scope, range, "age"), breakdown(scope, range, "gender"), breakdown(scope, range, "country"), breakdown(scope, range, "placement"),
  ]);
  const was = new Map(campsBefore.map((c) => [c.id, c.m]));
  const bd = (rows: { value: string; m: Derived }[]) => rows.slice(0, 8).map((x) => ({ value: x.value, spend: r2(x.m.spend), leads: x.m.leads, cost_per_lead: r2(x.m.cpl), conversions: x.m.conversions, ctr_pct: r2(x.m.ctr) }));
  return {
    workspace: context.workspace, currency: scope.currency, filters: context.filters,
    period: range, previous_period: prev,
    totals: kpi(now), totals_previous_period: kpi(before),
    by_platform: sp.byPlatform.map((p) => ({ platform: PLATFORM_META[p.platform].label, ...kpi(p.m) })),
    by_company: sp.byCompany.slice(0, 15).map((c) => ({ company: c.name, ...kpi(c.m) })),
    by_account: sp.byAccount.slice(0, 20).map((a) => ({ account: a.name, platform: PLATFORM_META[a.platform].label, company: a.company, ...kpi(a.m) })),
    by_funnel_stage: stages.map((s) => ({ stage: s.label, ad_sets: s.adSets, ...kpi(s.m) })),
    campaigns: camps.filter((c) => c.m.spend > 0).slice(0, 30).map((c) => ({
      campaign: c.name, platform: PLATFORM_META[c.platform].label, account: c.account.name, status: c.status, objective: c.objective.replace(/^OUTCOME_/, ""), ...kpi(c.m),
      previous_period: was.get(c.id) ? { spend: r2(was.get(c.id)!.spend), leads: was.get(c.id)!.leads, cost_per_lead: r2(was.get(c.id)!.cpl), conversions: was.get(c.id)!.conversions } : null,
    })),
    ad_sets: sets.map((s) => ({ ad_set: s.name, campaign: s.campaign, status: s.status, funnel_stage: s.stage, ...kpi(s.m) })),
    ads: [...ads].sort((a, b) => b.spend - a.spend).slice(0, 25).map((a) => ({
      ad: a.name, campaign: a.campaignName, ad_set: a.adSetName, status: a.status, headline: a.headline.slice(0, 80), spend: r2(a.spend), results: a.results,
      cost_per_result: a.results > 0 ? r2(a.spend / a.results) : null, ctr_pct: a.impressions > 0 ? r2((a.clicks * 100) / a.impressions) : null, fatigue: fatigue(a.days).level,
    })),
    interests_with_exact_results: aud.rows.filter((x) => x.source !== "setting").slice(0, 20).map((x) => ({ option: x.value, type: x.type, source: x.source, spend: r2(x.m.spend), leads: x.m.leads, cost_per_lead: r2(x.m.cpl) })),
    interests_never_measured_alone: aud.notMeasured.slice(0, 20).map((x) => x.value),
    breakdowns: { age: bd(age), gender: bd(gender), country: bd(country), placement: bd(placement) },
    daily: series.map((d) => ({ date: d.date, spend: r2(d.spend), leads: d.leads, conversions: d.conversions, cost_per_lead: r2(d.cpl) })),
  };
}

export const ASK_SYSTEM = `You are a senior performance-marketing analyst answering a question about one advertiser's ad accounts.
You are given their numbers as JSON ("data") for a period and the period before it, already limited to what this person may see.
Rules:
- Answer only from the data. Quote the figures you used (with the currency). If the data cannot answer the question, say so and say what would be needed.
- Never invent numbers, campaigns or causes. When you infer a likely cause, say it is a likely cause and which numbers point to it.
- An interest's result is known only if it is listed under interests_with_exact_results; never attribute a mixed ad set's results to one interest.
- Be brief: the direct answer first, then up to 5 short bullet points, then (if useful) one or two concrete next steps. Use simple words.
- Answer in the language of the question (Arabic or English). Format with short markdown; no tables wider than 4 columns.`;
