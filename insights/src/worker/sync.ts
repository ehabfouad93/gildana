import { Prisma, type AdConnection, type Level } from "@prisma/client";
import { db } from "@/lib/db";
import { decrypt, encrypt } from "@/lib/crypto";
import { toDate } from "@/lib/dates";
import { connectorFor } from "@/platforms";
import type { Connector, Metrics, SyncContext } from "@/platforms/types";

/**
 * Pulls one ad account for one date window and writes it into the warehouse.
 * Structure is upserted; metrics for the window are replaced wholesale, which
 * is what makes a re-sync pick up the platforms' attribution restatements.
 */
export async function syncAccount(accountId: string, from: string, to: string, log: (s: string) => void = () => {}) {
  const account = await db.adAccount.findUniqueOrThrow({ where: { id: accountId }, include: { connection: true } });
  const conn = account.connection;
  const connector = connectorFor(account.platform, conn.isDemo);
  const ctx: SyncContext = {
    accessToken: conn.isDemo ? "demo" : await freshToken(conn, connector),
    account: { externalId: account.externalId, currency: account.currency, timezone: account.timezone, parentId: account.parentId },
  };
  const platform = account.platform;

  // ── structure ──
  const s = await connector.fetchStructure(ctx);
  const campId = new Map<string, string>();
  const setId = new Map<string, string>();
  const adId = new Map<string, string>();

  for (const c of s.campaigns) {
    const data = {
      name: c.name, objective: c.objective, status: c.status,
      dailyBudget: c.dailyBudget ?? null, totalBudget: c.totalBudget ?? null,
      startDate: c.startDate ? toDate(c.startDate) : null, endDate: c.endDate ? toDate(c.endDate) : null,
      raw: (c.raw ?? Prisma.JsonNull) as Prisma.InputJsonValue,
    };
    const row = await db.campaign.upsert({
      where: { accountId_externalId: { accountId, externalId: c.externalId } },
      create: { accountId, platform, externalId: c.externalId, ...data },
      update: data,
      select: { id: true },
    });
    campId.set(c.externalId, row.id);
  }
  for (const x of s.adSets) {
    const campaignId = campId.get(x.campaignExternalId);
    if (!campaignId) continue;
    const data = {
      campaignId, name: x.name, status: x.status, optimizationGoal: x.optimizationGoal ?? "", bidStrategy: x.bidStrategy ?? "",
      dailyBudget: x.dailyBudget ?? null, targetingRaw: (x.targetingRaw ?? Prisma.JsonNull) as Prisma.InputJsonValue,
    };
    const row = await db.adSet.upsert({
      where: { accountId_externalId: { accountId, externalId: x.externalId } },
      create: { accountId, platform, externalId: x.externalId, ...data },
      update: data,
      select: { id: true },
    });
    setId.set(x.externalId, row.id);
    await db.$transaction([
      db.targetingItem.deleteMany({ where: { adSetId: row.id } }),
      db.targetingItem.createMany({ data: x.targeting.map((t) => ({ adSetId: row.id, type: t.type, value: t.value, externalId: t.externalId ?? "", exclude: !!t.exclude })) }),
    ]);
  }
  for (const a of s.ads) {
    const campaignId = campId.get(a.campaignExternalId), adSetId = setId.get(a.adSetExternalId);
    if (!campaignId || !adSetId) continue;
    const data = {
      campaignId, adSetId, name: a.name, status: a.status, format: a.format ?? "", headline: a.headline ?? "", body: a.body ?? "",
      cta: a.cta ?? "", thumbUrl: a.thumbUrl ?? "", landingUrl: a.landingUrl ?? "", previewUrl: a.previewUrl ?? "", previewHtml: a.previewHtml ?? null,
    };
    const row = await db.ad.upsert({
      where: { accountId_externalId: { accountId, externalId: a.externalId } },
      create: { accountId, platform, externalId: a.externalId, ...data },
      update: data,
      select: { id: true },
    });
    adId.set(a.externalId, row.id);
  }
  log(`structure: ${s.campaigns.length} campaigns, ${s.adSets.length} ad sets, ${s.ads.length} ads`);

  // ── metrics ──
  const bundle = await connector.fetchStats(ctx, from, to);
  const resolve = (level: Level, ext: string): string | undefined =>
    level === "ACCOUNT" ? accountId : level === "CAMPAIGN" ? campId.get(ext) : level === "ADSET" ? setId.get(ext) : adId.get(ext);

  // Parent ids on ad set / ad rows let campaign pages read children without joins.
  const setCampaign = new Map(s.adSets.map((x) => [setId.get(x.externalId), campId.get(x.campaignExternalId)]));
  const adParents = new Map(s.ads.map((x) => [adId.get(x.externalId), { c: campId.get(x.campaignExternalId), s: setId.get(x.adSetExternalId) }]));

  const range = { gte: toDate(from), lte: toDate(to) };
  const m = (x: Metrics) => ({
    spend: x.spend ?? 0, impressions: Math.round(x.impressions ?? 0), reach: Math.round(x.reach ?? 0), clicks: Math.round(x.clicks ?? 0),
    linkClicks: Math.round(x.linkClicks ?? 0), conversions: x.conversions ?? 0, leads: Math.round(x.leads ?? 0),
    purchases: Math.round(x.purchases ?? 0), value: x.value ?? 0, videoViews: Math.round(x.videoViews ?? 0), engagements: Math.round(x.engagements ?? 0),
  });

  const statRows: Prisma.StatDailyCreateManyInput[] = [];
  for (const r of bundle.stats) {
    const entityId = resolve(r.level, r.externalId);
    if (!entityId) continue;
    let campaignId: string | null = null, adSetId: string | null = null;
    if (r.level === "CAMPAIGN") campaignId = entityId;
    if (r.level === "ADSET") { adSetId = entityId; campaignId = setCampaign.get(entityId) ?? null; }
    if (r.level === "AD") { const p = adParents.get(entityId); campaignId = p?.c ?? null; adSetId = p?.s ?? null; }
    statRows.push({ accountId, platform, level: r.level, entityId, campaignId, adSetId, date: toDate(r.date), ...m(r), extra: (r.extra ?? undefined) as Prisma.InputJsonValue | undefined });
  }

  const bdRows: Prisma.StatBreakdownCreateManyInput[] = [];
  for (const r of bundle.breakdowns) {
    const entityId = resolve(r.level, r.externalId);
    if (!entityId) continue;
    const x = m(r);
    bdRows.push({
      accountId, platform, level: r.level, entityId, campaignId: r.level === "CAMPAIGN" ? entityId : null, date: toDate(r.date),
      dimension: r.dimension, value: r.bucket, spend: x.spend, impressions: x.impressions, reach: x.reach, clicks: x.clicks,
      conversions: x.conversions, leads: x.leads, convValue: x.value,
    });
  }

  await replace("statDaily", accountId, range, statRows);
  await replace("statBreakdown", accountId, range, bdRows);

  if (bundle.keywords) {
    await replace("keyword", accountId, range, bundle.keywords.flatMap((k) => {
      const c = campId.get(k.campaignExternalId), g = setId.get(k.adSetExternalId);
      if (!c || !g) return [];
      return [{ accountId, campaignId: c, adSetId: g, externalId: k.externalId, text: k.text, matchType: k.matchType, status: k.status ?? "", qualityScore: k.qualityScore ?? null, date: toDate(k.date), spend: k.spend ?? 0, impressions: k.impressions ?? 0, clicks: k.clicks ?? 0, conversions: k.conversions ?? 0, value: k.value ?? 0 }];
    }));
  }
  if (bundle.searchTerms) {
    await replace("searchTerm", accountId, range, sumDupes(["adSetId", "term", "date"], bundle.searchTerms.flatMap((k) => {
      const c = campId.get(k.campaignExternalId), g = setId.get(k.adSetExternalId);
      if (!c || !g) return [];
      return [{ accountId, campaignId: c, adSetId: g, term: k.term, keywordText: k.keywordText ?? "", matchType: k.matchType ?? "", date: toDate(k.date), spend: k.spend ?? 0, impressions: k.impressions ?? 0, clicks: k.clicks ?? 0, conversions: k.conversions ?? 0 }];
    })));
  }
  if (bundle.placements) {
    await replace("placement", accountId, range, sumDupes(["campaignId", "kind", "target", "date"], bundle.placements.flatMap((k) => {
      const c = campId.get(k.campaignExternalId);
      if (!c) return [];
      return [{ accountId, campaignId: c, kind: k.kind, target: k.target, display: k.display ?? "", date: toDate(k.date), spend: k.spend ?? 0, impressions: k.impressions ?? 0, clicks: k.clicks ?? 0, conversions: k.conversions ?? 0 }];
    })));
  }
  log(`metrics: ${statRows.length} daily rows, ${bdRows.length} breakdown rows`);

  // ── leads ──
  if (connector.fetchLeads) {
    const leads = await connector.fetchLeads(ctx, from, to);
    if (leads.length) {
      await db.lead.createMany({
        skipDuplicates: true,
        data: leads.map((l) => ({
          accountId, platform, externalId: l.externalId, formName: l.formName ?? "", createdAt: new Date(l.createdAt), fields: l.fields,
          campaignId: l.campaignExternalId ? campId.get(l.campaignExternalId) ?? null : null,
          adSetId: l.adSetExternalId ? setId.get(l.adSetExternalId) ?? null : null,
          adId: l.adExternalId ? adId.get(l.adExternalId) ?? null : null,
        })),
      });
    }
    log(`leads: ${leads.length}`);
  }

  await db.adAccount.update({ where: { id: accountId }, data: { lastSyncAt: new Date(), syncError: "" } });
}

/**
 * Access token for a real connection, refreshed first when it is within ten
 * minutes of expiry (Google, LinkedIn, Snapchat and TikTok tokens are short-lived).
 */
async function freshToken(conn: AdConnection, connector: Connector): Promise<string> {
  const soon = conn.expiresAt && conn.expiresAt.getTime() < Date.now() + 10 * 60_000;
  if (!soon || !conn.refreshEnc || !connector.refresh) return decrypt(conn.tokenEnc);
  try {
    const t = await connector.refresh(decrypt(conn.refreshEnc));
    await db.adConnection.update({
      where: { id: conn.id },
      data: {
        tokenEnc: encrypt(t.accessToken), expiresAt: t.expiresAt ?? null, status: "active", lastError: "",
        ...(t.refreshToken ? { refreshEnc: encrypt(t.refreshToken) } : {}),
      },
    });
    return t.accessToken;
  } catch (e) {
    await db.adConnection.update({ where: { id: conn.id }, data: { status: "expired", lastError: `Token refresh failed: ${(e as Error).message}`.slice(0, 500) } });
    throw e;
  }
}

/**
 * Platforms can return several rows for one unique key (a search term matched
 * by two keywords, say); sum them so nothing is dropped by skipDuplicates.
 */
function sumDupes<T extends Record<string, any>>(keys: string[], rows: T[]): T[] {
  const out = new Map<string, T>();
  for (const r of rows) {
    const k = keys.map((x) => (r[x] instanceof Date ? r[x].toISOString() : r[x])).join("|");
    const prev = out.get(k);
    if (!prev) { out.set(k, { ...r }); continue; }
    for (const f of ["spend", "impressions", "clicks", "conversions"]) (prev as any)[f] = Number(prev[f] ?? 0) + Number(r[f] ?? 0);
  }
  return [...out.values()];
}

type Table = "statDaily" | "statBreakdown" | "keyword" | "searchTerm" | "placement";

/** Deletes the window and inserts the fresh rows in one transaction, in chunks. */
async function replace(table: Table, accountId: string, date: { gte: Date; lte: Date }, rows: any[]) {
  await db.$transaction(async (tx) => {
    const model = tx[table] as any;
    await model.deleteMany({ where: { accountId, date } });
    for (let i = 0; i < rows.length; i += 5000) await model.createMany({ data: rows.slice(i, i + 5000), skipDuplicates: true });
  }, { timeout: 120_000 });
}
