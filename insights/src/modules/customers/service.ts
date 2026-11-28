import type { Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { decrypt } from "@/lib/crypto";
import { identify, scopeOf } from "@/modules/identity/service";
import { ID_LABEL, idHash, strength, type IdType } from "@/modules/identity/rules";
import { EVENT_LABEL, identityScore, intentScore, isKeyWebEvent, leadContact, matchProjects, parseBudget, stageOf, valueScore, type Ev, type ProjectRef } from "./profile";

/** Worker-safe customer services: profile refresh, lead resolution, website hooks, timeline. */

const projCache = new Map<string, { at: number; list: (ProjectRef & { priceFrom: number | null; priceTo: number | null })[] }>();
export async function projectsOf(clientId: string) {
  const hit = projCache.get(clientId);
  if (hit && Date.now() - hit.at < 60_000) return hit.list;
  const list = await db.project.findMany({ where: { clientId, active: true }, select: { id: true, name: true, slug: true, aliases: true, priceFrom: true, priceTo: true } });
  projCache.set(clientId, { at: Date.now(), list });
  return list;
}
export const forgetProjects = (clientId: string) => projCache.delete(clientId);

/** Website visitors ("siteId:visitorId") behind a customer's browser identifiers. */
export async function visitorsOf(customerId: string): Promise<{ siteId: string; visitorId: string }[]> {
  const rows = await db.customerIdentifier.findMany({ where: { customerId, type: "anon_id" }, select: { valueEnc: true } });
  return rows.flatMap((r) => {
    const v = r.valueEnc ? decrypt(r.valueEnc) : "";
    const i = v.indexOf(":");
    return i > 0 ? [{ siteId: v.slice(0, i), visitorId: v.slice(i + 1) }] : [];
  });
}

async function webActivity(customerId: string, sinceDays = 180) {
  const visitors = await visitorsOf(customerId);
  if (!visitors.length) return { sessions: [], events: [] as Ev[] };
  const sessions = await db.trackSession.findMany({
    where: { OR: visitors, startedAt: { gte: new Date(Date.now() - sinceDays * 86_400_000) } },
    orderBy: { startedAt: "asc" }, take: 500,
    select: { id: true, siteId: true, startedAt: true, pageviews: true, landingPath: true, channel: true, utmSource: true, utmMedium: true, utmCampaign: true, utmContent: true, clickIdType: true, refHost: true, durationSec: true, device: true, converted: true },
  });
  const evs = sessions.length
    ? await db.trackEvent.findMany({ where: { sessionId: { in: sessions.map((s) => s.id) }, type: { in: ["custom", "conversion", "form_submit"] } }, select: { sessionId: true, type: true, name: true, ts: true, path: true, props: true, value: true }, take: 2000, orderBy: { ts: "asc" } })
    : [];
  const events: Ev[] = evs.flatMap((e) => {
    const type = e.type === "form_submit" ? "form_submit" : e.name;
    if (!isKeyWebEvent(type)) return [];
    return [{ type, occurredAt: e.ts, source: "website", props: { ...((e.props as Record<string, unknown>) ?? {}), path: e.path, ...(e.value != null ? { value: e.value } : {}) } }];
  });
  return { sessions, events };
}

/** Recomputes stage, scores, projects and first/last touch from everything known about the customer. */
export async function refreshCustomer(customerId: string) {
  const c = await db.customer.findUnique({ where: { id: customerId }, include: { identifiers: { select: { type: true, verified: true, source: true } }, events: { select: { type: true, occurredAt: true, props: true, projectId: true, source: true } } } });
  if (!c || c.mergedIntoId) return;
  const projects = await projectsOf(c.clientId);
  const web = await webActivity(c.id);
  const events: Ev[] = [...c.events.map((e) => ({ ...e, props: (e.props as Record<string, unknown>) ?? {} })), ...web.events];
  const visits = web.sessions.map((s) => ({ startedAt: s.startedAt, pageviews: s.pageviews }));

  // Projects: tagged on events, named in event properties, or in the pages they visited.
  const projectIds = new Set<string>(events.flatMap((e) => (e.projectId ? [e.projectId] : [])));
  for (const e of web.events) for (const id of matchProjects(`${e.props?.project ?? ""} ${e.props?.path ?? ""}`, projects)) projectIds.add(id);
  for (const s of web.sessions) for (const id of matchProjects(`${s.landingPath} ${s.utmCampaign}`, projects)) projectIds.add(id);

  const budget = c.budget ?? parseBudget(c.budgetText);
  const purchases = events.filter((e) => e.type === "purchase");
  const intent = intentScore(events, visits);
  const value = valueScore({ budget, prices: projects.filter((p) => projectIds.has(p.id)).map((p) => ({ from: p.priceFrom, to: p.priceTo })), purchases: purchases.length, revenue: purchases.reduce((a, e) => a + Number(e.props?.value ?? 0), 0) });
  const ident = identityScore(c.identifiers.map((i) => ({ strength: strength(i.type as IdType, i.verified), source: i.source, label: ID_LABEL[i.type as IdType] ?? i.type })));

  // Touches: ad leads (platform + campaign) and website sessions that came from ads or campaigns.
  const touches = [
    ...c.events.filter((e) => e.type === "lead").map((e) => ({ at: e.occurredAt, ...(e.props as Record<string, unknown>), kind: "lead" })),
    ...web.sessions.filter((s) => s.utmSource || s.clickIdType || s.refHost).map((s) => ({ at: s.startedAt, kind: "visit", channel: s.channel, source: s.utmSource || s.refHost, medium: s.utmMedium, campaign: s.utmCampaign, content: s.utmContent, clickId: s.clickIdType })),
  ].sort((a, b) => +a.at - +b.at);
  const touch = (t: (typeof touches)[number] | undefined) => (t ? (JSON.parse(JSON.stringify(t)) as Prisma.InputJsonValue) : undefined);
  const last = Math.max(+c.lastSeenAt, ...events.map((e) => +e.occurredAt), ...visits.map((v) => +v.startedAt));

  await db.customer.update({
    where: { id: c.id },
    data: {
      stage: stageOf(events, visits), intentScore: intent.score, valueScore: value.score, identityScore: ident.score,
      scoreWhy: { intent: intent.why, value: value.why, identity: ident.why }, projectIds: [...projectIds], budget: c.budget ?? budget,
      firstTouch: touch(touches[0]) ?? undefined, lastTouch: touch(touches.at(-1)) ?? undefined, lastSeenAt: new Date(last),
    },
  });
}

/**
 * Turns synced ad leads into customers: phone, email and the lead id resolve the
 * person; the lead goes on the timeline with its campaign, ad set, ad and answers.
 * Runs in the worker in batches; returns how many leads were resolved.
 */
export async function resolveLeads(opts: { clientId?: string; limit?: number; budgetMs?: number } = {}): Promise<number> {
  const started = Date.now();
  let done = 0;
  while (Date.now() - started < (opts.budgetMs ?? 20_000)) {
    const leads = await db.lead.findMany({
      where: { customerId: null, ...(opts.clientId ? { account: { connection: { clientId: opts.clientId } } } : {}) },
      orderBy: { createdAt: "desc" }, take: Math.min(200, opts.limit ?? 200),
      include: { account: { select: { companyId: true, connection: { select: { clientId: true, isDemo: true } } } } },
    });
    if (!leads.length) break;
    const names = await entityNames(leads);
    const touched = new Set<string>();
    for (const l of leads) {
      const clientId = l.account.connection.clientId;
      // Gildana's shared agency logins belong to no workspace: their leads aren't anyone's customers.
      if (!clientId) { await db.lead.update({ where: { id: l.id }, data: { customerId: "-" } }); continue; }
      const f = leadContact((l.fields as Record<string, unknown>) ?? {});
      const res = await identify({
        clientId, companyId: l.account.companyId, source: `${l.platform.toLowerCase()} lead`, at: l.createdAt,
        ids: [{ type: "phone", value: f.phone }, { type: "email", value: f.email }, { type: "lead_id", value: `${l.platform}:${l.externalId}` }],
        profile: { name: f.name, city: f.city, budgetText: f.budget },
      }).catch((e) => { console.error("[customers] lead", l.id, (e as Error).message); return null; });
      // A lead without any usable contact detail is still a person: identify() gets its lead id.
      const customerId = res?.customerId;
      if (!customerId) { await db.lead.update({ where: { id: l.id }, data: { customerId: "-" } }); continue; }
      const campaign = l.campaignId ? names.campaigns.get(l.campaignId) ?? "" : "";
      const adSet = l.adSetId ? names.adSets.get(l.adSetId) ?? "" : "";
      const ad = l.adId ? names.ads.get(l.adId) ?? "" : "";
      const projects = await projectsOf(clientId);
      const projectId = matchProjects(`${campaign} ${adSet} ${l.formName} ${Object.values(f.answers).join(" ")}`, projects)[0] ?? null;
      await db.customerEvent.upsert({
        where: { clientId_dedupKey: { clientId, dedupKey: `lead:${l.id}` } },
        update: { customerId },
        create: {
          clientId, customerId, source: "ads", type: "lead", occurredAt: l.createdAt, projectId, title: l.formName || "Lead form",
          props: { platform: l.platform, campaignId: l.campaignId, adSetId: l.adSetId, adId: l.adId, campaign, adSet, ad, form: l.formName, answers: f.answers },
          dedupKey: `lead:${l.id}`,
        },
      });
      await db.lead.update({ where: { id: l.id }, data: { customerId } });
      if (l.account.connection.isDemo) await db.customer.update({ where: { id: customerId }, data: { isDemo: true } });
      touched.add(customerId);
      done++;
    }
    for (const id of touched) await refreshCustomer(id).catch((e) => console.error("[customers] refresh", id, (e as Error).message));
    if (opts.limit && done >= opts.limit) break;
  }
  return done;
}

async function entityNames(leads: { campaignId: string | null; adSetId: string | null; adId: string | null }[]) {
  const ids = (k: "campaignId" | "adSetId" | "adId") => [...new Set(leads.flatMap((l) => (l[k] ? [l[k]!] : [])))];
  const [c, s, a] = await Promise.all([
    db.campaign.findMany({ where: { id: { in: ids("campaignId") } }, select: { id: true, name: true } }),
    db.adSet.findMany({ where: { id: { in: ids("adSetId") } }, select: { id: true, name: true } }),
    db.ad.findMany({ where: { id: { in: ids("adId") } }, select: { id: true, name: true } }),
  ]);
  return { campaigns: new Map(c.map((x) => [x.id, x.name])), adSets: new Map(s.map((x) => [x.id, x.name])), ads: new Map(a.map((x) => [x.id, x.name])) };
}

// ───────── website ─────────

export type WebIdentity = { phone?: string; email?: string; name?: string; crm?: string; uid?: string };
export type WebConsent = { analytics?: boolean; ads?: boolean; marketing?: boolean };

const anonValue = (siteId: string, visitorId: string) => `${siteId}:${visitorId}`;

/**
 * A browser identified itself (form with phone/email, logged-in user, CRM link).
 * Its first-party visitor id joins the customer, so every past and future visit
 * from it appears on their timeline. Values arrive over TLS and are stored encrypted.
 */
export async function identifyVisitor(site: { id: string; companyId: string; clientId: string }, visitorId: string, who: WebIdentity, consent: WebConsent | null, at: Date) {
  const res = await identify({
    clientId: site.clientId, companyId: site.companyId, source: "website", at,
    ids: [
      { type: "anon_id", value: anonValue(site.id, visitorId) }, { type: "phone", value: who.phone ?? "" }, { type: "email", value: who.email ?? "" },
      { type: "crm_id", value: who.crm ?? "" }, { type: "account_id", value: who.uid ?? "" },
    ],
    profile: { name: who.name },
  });
  if (!res) return null;
  await db.customerEvent.create({ data: { clientId: site.clientId, customerId: res.customerId, source: "website", type: "identify", occurredAt: at, title: "Identified on the website", props: { siteId: site.id, fields: Object.keys(who).filter((k) => (who as Record<string, unknown>)[k]) }, dedupKey: `identify:${site.id}:${visitorId}:${at.getTime()}` } }).catch(() => {});
  if (consent) await recordConsent(site.clientId, res.customerId, consent, "website", at);
  await refreshCustomer(res.customerId);
  return res.customerId;
}

/** The customer behind a known browser, if it has identified itself before. */
export async function customerOfVisitor(site: { id: string; companyId: string; clientId: string }, visitorId: string): Promise<string | null> {
  const { scopeId } = await scopeOf(site.clientId, site.companyId);
  const row = await db.customerIdentifier.findUnique({ where: { scopeId_type_hash: { scopeId, type: "anon_id", hash: idHash("anon_id", anonValue(site.id, visitorId)) } }, select: { customerId: true } });
  return row?.customerId ?? null;
}

export async function recordConsent(clientId: string, customerId: string, c: WebConsent, source: string, at = new Date()) {
  const rows = (Object.entries(c) as [string, boolean | undefined][]).filter(([, v]) => typeof v === "boolean").map(([purpose, v]) => ({ clientId, customerId, purpose, status: v ? "granted" : "denied", source, at }));
  if (rows.length) await db.consentRecord.createMany({ data: rows });
}

/** Latest status per purpose. */
export function consentSummary(rows: { purpose: string; status: string; at: Date; source: string }[]) {
  const out: Record<string, { status: string; at: Date; source: string }> = {};
  for (const r of [...rows].sort((a, b) => +a.at - +b.at)) out[r.purpose] = { status: r.status, at: r.at, source: r.source };
  return out;
}

// ───────── timeline ─────────

export type TimelineItem = { at: Date; source: string; type: string; title: string; detail: string; projectId?: string | null; props?: Record<string, unknown> };

export async function timeline(customerId: string, limit = 300): Promise<TimelineItem[]> {
  const [events, web] = await Promise.all([
    db.customerEvent.findMany({ where: { customerId }, orderBy: { occurredAt: "desc" }, take: limit }),
    webActivity(customerId, 365),
  ]);
  const items: TimelineItem[] = events.map((e) => {
    const p = (e.props as Record<string, unknown>) ?? {};
    const detail = e.type === "lead" ? [p.platform, p.campaign, p.adSet, p.ad].filter(Boolean).join(" › ") : typeof p.detail === "string" ? p.detail : "";
    return { at: e.occurredAt, source: e.source, type: e.type, title: e.title || EVENT_LABEL[e.type] || e.type, detail, projectId: e.projectId, props: p };
  });
  for (const s of web.sessions) {
    const from = [s.channel, s.utmSource && `${s.utmSource}${s.utmMedium ? ` / ${s.utmMedium}` : ""}`, s.utmCampaign && `campaign “${s.utmCampaign}”`, s.utmContent && `ad “${s.utmContent}”`].filter(Boolean).join(" · ");
    items.push({ at: s.startedAt, source: "website", type: "visit", title: `Website visit — ${s.pageviews} page${s.pageviews === 1 ? "" : "s"}, ${Math.round(s.durationSec / 60)} min`, detail: `${from} · landed on ${s.landingPath} · ${s.device}` });
  }
  for (const e of web.events) items.push({ at: e.occurredAt, source: "website", type: e.type, title: EVENT_LABEL[e.type] ?? e.type, detail: [e.props?.project, e.props?.unit, e.props?.path].filter(Boolean).join(" · "), props: e.props });
  return items.sort((a, b) => +b.at - +a.at).slice(0, limit);
}
