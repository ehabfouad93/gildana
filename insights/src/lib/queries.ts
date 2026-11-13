import "server-only";
import type { Level, Platform, Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { convert, fxTable, type FxTable } from "@/lib/fx";
import { toDate, type Range } from "@/lib/dates";
import { ZERO, add, derive, type Totals } from "@/lib/metrics";
import { advantageAudience } from "@/platforms/meta/normalize";
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
    byCompany: [...byCompany.entries()].map(([id, t]) => ({ id, name: id ? cname.get(id) ?? "—" : "Unassigned", m: derive(t) })).sort((a, b) => b.m.spend - a.m.spend),
    byAccount: byAccount.sort((a, b) => b.m.spend - a.m.spend),
  };
}

/** Campaign table rows with their metrics for the range. */
/**
 * Campaigns with their totals. With `ownCurrency`, each campaign's money stays
 * in its ad account's real currency (as in the platform's ads manager)
 * instead of being converted into the reporting currency.
 */
export async function campaignTable(scope: Scope, r: Range, filter: { platform?: Platform; status?: string; q?: string; ownCurrency?: boolean } = {}) {
  const campaigns = await db.campaign.findMany({
    where: {
      accountId: { in: scope.accountIds },
      ...(filter.platform ? { platform: filter.platform } : {}),
      ...(filter.status ? { status: filter.status } : {}),
      ...(filter.q ? { name: { contains: filter.q, mode: "insensitive" } } : {}),
    },
    select: { id: true, name: true, platform: true, status: true, objective: true, accountId: true, account: { select: { name: true, currency: true, company: { select: { name: true } } } } },
  });
  const ids = { entityId: { in: campaigns.map((c) => c.id) } };
  const rows = new Map<string, Totals>();
  if (filter.ownCurrency) {
    // One pass per currency, converting into that same currency (i.e. not at all).
    for (const cur of new Set(campaigns.map((c) => c.account.currency))) {
      const sub = { accountIds: [...new Set(campaigns.filter((c) => c.account.currency === cur).map((c) => c.accountId))], currency: cur };
      for (const [k, v] of (await grouped(sub, r, "CAMPAIGN", ["entityId"], ids)).rows) rows.set(k, v);
    }
  } else {
    for (const [k, v] of (await grouped(scope, r, "CAMPAIGN", ["entityId"], ids)).rows) rows.set(k, v);
  }
  return campaigns
    .map((c) => ({ ...c, currency: filter.ownCurrency ? c.account.currency : scope.currency, m: derive(rows.get(c.id) ?? { ...ZERO }) }))
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

export type AudienceSource = "reported" | "tested" | "measured" | "setting";

/** Ad-set-level breakdown dimensions that platforms report per targeted segment → the targeting type they describe. */
export const SEGMENT_DIMS: Record<string, string> = {
  seg_interest: "interest", seg_topic: "interest", seg_audience: "audience",
  seg_job_title: "job_title", seg_seniority: "seniority", seg_industry: "industry", seg_company_size: "company_size",
};

export type AudienceRow = {
  type: string; value: string; externalId: string; source: AudienceSource; adSets: number; leadsCollected: number; note: string; m: ReturnType<typeof derive>;
};

/** A targeting option that has never run alone, so no exact result exists for it. */
export type NotMeasured = { type: string; value: string; externalId: string; platform: Platform; accountId: string; adSets: { id: string; name: string }[] };

/**
 * Exact results per targeting option — nothing is estimated:
 *   reported — the platform reports this segment's results itself (Google audiences/topics/keywords, TikTok interests, LinkedIn job titles…)
 *   tested   — an ad set created by an interest split test from this dashboard
 *   measured — an ad set that targets this one segment only
 *   setting  — age, gender, location, placement: delivery settings (their split is on the Breakdowns tab)
 * Segments that only ever share an ad set with others (Meta reports per ad
 * set, not per interest) come back in `notMeasured`, without numbers.
 */
export async function audienceInsights(scope: Scope, r: Range, where: { campaignId?: string; types?: string[] } = {}): Promise<{ rows: AudienceRow[]; notMeasured: NotMeasured[] }> {
  if (!scope.accountIds.length) return { rows: [], notMeasured: [] };
  const inView = await db.adSet.findMany({
    where: { accountId: { in: scope.accountIds }, ...(where.campaignId ? { campaignId: where.campaignId } : {}) },
    select: { id: true, name: true, externalId: true, platform: true, accountId: true, targetingRaw: true, targeting: { where: { exclude: false } } },
  });
  const viewIds = inView.map((x) => x.id);
  const typeOk = (t: string) => !where.types || where.types.includes(t);

  const [{ rows: setTotals }, info, fx, tests, leadCounts] = await Promise.all([
    grouped(scope, r, "ADSET", ["entityId"], { entityId: { in: viewIds } }),
    accountInfo(scope.accountIds),
    fxTable(),
    db.interestTest.findMany({ where: { accountId: { in: scope.accountIds }, status: { not: "failed" } }, select: { arms: true } }),
    db.lead.groupBy({ by: ["adSetId"], where: { adSetId: { in: viewIds }, createdAt: { gte: toDate(r.from), lt: new Date(toDate(r.to).getTime() + 86_400_000) } }, _count: true }),
  ]);
  const leadsBySet = new Map(leadCounts.map((l) => [l.adSetId, l._count]));
  const testedExt = new Set(tests.flatMap((t) => (t.arms as { externalId: string }[]).map((a) => a.externalId)));

  const out = new Map<string, AudienceRow & { t: Totals; sets: Set<string> }>();
  const bump = (type: string, value: string, externalId: string, source: AudienceSource, setId: string, t: Totals, note = "") => {
    const k = `${source}|${type}|${value}`;
    const cur = out.get(k) ?? { type, value, externalId, source, adSets: 0, leadsCollected: 0, note, m: derive({ ...ZERO }), t: { ...ZERO }, sets: new Set<string>() };
    cur.t = add(cur.t, t);
    if (note && !cur.note) cur.note = note;
    if (!cur.sets.has(setId)) { cur.sets.add(setId); cur.adSets++; cur.leadsCollected += source === "measured" || source === "tested" ? leadsBySet.get(setId) ?? 0 : 0; }
    out.set(k, cur);
  };

  // ── reported by the platform ──
  const covered = new Set<string>(); // `${adSetId}|${type}` the platform reported
  const rep = await db.statBreakdown.groupBy({
    by: ["accountId", "entityId", "dimension", "value"],
    where: { accountId: { in: scope.accountIds }, level: "ADSET", entityId: { in: viewIds }, dimension: { in: Object.keys(SEGMENT_DIMS) }, date: dateWhere(r) },
    _sum: { spend: true, impressions: true, reach: true, clicks: true, conversions: true, leads: true, convValue: true },
  });
  for (const g of rep) {
    const type = SEGMENT_DIMS[g.dimension];
    covered.add(`${g.entityId}|${type}`);
    if (!typeOk(type)) continue;
    const sum = g._sum as any;
    bump(type, g.value, "", "reported", g.entityId, toTotals({ ...sum, value: sum.convValue }, info.get(g.accountId)?.currency ?? scope.currency, scope.currency, fx));
  }
  if (typeOk("keyword")) {
    const kw = await db.keyword.groupBy({
      by: ["accountId", "adSetId", "text"], where: { accountId: { in: scope.accountIds }, adSetId: { in: viewIds }, date: dateWhere(r) },
      _sum: { spend: true, impressions: true, clicks: true, conversions: true, value: true },
    });
    for (const g of kw) {
      covered.add(`${g.adSetId}|keyword`);
      bump("keyword", g.text, "", "reported", g.adSetId, toTotals({ ...g._sum, leads: Number(g._sum.conversions ?? 0) }, info.get(g.accountId)?.currency ?? scope.currency, scope.currency, fx));
    }
  }

  // ── ad sets that target one segment only: exact. Mixed ad sets: listed, never split. ──
  const mixed = new Map<string, NotMeasured>();
  for (const x of inView) {
    const t = setTotals.get(x.id) ?? { ...ZERO };
    const segs = x.targeting.filter((ti) => SEGMENT_TYPES.has(ti.type) && !covered.has(`${x.id}|${ti.type}`));
    for (const ti of segs) {
      if (!typeOk(ti.type)) continue;
      if (segs.length === 1) {
        const widened = x.platform === "META" && advantageAudience(x.targetingRaw as Record<string, unknown> | null);
        bump(ti.type, ti.value, ti.externalId, testedExt.has(x.externalId) ? "tested" : "measured", x.id, t, widened ? "Advantage+ audience is on — Meta may go beyond this interest" : "");
      } else {
        const k = `${ti.type}|${ti.value}`;
        const cur = mixed.get(k) ?? { type: ti.type, value: ti.value, externalId: ti.externalId, platform: x.platform, accountId: x.accountId, adSets: [] };
        if (!cur.adSets.some((a) => a.id === x.id)) cur.adSets.push({ id: x.id, name: x.name });
        mixed.set(k, cur);
      }
    }
    for (const ti of x.targeting.filter((ti) => !SEGMENT_TYPES.has(ti.type))) if (typeOk(ti.type)) bump(ti.type, ti.value, ti.externalId, "setting", x.id, t);
  }

  const order: Record<AudienceSource, number> = { reported: 0, tested: 1, measured: 2, setting: 3 };
  const rows = [...out.values()]
    .map(({ t, sets, ...row }) => ({ ...row, m: derive(t) }))
    .sort((a, b) => order[a.source] - order[b.source] || b.m.spend - a.m.spend);
  const exact = new Set(rows.filter((x) => x.source !== "setting").map((x) => `${x.type}|${x.value}`));
  const notMeasured = [...mixed.entries()].filter(([k]) => !exact.has(k)).map(([, v]) => v).sort((a, b) => b.adSets.length - a.adSets.length || a.value.localeCompare(b.value));
  return { rows, notMeasured };
}

/**
 * Which targeted segment each ad set's leads belong to. Only an ad set with a
 * single segment gives an exact answer; a mixed one is reported as mixed —
 * Meta does not say which of its interests a lead came from.
 */
export async function leadSegments(scope: Scope, adSetIds: string[]): Promise<Map<string, { exact: string | null; mixed: string[] }>> {
  const sets = await db.adSet.findMany({ where: { id: { in: adSetIds }, accountId: { in: scope.accountIds } }, select: { id: true, targeting: { where: { exclude: false } } } });
  const out = new Map<string, { exact: string | null; mixed: string[] }>();
  for (const x of sets) {
    const items = x.targeting.filter((t) => SEGMENT_TYPES.has(t.type)).map((t) => t.value);
    out.set(x.id, { exact: items.length === 1 ? items[0] : null, mixed: items.length > 1 ? items : [] });
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
