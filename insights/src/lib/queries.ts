import "server-only";
import type { Level, Platform, Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { convert, fxTable, type FxTable } from "@/lib/fx";
import { toDate, type Range } from "@/lib/dates";
import { ZERO, add, derive, type Totals } from "@/lib/metrics";
import { advantageAudience } from "@/platforms/meta/normalize";
import { SEGMENT_TYPES } from "@/lib/segments";
import { applyEntity, type EntityFilter } from "@/lib/entity-filter";
import { STAGES, STAGE_LABEL, stageOf } from "@/lib/funnel";
import type { AdDay, AdInput } from "@/lib/creative-insights";
import { splitTestStatus, type ArmResult, type History, type TestVerdict } from "@/lib/interest-advice";

/**
 * Warehouse reads. Every function takes the already-scoped account ids (see
 * tenancy.ts) and converts money into the reporting currency per account,
 * because one client's accounts can bill in different currencies.
 */

/** `entity` is the page's campaign / ad set / ad filter, when one is on. */
export type Scope = { accountIds: string[]; currency: string; entity?: EntityFilter };

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
  // A campaign / ad set / ad filter may move the question to another level (see entity-filter.ts).
  const x = applyEntity(scope.entity, level, by, where as Record<string, unknown>);
  const [raw, info, fx] = await Promise.all([
    db.statDaily.groupBy({
      by: ["accountId", ...x.by] as any,
      where: { accountId: { in: scope.accountIds }, level: x.level, date: dateWhere(r), AND: [x.where, x.filter] },
      _sum: SUM,
    }),
    accountInfo(scope.accountIds),
    fxTable(),
  ]);
  const rows = new Map<string, Totals>();
  for (const g of raw as any[]) {
    const key = x.by.map((k) => (k === "date" ? (g.date as Date).toISOString().slice(0, 10) : g[k])).join("|");
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
  const x = applyEntity(scope.entity, "ACCOUNT", [], {});
  const [raw, info, fx, companies] = await Promise.all([
    db.statDaily.groupBy({ by: ["accountId"], where: { accountId: { in: scope.accountIds }, level: x.level, date: dateWhere(r), ...x.filter }, _sum: SUM }),
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
      ...(scope.entity ? { id: { in: scope.entity.campaignIds } } : {}),
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
      const sub = { ...scope, accountIds: [...new Set(campaigns.filter((c) => c.account.currency === cur).map((c) => c.accountId))], currency: cur };
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
  const e = scope.entity;
  // Under an ad-set or ad filter, only those ad sets (an ad's ad set) are listed.
  const sets = await db.adSet.findMany({ where: { campaignId, accountId: { in: scope.accountIds }, ...(e && e.level !== "CAMPAIGN" ? { id: { in: e.adSetIds } } : {}) }, include: { targeting: true } });
  const { rows } = await grouped(scope, r, "ADSET", ["entityId"], { campaignId });
  return sets.map((s) => ({ ...s, m: derive(rows.get(s.id) ?? { ...ZERO }) })).sort((a, b) => b.m.spend - a.m.spend);
}

export async function adTable(scope: Scope, r: Range, campaignId: string) {
  const e = scope.entity;
  const ads = await db.ad.findMany({ where: { campaignId, accountId: { in: scope.accountIds }, ...(e?.level === "ADSET" ? { adSetId: { in: e.ids } } : e?.level === "AD" ? { id: { in: e.ids } } : {}) }, include: { adSet: { select: { name: true } } } });
  const { rows } = await grouped(scope, r, "AD", ["entityId"], { campaignId });
  return ads.map((a) => ({ ...a, m: derive(rows.get(a.id) ?? { ...ZERO }) })).sort((a, b) => b.m.spend - a.m.spend);
}

/** How the page's filter narrows breakdown rows (stored per campaign and per ad set). */
function breakdownEntity(e: EntityFilter | undefined): Prisma.StatBreakdownWhereInput {
  if (!e || !e.ids.length) return {};
  if (e.level === "CAMPAIGN") return { campaignId: { in: e.ids } };
  return { level: "ADSET", entityId: { in: e.level === "ADSET" ? e.ids : e.adSetIds } };
}

/** One breakdown dimension, summed over the range, for campaigns or whole accounts. */
export async function breakdown(scope: Scope, r: Range, dimension: string, where: Prisma.StatBreakdownWhereInput = {}) {
  if (!scope.accountIds.length) return [];
  const [raw, info, fx] = await Promise.all([
    db.statBreakdown.groupBy({
      by: ["accountId", "value"],
      // Campaign-level rows unless the caller asks otherwise: ad-set-level audience rows would double count.
      // With an ad-set or ad filter the ad sets' own rows are read (an ad's breakdown is its ad set's).
      where: { accountId: { in: scope.accountIds }, dimension, date: dateWhere(r), level: "CAMPAIGN", ...where, ...breakdownEntity(scope.entity) },
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
  const e = scope.entity;
  const inView = await db.adSet.findMany({
    where: {
      accountId: { in: scope.accountIds }, ...(where.campaignId ? { campaignId: where.campaignId } : {}),
      ...(e ? (e.level === "CAMPAIGN" ? { campaignId: { in: e.ids } } : { id: { in: e.level === "ADSET" ? e.ids : e.adSetIds } }) : {}),
    },
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

/**
 * Everything the Meta ad sets in scope ever did, for the suggestion engine
 * (src/lib/interest-advice.ts): each ad set's interests with its all-time
 * spend and results. Ignores the page's date filter on purpose.
 */
export async function interestHistory(scope: Scope): Promise<History> {
  const today = new Date().toISOString().slice(0, 10);
  if (!scope.accountIds.length) return { currency: scope.currency, sets: [], today };
  const [sets, raw, info, fx] = await Promise.all([
    db.adSet.findMany({
      where: { accountId: { in: scope.accountIds }, platform: "META" },
      select: { id: true, name: true, accountId: true, campaignId: true, targetingRaw: true, campaign: { select: { name: true, objective: true } }, targeting: { where: { exclude: false } } },
    }),
    db.statDaily.groupBy({
      by: ["accountId", "entityId"], where: { accountId: { in: scope.accountIds }, platform: "META", level: "ADSET" },
      _sum: SUM, _min: { date: true }, _max: { date: true },
    }),
    accountInfo(scope.accountIds),
    fxTable(),
  ]);
  const stat = new Map(raw.map((g) => [g.entityId, g]));
  return {
    currency: scope.currency, today,
    sets: sets.map((x) => {
      const g = stat.get(x.id);
      const t = g ? toTotals(g._sum, info.get(x.accountId)?.currency ?? scope.currency, scope.currency, fx) : { ...ZERO };
      return {
        id: x.id, name: x.name, accountId: x.accountId, campaignId: x.campaignId, campaignName: x.campaign.name, objective: x.campaign.objective.replace(/^OUTCOME_/, ""),
        interests: x.targeting.filter((ti) => SEGMENT_TYPES.has(ti.type)).map((ti) => ({ type: ti.type, value: ti.value, externalId: ti.externalId })),
        widened: advantageAudience(x.targetingRaw as Record<string, unknown> | null),
        spend: t.spend, results: t.leads || t.conversions,
        firstDate: g?._min.date?.toISOString().slice(0, 10) ?? null, lastDate: g?._max.date?.toISOString().slice(0, 10) ?? null,
      };
    }),
  };
}

export type SplitTestView = {
  id: string; name: string; kind: string; status: string; createdAt: Date; endsAt: Date | null; campaignId: string | null; sourceAdSetId: string; accountId: string;
  arms: (ArmResult & { adSetId: string | null; externalId: string; status: string; cpr: number | null })[];
  verdict: TestVerdict;
};

/** Interest split tests of the accounts in scope, each arm with its exact results since the test was created. */
export async function splitTests(scope: Scope, where: { campaignId?: string } = {}, fmt?: (n: number) => string): Promise<SplitTestView[]> {
  if (!scope.accountIds.length) return [];
  // kind "audience" rows are ad sets launched from the mixer, not tests.
  const tests = await db.interestTest.findMany({ where: { accountId: { in: scope.accountIds }, status: { not: "failed" }, kind: { in: ["adset", "campaign"] } }, orderBy: { createdAt: "desc" }, take: 50 });
  if (!tests.length) return [];
  const ext = tests.flatMap((t) => (t.arms as { externalId: string }[]).map((a) => a.externalId));
  const [sets, newCampaigns, info, fx] = await Promise.all([
    db.adSet.findMany({ where: { accountId: { in: scope.accountIds }, externalId: { in: ext } }, select: { id: true, externalId: true, accountId: true, status: true, campaignId: true } }),
    db.campaign.findMany({ where: { accountId: { in: scope.accountIds }, externalId: { in: tests.map((t) => t.newCampaignExternalId).filter(Boolean) } }, select: { id: true, externalId: true, accountId: true } }),
    accountInfo(scope.accountIds),
    fxTable(),
  ]);
  const setOf = new Map(sets.map((x) => [`${x.accountId}|${x.externalId}`, x]));
  const out: SplitTestView[] = [];
  for (const t of tests) {
    const arms = t.arms as { externalId: string; interest: { value: string } }[];
    const mine = arms.map((a) => setOf.get(`${t.accountId}|${a.externalId}`));
    const newCampaign = newCampaigns.find((c) => c.accountId === t.accountId && c.externalId === t.newCampaignExternalId);
    const shownIn = t.kind === "campaign" ? newCampaign?.id ?? null : t.campaignId;
    if (where.campaignId && where.campaignId !== shownIn && where.campaignId !== t.campaignId) continue;
    const raw = await db.statDaily.groupBy({
      by: ["entityId"], where: { accountId: t.accountId, level: "ADSET", entityId: { in: mine.flatMap((x) => (x ? [x.id] : [])) }, date: { gte: toDate(t.createdAt.toISOString().slice(0, 10)) } }, _sum: SUM,
    });
    const totalsBy = new Map(raw.map((g) => [g.entityId, toTotals(g._sum, info.get(t.accountId)?.currency ?? scope.currency, scope.currency, fx)]));
    const rows = arms.map((a, i) => {
      const tot = mine[i] ? totalsBy.get(mine[i]!.id) : undefined;
      const results = tot ? tot.leads || tot.conversions : 0;
      return { id: a.externalId, externalId: a.externalId, adSetId: mine[i]?.id ?? null, status: mine[i]?.status ?? "not synced yet", value: a.interest.value, spend: tot?.spend ?? 0, results, cpr: results > 0 ? (tot?.spend ?? 0) / results : null };
    });
    out.push({ id: t.id, name: t.name, kind: t.kind, status: t.status, createdAt: t.createdAt, endsAt: t.endsAt, campaignId: shownIn, sourceAdSetId: t.sourceAdSetId, accountId: t.accountId, arms: rows.sort((a, b) => (a.cpr ?? Infinity) - (b.cpr ?? Infinity)), verdict: splitTestStatus(rows, fmt) });
  }
  return out;
}

/**
 * Every ad in scope with its totals for the range and its recent days (up to
 * 60, whatever the range) for the fatigue trend — input of src/lib/creative-insights.ts.
 */
export async function creativeData(scope: Scope, r: Range, filter: { platform?: Platform } = {}): Promise<AdInput[]> {
  if (!scope.accountIds.length) return [];
  const e = scope.entity;
  const ads = await db.ad.findMany({
    where: {
      accountId: { in: scope.accountIds }, ...(filter.platform ? { platform: filter.platform } : {}),
      ...(e ? (e.level === "CAMPAIGN" ? { campaignId: { in: e.ids } } : e.level === "ADSET" ? { adSetId: { in: e.ids } } : { id: { in: e.ids } }) : {}),
    },
    select: { id: true, name: true, status: true, platform: true, format: true, creativeExternalId: true, headline: true, body: true, cta: true, thumbUrl: true, adSetId: true, campaignId: true, adSet: { select: { name: true } }, campaign: { select: { name: true } } },
  });
  if (!ads.length) return [];
  const today = new Date().toISOString().slice(0, 10);
  const recent = { from: new Date(Date.now() - 60 * 86_400_000).toISOString().slice(0, 10), to: today };
  const [{ rows }, { rows: daily }] = await Promise.all([
    grouped(scope, r, "AD", ["entityId"]),
    grouped(scope, recent, "AD", ["entityId", "date"]),
  ]);
  const days = new Map<string, AdDay[]>();
  for (const [k, t] of daily) {
    const [id, date] = k.split("|");
    days.set(id, [...(days.get(id) ?? []), { date, spend: t.spend, impressions: t.impressions, reach: t.reach, clicks: t.clicks, results: t.leads || t.conversions }]);
  }
  return ads.flatMap((a) => {
    const t = rows.get(a.id);
    if (!t || (t.impressions === 0 && t.spend === 0)) return [];
    return [{
      id: a.id, name: a.name, status: a.status, platform: a.platform, format: a.format, creativeExternalId: a.creativeExternalId, headline: a.headline, body: a.body, cta: a.cta, thumbUrl: a.thumbUrl,
      adSetId: a.adSetId, adSetName: a.adSet.name, campaignId: a.campaignId, campaignName: a.campaign.name,
      spend: t.spend, impressions: t.impressions, reach: t.reach, clicks: t.clicks, results: t.leads || t.conversions,
      days: (days.get(a.id) ?? []).sort((x, y) => x.date.localeCompare(y.date)),
    }];
  });
}

/** The ad sets with the most spend in the range, with their campaign and funnel stage. */
export async function topAdSets(scope: Scope, r: Range, n = 25) {
  if (!scope.accountIds.length) return [];
  const { rows } = await grouped(scope, r, "ADSET", ["entityId"]);
  const top = [...rows.entries()].sort((a, b) => b[1].spend - a[1].spend).slice(0, n);
  const sets = await db.adSet.findMany({ where: { id: { in: top.map(([id]) => id) } }, select: { id: true, name: true, status: true, funnelStage: true, funnelStageManual: true, campaign: { select: { name: true } } } });
  const info = new Map(sets.map((s) => [s.id, s]));
  return top.flatMap(([id, t]) => { const s = info.get(id); return s ? [{ id, name: s.name, status: s.status, campaign: s.campaign.name, stage: stageOf(s), m: derive(t) }] : []; });
}

/** Results per funnel stage: every ad set's numbers under its stage. */
export async function stageTable(scope: Scope, r: Range) {
  if (!scope.accountIds.length) return [];
  const [sets, { rows }] = await Promise.all([
    db.adSet.findMany({ where: { accountId: { in: scope.accountIds } }, select: { id: true, funnelStage: true, funnelStageManual: true } }),
    grouped(scope, r, "ADSET", ["entityId"]),
  ]);
  const out = new Map<string, { t: Totals; adSets: number }>();
  for (const s of sets) {
    const t = rows.get(s.id);
    if (!t) continue;
    const k = stageOf(s);
    const cur = out.get(k) ?? { t: { ...ZERO }, adSets: 0 };
    out.set(k, { t: add(cur.t, t), adSets: cur.adSets + 1 });
  }
  return STAGES.flatMap((k) => (out.has(k) ? [{ stage: k, label: STAGE_LABEL[k], adSets: out.get(k)!.adSets, m: derive(out.get(k)!.t) }] : []));
}

/** Results per tag: a campaign with two tags counts under both. */
export async function tagTable(scope: Scope, r: Range, clientId: string) {
  if (!scope.accountIds.length) return [];
  const tags = await db.tag.findMany({ where: { clientId }, include: { entities: { where: { level: "CAMPAIGN" }, select: { entityId: true } } }, orderBy: { name: "asc" } });
  if (!tags.length) return [];
  const { rows } = await grouped(scope, r, "CAMPAIGN", ["entityId"]);
  return tags.map((t) => {
    let sum = { ...ZERO }, n = 0;
    for (const e of t.entities) { const x = rows.get(e.entityId); if (x) { sum = add(sum, x); n++; } }
    return { id: t.id, name: t.name, color: t.color, campaigns: n, m: derive(sum) };
  }).filter((t) => t.campaigns > 0).sort((a, b) => b.m.spend - a.m.spend);
}

/** Tags of a set of campaigns, for showing next to their names. */
export async function campaignTags(clientId: string, campaignIds: string[]): Promise<Map<string, { id: string; name: string; color: string }[]>> {
  const rows = await db.entityTag.findMany({ where: { level: "CAMPAIGN", entityId: { in: campaignIds }, tag: { clientId } }, select: { entityId: true, tag: { select: { id: true, name: true, color: true } } } });
  const out = new Map<string, { id: string; name: string; color: string }[]>();
  for (const r of rows) out.set(r.entityId, [...(out.get(r.entityId) ?? []), r.tag]);
  return out;
}

/** Google keywords, search terms and placements, summed over the range. */
export async function keywordTable(scope: Scope, r: Range, campaignId?: string) {
  const raw = await db.keyword.groupBy({
    by: ["accountId", "externalId", "text", "matchType", "campaignId", "adSetId"],
    where: { accountId: { in: scope.accountIds }, date: dateWhere(r), ...(campaignId ? { campaignId } : {}), ...adGroupEntity(scope.entity) },
    _sum: { spend: true, impressions: true, clicks: true, conversions: true, value: true },
    _avg: { qualityScore: true },
  });
  return convertRows(scope, raw, (g) => ({ text: g.text, matchType: g.matchType, campaignId: g.campaignId, adSetId: g.adSetId, qs: g._avg.qualityScore }));
}

export async function searchTermTable(scope: Scope, r: Range, campaignId?: string) {
  const raw = await db.searchTerm.groupBy({
    by: ["accountId", "term", "keywordText", "matchType", "campaignId"],
    where: { accountId: { in: scope.accountIds }, date: dateWhere(r), ...(campaignId ? { campaignId } : {}), ...adGroupEntity(scope.entity) },
    _sum: { spend: true, impressions: true, clicks: true, conversions: true },
  });
  return convertRows(scope, raw, (g) => ({ term: g.term, keywordText: g.keywordText, matchType: g.matchType, campaignId: g.campaignId }));
}

export async function placementTable(scope: Scope, r: Range, campaignId?: string) {
  const raw = await db.placement.groupBy({
    by: ["accountId", "kind", "target", "display", "campaignId"],
    where: { accountId: { in: scope.accountIds }, date: dateWhere(r), ...(campaignId ? { campaignId } : {}), ...(scope.entity ? { campaignId: { in: scope.entity.campaignIds } } : {}) },
    _sum: { spend: true, impressions: true, clicks: true, conversions: true },
  });
  return convertRows(scope, raw, (g) => ({ kind: g.kind, target: g.target, display: g.display, campaignId: g.campaignId }));
}

/** Keywords and search terms belong to an ad group; an ad filter narrows to the ad's ad group. */
function adGroupEntity(e: EntityFilter | undefined): { campaignId?: { in: string[] }; adSetId?: { in: string[] } } {
  if (!e || !e.ids.length) return {};
  return e.level === "CAMPAIGN" ? { campaignId: { in: e.ids } } : { adSetId: { in: e.level === "ADSET" ? e.ids : e.adSetIds } };
}

async function convertRows<T>(scope: Scope, raw: any[], pick: (g: any) => T) {
  const [info, fx] = await Promise.all([accountInfo(scope.accountIds), fxTable()]);
  return raw
    .map((g) => ({ ...pick(g), m: derive(toTotals(g._sum, info.get(g.accountId)?.currency ?? scope.currency, scope.currency, fx)) }))
    .sort((a, b) => b.m.spend - a.m.spend);
}
