import "server-only";
import type { Level, Platform, Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { convert, fxTable, type FxTable } from "@/lib/fx";
import { toDate, type Range } from "@/lib/dates";
import { ZERO, add, derive, type Totals } from "@/lib/metrics";
import { estimateInterests } from "@/lib/attribution";
import { SEGMENT_TYPES } from "@/lib/segments";

/**
 * Warehouse reads. Every function takes the already-scoped account ids (see
 * tenancy.ts) and converts money into the reporting currency per account,
 * because one client's accounts can bill in different currencies.
 */

export type Scope = { accountIds: string[]; currency: string };

type AccInfo = { currency: string; platform: Platform; name: string; companyId: string | null };

async function accountInfo(ids: string[]): Promise<Map<string, AccInfo>> {
  const rows = await db.adAccount.findMany({ where: { id: { in: ids } }, select: { id: true, currency: true, platform: true, name: true, companyId: true } });
  return new Map(rows.map((r) => [r.id, r]));
}

const SUM = { spend: true, impressions: true, reach: true, clicks: true, linkClicks: true, conversions: true, leads: true, purchases: true, value: true, videoViews: true } as const;

function toTotals(s: Record<string, unknown>, cur: string, target: string, fx: FxTable): Totals {
  const n = (k: string) => Number((s as any)[k] ?? 0);
  return {
    spend: convert(n("spend"), cur, target, fx), value: convert(n("value"), cur, target, fx),
    impressions: n("impressions"), reach: n("reach"), clicks: n("clicks"), linkClicks: n("linkClicks"),
    conversions: n("conversions"), leads: n("leads"), purchases: n("purchases"), videoViews: n("videoViews"),
  };
}

function dateWhere(r: Range) {
  return { gte: toDate(r.from), lte: toDate(r.to) };
}

/** Sums one level, grouped by the given keys plus accountId (for FX), converted and merged. */
async function grouped<K extends "date" | "entityId" | "campaignId" | "adSetId">(
  scope: Scope, r: Range, level: Level, by: K[], where: Prisma.StatDailyWhereInput = {},
) {
  if (!scope.accountIds.length) return { rows: new Map<string, Totals>(), info: new Map<string, AccInfo>() };
  const [raw, info, fx] = await Promise.all([
    db.statDaily.groupBy({
      by: ["accountId", ...by] as any,
      where: { accountId: { in: scope.accountIds }, level, date: dateWhere(r), ...where },
      _sum: SUM,
    }),
    accountInfo(scope.accountIds),
    fxTable(),
  ]);
  const rows = new Map<string, Totals>();
  for (const g of raw as any[]) {
    const key = by.map((k) => (k === "date" ? (g.date as Date).toISOString().slice(0, 10) : g[k])).join("|");
    const t = toTotals(g._sum, info.get(g.accountId)?.currency ?? scope.currency, scope.currency, fx);
    rows.set(key, add(rows.get(key) ?? { ...ZERO }, t));
  }
  return { rows, info, raw: raw as any[], fx };
}

export async function totals(scope: Scope, r: Range, where: Prisma.StatDailyWhereInput = {}, level: Level = "ACCOUNT") {
  const { rows } = await grouped(scope, r, level, [], where);
  return derive(rows.get("") ?? { ...ZERO });
}

export async function daily(scope: Scope, r: Range, where: Prisma.StatDailyWhereInput = {}, level: Level = "ACCOUNT") {
  const { rows } = await grouped(scope, r, level, ["date"], where);
  return [...rows.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([date, t]) => ({ date, ...derive(t) }));
}

/** Account-level totals split by platform, company and account. */
export async function splits(scope: Scope, r: Range) {
  if (!scope.accountIds.length) return { byPlatform: [], byCompany: [], byAccount: [] };
  const [raw, info, fx, companies] = await Promise.all([
    db.statDaily.groupBy({ by: ["accountId"], where: { accountId: { in: scope.accountIds }, level: "ACCOUNT", date: dateWhere(r) }, _sum: SUM }),
    accountInfo(scope.accountIds),
    fxTable(),
    db.company.findMany({ where: { accounts: { some: { id: { in: scope.accountIds } } } }, select: { id: true, name: true } }),
  ]);
  const cname = new Map(companies.map((c) => [c.id, c.name]));
  const byPlatform = new Map<string, Totals>(), byCompany = new Map<string, Totals>();
  const byAccount: { id: string; name: string; platform: Platform; company: string; currency: string; m: ReturnType<typeof derive> }[] = [];
  for (const g of raw) {
    const a = info.get(g.accountId)!;
    const t = toTotals(g._sum, a.currency, scope.currency, fx);
    byPlatform.set(a.platform, add(byPlatform.get(a.platform) ?? { ...ZERO }, t));
    const ck = a.companyId ?? "";
    byCompany.set(ck, add(byCompany.get(ck) ?? { ...ZERO }, t));
    byAccount.push({ id: g.accountId, name: a.name, platform: a.platform, company: cname.get(ck) ?? "—", currency: a.currency, m: derive(t) });
  }
  return {
    byPlatform: [...byPlatform.entries()].map(([platform, t]) => ({ platform: platform as Platform, m: derive(t) })).sort((a, b) => b.m.spend - a.m.spend),
    byCompany: [...byCompany.entries()].map(([id, t]) => ({ id, name: cname.get(id) ?? "—", m: derive(t) })).sort((a, b) => b.m.spend - a.m.spend),
    byAccount: byAccount.sort((a, b) => b.m.spend - a.m.spend),
  };
}

/** Campaign table rows with their metrics for the range. */
export async function campaignTable(scope: Scope, r: Range, filter: { platform?: Platform; status?: string; q?: string } = {}) {
  const campaigns = await db.campaign.findMany({
    where: {
      accountId: { in: scope.accountIds },
      ...(filter.platform ? { platform: filter.platform } : {}),
      ...(filter.status ? { status: filter.status } : {}),
      ...(filter.q ? { name: { contains: filter.q, mode: "insensitive" } } : {}),
    },
    select: { id: true, name: true, platform: true, status: true, objective: true, accountId: true, account: { select: { name: true, company: { select: { name: true } } } } },
  });
  const { rows } = await grouped(scope, r, "CAMPAIGN", ["entityId"], { entityId: { in: campaigns.map((c) => c.id) } });
  return campaigns
    .map((c) => ({ ...c, m: derive(rows.get(c.id) ?? { ...ZERO }) }))
    .sort((a, b) => b.m.spend - a.m.spend);
}

export async function adSetTable(scope: Scope, r: Range, campaignId: string) {
  const sets = await db.adSet.findMany({ where: { campaignId, accountId: { in: scope.accountIds } }, include: { targeting: true } });
  const { rows } = await grouped(scope, r, "ADSET", ["entityId"], { campaignId });
  return sets.map((s) => ({ ...s, m: derive(rows.get(s.id) ?? { ...ZERO }) })).sort((a, b) => b.m.spend - a.m.spend);
}

export async function adTable(scope: Scope, r: Range, campaignId: string) {
  const ads = await db.ad.findMany({ where: { campaignId, accountId: { in: scope.accountIds } }, include: { adSet: { select: { name: true } } } });
  const { rows } = await grouped(scope, r, "AD", ["entityId"], { campaignId });
  return ads.map((a) => ({ ...a, m: derive(rows.get(a.id) ?? { ...ZERO }) })).sort((a, b) => b.m.spend - a.m.spend);
}

/** One breakdown dimension, summed over the range, for campaigns or whole accounts. */
export async function breakdown(scope: Scope, r: Range, dimension: string, where: Prisma.StatBreakdownWhereInput = {}) {
  if (!scope.accountIds.length) return [];
  const [raw, info, fx] = await Promise.all([
    db.statBreakdown.groupBy({
      by: ["accountId", "value"],
      // Campaign-level rows unless the caller asks otherwise: ad-set-level audience rows would double count.
      where: { accountId: { in: scope.accountIds }, dimension, date: dateWhere(r), level: "CAMPAIGN", ...where },
      _sum: { spend: true, impressions: true, reach: true, clicks: true, conversions: true, leads: true, convValue: true },
    }),
    accountInfo(scope.accountIds),
    fxTable(),
  ]);
  const out = new Map<string, Totals>();
  for (const g of raw) {
    const cur = info.get(g.accountId)?.currency ?? scope.currency;
    const s = g._sum as any;
    const t = toTotals({ ...s, value: s.convValue }, cur, scope.currency, fx);
    out.set(g.value, add(out.get(g.value) ?? { ...ZERO }, t));
  }
  return [...out.entries()].map(([value, t]) => ({ value, m: derive(t) })).sort((a, b) => b.m.spend - a.m.spend);
}

export type AudienceSource = "reported" | "tested" | "measured" | "estimated" | "adset" | "setting";

/** Ad-set-level breakdown dimensions that platforms report per targeted segment → the targeting type they describe. */
export const SEGMENT_DIMS: Record<string, string> = {
  seg_interest: "interest", seg_topic: "interest", seg_audience: "audience",
  seg_job_title: "job_title", seg_seniority: "seniority", seg_industry: "industry", seg_company_size: "company_size",
};

export type AudienceRow = {
  type: string; value: string; source: AudienceSource; adSets: number; leadsCollected: number; note: string; m: ReturnType<typeof derive>;
};

const scaleTotals = (t: Totals, delivery: number, results: number): Totals => ({
  spend: t.spend * delivery, impressions: t.impressions * delivery, reach: t.reach * delivery, clicks: t.clicks * delivery, linkClicks: t.linkClicks * delivery,
  videoViews: t.videoViews * delivery, conversions: t.conversions * results, leads: t.leads * results, purchases: t.purchases * results, value: t.value * results,
});

/**
 * Results per targeting option, each labelled with where the number comes from:
 *   reported  — the platform reports this segment's results itself (Google audiences/topics/keywords, TikTok interests, LinkedIn job titles…)
 *   tested    — an ad set created by an interest split test from this dashboard
 *   measured  — an ad set that targets this one segment only
 *   estimated — Meta-style ad sets with several interests, split by the attribution model (src/lib/attribution.ts)
 *   adset     — too little variation to estimate; the whole ad set's results, for ranking only
 *   setting   — age, gender, location, placement: delivery settings (see the Breakdowns tab for their split)
 */
export async function audienceInsights(scope: Scope, r: Range, where: { campaignId?: string; types?: string[] } = {}): Promise<AudienceRow[]> {
  if (!scope.accountIds.length) return [];
  // The model is fitted on every ad set of the accounts involved, then read for the ones in view.
  const all = await db.adSet.findMany({
    where: { accountId: { in: scope.accountIds } },
    select: { id: true, campaignId: true, externalId: true, platform: true, accountId: true, targeting: { where: { exclude: false } } },
  });
  const inView = all.filter((x) => !where.campaignId || x.campaignId === where.campaignId);
  const viewIds = new Set(inView.map((x) => x.id));
  const typeOk = (t: string) => !where.types || where.types.includes(t);

  const [{ rows: setTotals }, info, fx, tests, leadCounts] = await Promise.all([
    grouped(scope, r, "ADSET", ["entityId"], { entityId: { in: all.map((x) => x.id) } }),
    accountInfo(scope.accountIds),
    fxTable(),
    db.interestTest.findMany({ where: { accountId: { in: scope.accountIds }, status: { not: "failed" } }, select: { arms: true } }),
    db.lead.groupBy({ by: ["adSetId"], where: { adSetId: { in: [...viewIds] }, createdAt: { gte: toDate(r.from), lt: new Date(toDate(r.to).getTime() + 86_400_000) } }, _count: true }),
  ]);
  const leadsBySet = new Map(leadCounts.map((l) => [l.adSetId, l._count]));
  const testedExt = new Set(tests.flatMap((t) => (t.arms as { externalId: string }[]).map((a) => a.externalId)));

  // ── reported by the platform ──
  const out = new Map<string, AudienceRow & { t: Totals; sets: Set<string> }>();
  const bump = (type: string, value: string, source: AudienceSource, setId: string, t: Totals, note = "") => {
    const k = `${source}|${type}|${value}`;
    const cur = out.get(k) ?? { type, value, source, adSets: 0, leadsCollected: 0, note, m: derive({ ...ZERO }), t: { ...ZERO }, sets: new Set<string>() };
    cur.t = add(cur.t, t);
    if (!cur.sets.has(setId)) { cur.sets.add(setId); cur.adSets++; cur.leadsCollected += source === "measured" || source === "tested" ? leadsBySet.get(setId) ?? 0 : 0; }
    out.set(k, cur);
  };
  const covered = new Set<string>(); // `${adSetId}|${type}` the platform reported
  const rep = await db.statBreakdown.groupBy({
    by: ["accountId", "entityId", "dimension", "value"],
    where: { accountId: { in: scope.accountIds }, level: "ADSET", entityId: { in: [...viewIds] }, dimension: { in: Object.keys(SEGMENT_DIMS) }, date: dateWhere(r) },
    _sum: { spend: true, impressions: true, reach: true, clicks: true, conversions: true, leads: true, convValue: true },
  });
  for (const g of rep) {
    const type = SEGMENT_DIMS[g.dimension];
    covered.add(`${g.entityId}|${type}`);
    if (!typeOk(type)) continue;
    const sum = g._sum as any;
    bump(type, g.value, "reported", g.entityId, toTotals({ ...sum, value: sum.convValue }, info.get(g.accountId)?.currency ?? scope.currency, scope.currency, fx));
  }
  if (typeOk("keyword")) {
    const kw = await db.keyword.groupBy({
      by: ["accountId", "adSetId", "text"], where: { accountId: { in: scope.accountIds }, adSetId: { in: [...viewIds] }, date: dateWhere(r) },
      _sum: { spend: true, impressions: true, clicks: true, conversions: true, value: true },
    });
    for (const g of kw) {
      covered.add(`${g.adSetId}|keyword`);
      bump("keyword", g.text, "reported", g.adSetId, toTotals({ ...g._sum, leads: Number(g._sum.conversions ?? 0) }, info.get(g.accountId)?.currency ?? scope.currency, scope.currency, fx));
    }
  }

  // ── everything the platform did not split ──
  const segmentsOf = (x: (typeof all)[number]) => x.targeting.filter((t) => SEGMENT_TYPES.has(t.type) && !covered.has(`${x.id}|${t.type}`));
  const { estimates, weights } = estimateInterests(all.map((x) => {
    const t = setTotals.get(x.id) ?? { ...ZERO };
    return { id: x.id, spend: t.spend, conversions: t.leads || t.conversions, interests: segmentsOf(x).map((i) => `${i.type}|${i.value}`) };
  }));
  const est = new Map(estimates.map((e) => [e.interest, e]));

  for (const x of inView) {
    const t = setTotals.get(x.id) ?? { ...ZERO };
    const segs = segmentsOf(x);
    for (const ti of segs) {
      if (!typeOk(ti.type)) continue;
      const key = `${ti.type}|${ti.value}`;
      if (segs.length === 1) {
        bump(ti.type, ti.value, testedExt.has(x.externalId) ? "tested" : "measured", x.id, t);
      } else if (est.get(key)?.identifiable) {
        bump(ti.type, ti.value, "estimated", x.id, scaleTotals(t, 1 / segs.length, weights.get(x.id)?.get(key) ?? 1 / segs.length), est.get(key)!.reason);
      } else {
        bump(ti.type, ti.value, "adset", x.id, t, est.get(key)?.reason ?? "");
      }
    }
    for (const ti of x.targeting.filter((t) => !SEGMENT_TYPES.has(t.type))) if (typeOk(ti.type)) bump(ti.type, ti.value, "setting", x.id, t);
  }

  const order: Record<AudienceSource, number> = { reported: 0, tested: 1, measured: 2, estimated: 3, adset: 4, setting: 5 };
  return [...out.values()]
    .map(({ t, sets, ...row }) => ({ ...row, m: derive(t) }))
    .sort((a, b) => order[a.source] - order[b.source] || b.m.spend - a.m.spend);
}

/**
 * For each lead, how its ad set's targeting shares the credit: 100% for a
 * single-segment ad set, the model's weights for several interests.
 */
export async function leadSegmentShares(scope: Scope, r: Range, adSetIds: string[]): Promise<Map<string, { value: string; share: number }[]>> {
  const sets = await db.adSet.findMany({ where: { accountId: { in: scope.accountIds } }, select: { id: true, targeting: { where: { exclude: false } } } });
  const { rows: totals } = await grouped(scope, r, "ADSET", ["entityId"], { entityId: { in: sets.map((x) => x.id) } });
  const segs = (x: (typeof sets)[number]) => x.targeting.filter((t) => SEGMENT_TYPES.has(t.type));
  const { weights } = estimateInterests(sets.map((x) => {
    const t = totals.get(x.id) ?? { ...ZERO };
    return { id: x.id, spend: t.spend, conversions: t.leads || t.conversions, interests: segs(x).map((i) => `${i.type}|${i.value}`) };
  }));
  const out = new Map<string, { value: string; share: number }[]>();
  for (const x of sets.filter((s) => adSetIds.includes(s.id))) {
    const items = segs(x);
    const w = weights.get(x.id);
    out.set(x.id, items.map((i) => ({ value: i.value, share: items.length === 1 ? 1 : w?.get(`${i.type}|${i.value}`) ?? 1 / items.length })).sort((a, b) => b.share - a.share));
  }
  return out;
}

/** Google keywords, search terms and placements, summed over the range. */
export async function keywordTable(scope: Scope, r: Range, campaignId?: string) {
  const raw = await db.keyword.groupBy({
    by: ["accountId", "externalId", "text", "matchType", "campaignId", "adSetId"],
    where: { accountId: { in: scope.accountIds }, date: dateWhere(r), ...(campaignId ? { campaignId } : {}) },
    _sum: { spend: true, impressions: true, clicks: true, conversions: true, value: true },
    _avg: { qualityScore: true },
  });
  return convertRows(scope, raw, (g) => ({ text: g.text, matchType: g.matchType, campaignId: g.campaignId, adSetId: g.adSetId, qs: g._avg.qualityScore }));
}

export async function searchTermTable(scope: Scope, r: Range, campaignId?: string) {
  const raw = await db.searchTerm.groupBy({
    by: ["accountId", "term", "keywordText", "matchType", "campaignId"],
    where: { accountId: { in: scope.accountIds }, date: dateWhere(r), ...(campaignId ? { campaignId } : {}) },
    _sum: { spend: true, impressions: true, clicks: true, conversions: true },
  });
  return convertRows(scope, raw, (g) => ({ term: g.term, keywordText: g.keywordText, matchType: g.matchType, campaignId: g.campaignId }));
}

export async function placementTable(scope: Scope, r: Range, campaignId?: string) {
  const raw = await db.placement.groupBy({
    by: ["accountId", "kind", "target", "display", "campaignId"],
    where: { accountId: { in: scope.accountIds }, date: dateWhere(r), ...(campaignId ? { campaignId } : {}) },
    _sum: { spend: true, impressions: true, clicks: true, conversions: true },
  });
  return convertRows(scope, raw, (g) => ({ kind: g.kind, target: g.target, display: g.display, campaignId: g.campaignId }));
}

async function convertRows<T>(scope: Scope, raw: any[], pick: (g: any) => T) {
  const [info, fx] = await Promise.all([accountInfo(scope.accountIds), fxTable()]);
  return raw
    .map((g) => ({ ...pick(g), m: derive(toTotals(g._sum, info.get(g.accountId)?.currency ?? scope.currency, scope.currency, fx)) }))
    .sort((a, b) => b.m.spend - a.m.spend);
}
