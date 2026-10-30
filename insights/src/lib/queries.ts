import "server-only";
import type { Level, Platform, Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { convert, fxTable, type FxTable } from "@/lib/fx";
import { toDate, type Range } from "@/lib/dates";
import { ZERO, add, derive, type Totals } from "@/lib/metrics";

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
      where: { accountId: { in: scope.accountIds }, dimension, date: dateWhere(r), ...where },
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

/**
 * Targeting options ranked by the results of the ad sets that use them. An
 * ad set's results are credited in full to each of its options — platforms do
 * not split results per interest — so this ranks options, it does not
 * attribute them; the UI says so.
 */
export async function targetingPerformance(scope: Scope, r: Range, where: { campaignId?: string; types?: string[] } = {}) {
  const sets = await db.adSet.findMany({
    where: { accountId: { in: scope.accountIds }, ...(where.campaignId ? { campaignId: where.campaignId } : {}) },
    select: { id: true, targeting: { where: { exclude: false, ...(where.types ? { type: { in: where.types } } : {}) } } },
  });
  const { rows } = await grouped(scope, r, "ADSET", ["entityId"], { entityId: { in: sets.map((s) => s.id) } });
  const leadCounts = await db.lead.groupBy({
    by: ["adSetId"], where: { adSetId: { in: sets.map((s) => s.id) }, createdAt: { gte: toDate(r.from), lt: new Date(toDate(r.to).getTime() + 86_400_000) } }, _count: true,
  });
  const leadsBySet = new Map(leadCounts.map((l) => [l.adSetId, l._count]));
  const out = new Map<string, { type: string; value: string; adSets: number; leadsCollected: number; t: Totals }>();
  for (const s of sets) {
    const t = rows.get(s.id) ?? { ...ZERO };
    for (const ti of s.targeting) {
      const k = `${ti.type}|${ti.value}`;
      const cur = out.get(k) ?? { type: ti.type, value: ti.value, adSets: 0, leadsCollected: 0, t: { ...ZERO } };
      cur.adSets++;
      cur.leadsCollected += leadsBySet.get(s.id) ?? 0;
      cur.t = add(cur.t, t);
      out.set(k, cur);
    }
  }
  return [...out.values()].map((x) => ({ ...x, m: derive(x.t) })).sort((a, b) => b.m.spend - a.m.spend);
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
