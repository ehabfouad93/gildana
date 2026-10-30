import "server-only";
import { Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { toDate, type Range } from "@/lib/dates";
import { convert, fxTable } from "@/lib/fx";

/**
 * Website analytics reads. A session is "engaged" (GA4's definition) when it
 * lasted 10s+, viewed 2+ pages or converted; bounce rate is the rest.
 */

export async function siteForClient(clientId: string, siteId: string) {
  return db.site.findFirst({ where: { id: siteId, company: { clientId } }, include: { company: true, goals: true } });
}

const end = (r: Range) => new Date(toDate(r.to).getTime() + 86_400_000);
const ENGAGED = Prisma.sql`("durationSec" >= 10 OR pageviews >= 2 OR converted)`;

export type WebTotals = { users: number; newUsers: number; sessions: number; pageviews: number; engaged: number; conversions: number; duration: number; bounceRate: number | null; convRate: number | null; avgDuration: number | null; pagesPerSession: number | null };

function finish(r: { users: number; newUsers: number; sessions: number; pageviews: number; engaged: number; conversions: number; duration: number }): WebTotals {
  const s = r.sessions || 0;
  return {
    ...r,
    bounceRate: s ? ((s - r.engaged) / s) * 100 : null,
    convRate: s ? (r.conversions / s) * 100 : null,
    avgDuration: s ? r.duration / s : null,
    pagesPerSession: s ? r.pageviews / s : null,
  };
}

export async function webTotals(siteId: string, r: Range, where = Prisma.empty): Promise<WebTotals> {
  const [row] = await db.$queryRaw<any[]>`
    SELECT COUNT(DISTINCT "visitorId")::int AS users, COUNT(DISTINCT "visitorId") FILTER (WHERE "isNew")::int AS "newUsers",
           COUNT(*)::int AS sessions, COALESCE(SUM(pageviews), 0)::int AS pageviews, COUNT(*) FILTER (WHERE ${ENGAGED})::int AS engaged,
           COUNT(*) FILTER (WHERE converted)::int AS conversions, COALESCE(SUM("durationSec"), 0)::int AS duration
    FROM "TrackSession" WHERE "siteId" = ${siteId} AND "startedAt" >= ${toDate(r.from)} AND "startedAt" < ${end(r)} ${where}`;
  return finish(row);
}

export async function webDaily(siteId: string, r: Range) {
  return db.$queryRaw<{ day: string; users: number; sessions: number; conversions: number; pageviews: number }[]>`
    SELECT to_char(date_trunc('day', "startedAt"), 'YYYY-MM-DD') AS day, COUNT(DISTINCT "visitorId")::int AS users, COUNT(*)::int AS sessions,
           COUNT(*) FILTER (WHERE converted)::int AS conversions, COALESCE(SUM(pageviews), 0)::int AS pageviews
    FROM "TrackSession" WHERE "siteId" = ${siteId} AND "startedAt" >= ${toDate(r.from)} AND "startedAt" < ${end(r)}
    GROUP BY 1 ORDER BY 1`;
}

const DIMS = {
  channel: Prisma.sql`channel`, source: Prisma.sql`COALESCE(NULLIF("utmSource", ''), NULLIF("refHost", ''), '(direct)')`,
  medium: Prisma.sql`COALESCE(NULLIF("utmMedium", ''), CASE WHEN "refHost" <> '' THEN 'referral' ELSE '(none)' END)`,
  campaign: Prisma.sql`COALESCE(NULLIF("utmCampaign", ''), '(not set)')`, referrer: Prisma.sql`COALESCE(NULLIF("refHost", ''), '(direct)')`,
  country: Prisma.sql`COALESCE(NULLIF(country, ''), '(unknown)')`, city: Prisma.sql`COALESCE(NULLIF(city, ''), '(unknown)')`,
  device: Prisma.sql`device`, browser: Prisma.sql`browser`, os: Prisma.sql`os`, landing: Prisma.sql`"landingPath"`, exit: Prisma.sql`"exitPath"`,
  visitor: Prisma.sql`CASE WHEN "isNew" THEN 'New' ELSE 'Returning' END`, hour: Prisma.sql`to_char("startedAt", 'HH24')`,
} as const;
export type WebDim = keyof typeof DIMS;
export const WEB_DIMS = Object.keys(DIMS) as WebDim[];

export async function webBy(siteId: string, r: Range, dim: WebDim, limit = 100) {
  const rows = await db.$queryRaw<any[]>`
    SELECT ${DIMS[dim]} AS key, COUNT(DISTINCT "visitorId")::int AS users, COUNT(*)::int AS sessions, COALESCE(SUM(pageviews), 0)::int AS pageviews,
           COUNT(*) FILTER (WHERE ${ENGAGED})::int AS engaged, COUNT(*) FILTER (WHERE converted)::int AS conversions, COALESCE(SUM("durationSec"), 0)::int AS duration
    FROM "TrackSession" WHERE "siteId" = ${siteId} AND "startedAt" >= ${toDate(r.from)} AND "startedAt" < ${end(r)}
    GROUP BY 1 ORDER BY sessions DESC LIMIT ${limit}`;
  return rows.map((x) => ({ key: String(x.key), ...finish({ ...x, newUsers: 0 }) }));
}

export async function webPages(siteId: string, r: Range) {
  return db.$queryRaw<{ path: string; title: string; views: number; sessions: number; avgTime: number; avgScroll: number; entrances: number; exits: number }[]>`
    WITH pv AS (
      SELECT path, MAX(title) AS title, COUNT(*)::int AS views, COUNT(DISTINCT "sessionId")::int AS sessions,
             AVG("activeSec")::float AS "avgTime", AVG("scrollPct")::float AS "avgScroll"
      FROM "TrackPageview" WHERE "siteId" = ${siteId} AND ts >= ${toDate(r.from)} AND ts < ${end(r)} GROUP BY path
    ), s AS (
      SELECT "landingPath" AS path, COUNT(*)::int AS n, 'in' AS k FROM "TrackSession" WHERE "siteId" = ${siteId} AND "startedAt" >= ${toDate(r.from)} AND "startedAt" < ${end(r)} GROUP BY 1
      UNION ALL
      SELECT "exitPath", COUNT(*)::int, 'out' FROM "TrackSession" WHERE "siteId" = ${siteId} AND "startedAt" >= ${toDate(r.from)} AND "startedAt" < ${end(r)} GROUP BY 1
    )
    SELECT pv.*, COALESCE((SELECT n FROM s WHERE s.path = pv.path AND k = 'in'), 0)::int AS entrances,
           COALESCE((SELECT n FROM s WHERE s.path = pv.path AND k = 'out'), 0)::int AS exits
    FROM pv ORDER BY views DESC LIMIT 200`;
}

export async function webRealtime(siteId: string) {
  const since = new Date(Date.now() - 5 * 60_000);
  const [active, pages] = await Promise.all([
    db.trackSession.count({ where: { siteId, lastSeenAt: { gte: since } } }),
    db.$queryRaw<{ path: string; n: number }[]>`SELECT path, COUNT(*)::int AS n FROM "TrackPageview" WHERE "siteId" = ${siteId} AND ts >= ${since} GROUP BY 1 ORDER BY 2 DESC LIMIT 10`,
  ]);
  return { active, pages };
}

export async function webEvents(siteId: string, r: Range) {
  return db.$queryRaw<{ type: string; name: string; n: number; sessions: number }[]>`
    SELECT type, name, COUNT(*)::int AS n, COUNT(DISTINCT "sessionId")::int AS sessions FROM "TrackEvent"
    WHERE "siteId" = ${siteId} AND ts >= ${toDate(r.from)} AND ts < ${end(r)} GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 100`;
}

/** Sessions that hit step 1, then step 2 later in the same session, and so on. Steps are path prefixes. */
export async function webFunnel(siteId: string, r: Range, steps: string[]) {
  if (!steps.length) return [];
  const views = await db.trackPageview.findMany({
    where: { siteId, ts: { gte: toDate(r.from), lt: end(r) }, OR: steps.map((s) => ({ path: { startsWith: s } })) },
    select: { sessionId: true, path: true, ts: true }, orderBy: { ts: "asc" }, take: 200_000,
  });
  const bySession = new Map<string, string[]>();
  for (const v of views) bySession.set(v.sessionId, [...(bySession.get(v.sessionId) ?? []), v.path]);
  const counts = steps.map(() => 0);
  for (const paths of bySession.values()) {
    let k = 0;
    for (const p of paths) if (k < steps.length && p.startsWith(steps[k])) k++;
    for (let i = 0; i < k; i++) counts[i]++;
  }
  return steps.map((s, i) => ({ step: s, sessions: counts[i], rate: i === 0 ? 100 : counts[0] ? (counts[i] / counts[0]) * 100 : 0, drop: i === 0 ? 0 : counts[i - 1] ? ((counts[i - 1] - counts[i]) / counts[i - 1]) * 100 : 0 }));
}

/**
 * Ad campaigns ↔ site behaviour. Sessions are matched to a campaign by
 * utm_campaign (campaign name or id); spend comes from the ads warehouse, so
 * this is the real cost per engaged visit and per site conversion.
 */
export async function webAdsJoin(siteId: string, companyId: string, r: Range, currency: string) {
  const rows = await webBy(siteId, r, "campaign", 500);
  const campaigns = await db.campaign.findMany({
    where: { account: { companyId, enabled: true } }, select: { id: true, name: true, externalId: true, platform: true, account: { select: { currency: true } } },
  });
  const byKey = new Map<string, (typeof campaigns)[number]>();
  for (const c of campaigns) { byKey.set(c.name.toLowerCase(), c); byKey.set(c.externalId.toLowerCase(), c); }
  const matched = rows.map((x) => ({ ...x, campaign: byKey.get(x.key.toLowerCase()) ?? null }));
  const ids = matched.flatMap((m) => (m.campaign ? [m.campaign.id] : []));
  const spend = ids.length ? await db.statDaily.groupBy({ by: ["entityId"], where: { level: "CAMPAIGN", entityId: { in: ids }, date: { gte: toDate(r.from), lte: toDate(r.to) } }, _sum: { spend: true, clicks: true } }) : [];
  const fx = await fxTable();
  const sp = new Map(spend.map((s) => [s.entityId, s]));
  return matched.map((m) => {
    const s = m.campaign ? sp.get(m.campaign.id) : undefined;
    const cost = s ? convert(Number(s._sum.spend ?? 0), m.campaign!.account.currency, currency, fx) : null;
    return {
      utm: m.key, campaignId: m.campaign?.id ?? null, platform: m.campaign?.platform ?? null, sessions: m.sessions, engaged: m.engaged, bounceRate: m.bounceRate,
      avgDuration: m.avgDuration, conversions: m.conversions, adClicks: s ? Number(s._sum.clicks ?? 0) : null, spend: cost,
      costPerSession: cost != null && m.sessions ? cost / m.sessions : null, costPerConversion: cost != null && m.conversions ? cost / m.conversions : null,
      clickToSession: s && Number(s._sum.clicks) ? (m.sessions / Number(s._sum.clicks)) * 100 : null,
    };
  });
}

// ───────── heatmaps ─────────

export type HeatKind = "click" | "rage_click" | "dead_click";

export async function heatPoints(siteId: string, path: string, device: string, r: Range, kind: HeatKind) {
  return db.trackEvent.findMany({
    where: { siteId, path, type: kind, device: device === "desktop" ? { in: ["desktop"] } : device, ts: { gte: toDate(r.from), lt: end(r) }, xPct: { not: null }, yPx: { not: null } },
    select: { xPct: true, yPx: true, docH: true }, take: 50_000,
  });
}

/** Share of page views that reached each 5% of page depth. */
export async function scrollReach(siteId: string, path: string, device: string, r: Range) {
  const rows = await db.$queryRaw<{ b: number; n: number }[]>`
    SELECT LEAST(100, ("scrollPct" / 5) * 5)::int AS b, COUNT(*)::int AS n FROM "TrackPageview"
    WHERE "siteId" = ${siteId} AND path = ${path} AND device = ${device} AND ts >= ${toDate(r.from)} AND ts < ${end(r)} GROUP BY 1`;
  const total = rows.reduce((a, x) => a + x.n, 0);
  const out: { depth: number; pct: number }[] = [];
  for (let d = 0; d <= 100; d += 5) out.push({ depth: d, pct: total ? (rows.filter((x) => x.b >= d).reduce((a, x) => a + x.n, 0) / total) * 100 : 0 });
  const [dims] = await db.$queryRaw<{ docH: number | null; views: number; avgTime: number | null }[]>`
    SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY "docH")::int AS "docH", COUNT(*)::int AS views, AVG("activeSec")::float AS "avgTime"
    FROM "TrackPageview" WHERE "siteId" = ${siteId} AND path = ${path} AND device = ${device} AND ts >= ${toDate(r.from)} AND ts < ${end(r)} AND "docH" > 0`;
  return { reach: out, docH: dims?.docH ?? 0, views: dims?.views ?? 0, avgTime: dims?.avgTime ?? 0 };
}

export async function clickedElements(siteId: string, path: string, device: string, r: Range) {
  return db.$queryRaw<{ selector: string; text: string; clicks: number; rage: number; dead: number; sessions: number }[]>`
    SELECT selector, MAX(text) AS text, COUNT(*) FILTER (WHERE type = 'click')::int AS clicks, COUNT(*) FILTER (WHERE type = 'rage_click')::int AS rage,
           COUNT(*) FILTER (WHERE type = 'dead_click')::int AS dead, COUNT(DISTINCT "sessionId")::int AS sessions
    FROM "TrackEvent" WHERE "siteId" = ${siteId} AND path = ${path} AND device = ${device} AND type IN ('click', 'rage_click', 'dead_click')
      AND ts >= ${toDate(r.from)} AND ts < ${end(r)} AND selector <> ''
    GROUP BY 1 ORDER BY 3 DESC LIMIT 50`;
}

export async function heatPaths(siteId: string, r: Range) {
  return db.$queryRaw<{ path: string; views: number }[]>`
    SELECT path, COUNT(*)::int AS views FROM "TrackPageview" WHERE "siteId" = ${siteId} AND ts >= ${toDate(r.from)} AND ts < ${end(r)}
    GROUP BY 1 ORDER BY 2 DESC LIMIT 50`;
}
