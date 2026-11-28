import type { Prisma } from "@prisma/client";
import { z } from "zod";
import { db } from "@/lib/db";
import { customerOfVisitor, identifyVisitor, recordConsent, refreshCustomer } from "@/modules/customers/service";
import { isKeyWebEvent } from "@/modules/customers/profile";

// ───────── classification (pure, unit-tested) ─────────

export function parseUA(ua: string): { device: string; browser: string; os: string; bot: boolean } {
  const bot = /bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|whatsapp|monitor|curl|wget|python|axios|go-http/i.test(ua);
  const os = /windows/i.test(ua) ? "Windows" : /iphone|ipad|ipod/i.test(ua) ? "iOS" : /android/i.test(ua) ? "Android" : /mac os/i.test(ua) ? "macOS" : /linux/i.test(ua) ? "Linux" : "Other";
  const browser = /edg\//i.test(ua) ? "Edge" : /opr\/|opera/i.test(ua) ? "Opera" : /samsungbrowser/i.test(ua) ? "Samsung Internet"
    : /fban|fbav|instagram/i.test(ua) ? "Facebook/Instagram app" : /tiktok|musical_ly/i.test(ua) ? "TikTok app" : /snapchat/i.test(ua) ? "Snapchat app"
    : /chrome|crios/i.test(ua) ? "Chrome" : /firefox|fxios/i.test(ua) ? "Firefox" : /safari/i.test(ua) ? "Safari" : "Other";
  const device = /ipad|tablet/i.test(ua) ? "tablet" : /mobi|iphone|android/i.test(ua) ? "mobile" : "desktop";
  return { device, browser, os, bot };
}

const SEARCH = /(^|\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia|naver|seznam)\./i;
const SOCIAL = /(^|\.)(facebook|fb|instagram|t\.co|twitter|x\.com|linkedin|lnkd|tiktok|snapchat|youtube|pinterest|reddit|whatsapp|telegram|threads)\b/i;
const AI = /(^|\.)(chatgpt\.com|chat\.openai\.com|openai\.com|perplexity\.ai|claude\.ai|gemini\.google\.com|bard\.google\.com|copilot\.microsoft\.com|you\.com|phind\.com|poe\.com|deepseek\.com)$/i;
const MAIL = /(^|\.)(mail\.|outlook\.|gmail)/i;

/** GA-style default channel grouping, plus "AI assistants" so GEO work shows up as traffic. */
export function classifyChannel(x: { utmSource?: string; utmMedium?: string; refHost?: string; clickIdType?: string; selfHost?: string }): string {
  const src = (x.utmSource ?? "").toLowerCase(), med = (x.utmMedium ?? "").toLowerCase(), ref = (x.refHost ?? "").toLowerCase();
  const paid = /^(cpc|ppc|paid|paidsearch|paid_search|paid-social|paid_social|paidsocial|cpm|cpv|display|banner|ads?)$/.test(med) || !!x.clickIdType;
  const social = SOCIAL.test(src) || /social|^sm$/.test(med) || SOCIAL.test(ref) || ["fbclid", "ttclid", "li_fat_id", "ScCid"].includes(x.clickIdType ?? "");
  const search = SEARCH.test(src) || SEARCH.test(ref) || ["gclid", "msclkid"].includes(x.clickIdType ?? "");
  if (AI.test(ref) || /chatgpt|perplexity|claude|gemini|copilot/.test(src)) return "AI assistants";
  if (paid && search && !social) return "Paid search";
  if (paid && social) return "Paid social";
  if (/display|banner|cpm/.test(med)) return "Display";
  if (paid) return "Paid other";
  if (/email|e-mail|newsletter/.test(med) || MAIL.test(ref)) return "Email";
  if (/affiliate/.test(med)) return "Affiliates";
  if (search) return "Organic search";
  if (social) return "Organic social";
  if (ref && ref !== x.selfHost) return "Referral";
  return "Direct";
}

export function hostOf(u: string): string {
  try { return new URL(u).hostname.replace(/^www\./, "").toLowerCase(); } catch { return ""; }
}

/** The page's origin must be the site's domain or one of its subdomains. */
export function originAllowed(origin: string, domain: string): boolean {
  const h = hostOf(origin);
  const d = domain.replace(/^https?:\/\//, "").replace(/^www\./, "").replace(/\/.*$/, "").toLowerCase();
  return !!h && (h === d || h.endsWith("." + d));
}

// ───────── payload ─────────

const ev = z.object({
  t: z.enum(["pv", "leave", "click", "rage_click", "dead_click", "form_start", "form_submit", "custom", "conversion", "identify", "consent"]),
  ts: z.number(), p: z.string().max(1000), d: z.enum(["mobile", "tablet", "desktop"]),
  pv: z.string().max(40).optional(), ti: z.string().max(300).optional(), r: z.string().max(1000).optional(),
  vw: z.number().optional(), vh: z.number().optional(), dh: z.number().optional(), dw: z.number().optional(),
  u: z.record(z.string(), z.string().max(300)).optional(), nv: z.boolean().optional(),
  a: z.number().optional(), sc: z.number().optional(),
  xp: z.number().min(0).max(100).optional(), y: z.number().optional(), sel: z.string().max(400).optional(), tx: z.string().max(200).optional(),
  n: z.string().max(200).optional(), val: z.number().optional(),
  pr: z.record(z.string().max(40), z.union([z.string().max(200), z.number(), z.boolean()])).optional(),
  id: z.object({ phone: z.string().max(40).optional(), email: z.string().max(200).optional(), name: z.string().max(120).optional(), crm: z.string().max(120).optional(), uid: z.string().max(120).optional() }).optional(),
  cs: z.object({ analytics: z.boolean().optional(), ads: z.boolean().optional(), marketing: z.boolean().optional() }).optional(),
});

export const payloadSchema = z.object({
  k: z.string().min(8).max(64),
  v: z.string().min(4).max(64),
  s: z.string().min(4).max(64),
  e: z.array(ev).max(200),
});

export type Payload = z.infer<typeof payloadSchema>;

const CLICK_IDS = ["gclid", "fbclid", "ttclid", "li_fat_id", "ScCid", "msclkid"];

// Site lookups are hot (every beacon); cache briefly.
type SiteRef = { id: string; domain: string; companyId: string; clientId: string };
const siteCache = new Map<string, { at: number; site: SiteRef | null }>();
export async function siteByKey(key: string): Promise<SiteRef | null> {
  const c = siteCache.get(key);
  if (c && Date.now() - c.at < 60_000) return c.site;
  const row = await db.site.findUnique({ where: { trackingKey: key }, select: { id: true, domain: true, companyId: true, company: { select: { clientId: true } } } });
  const site = row ? { id: row.id, domain: row.domain, companyId: row.companyId, clientId: row.company.clientId } : null;
  siteCache.set(key, { at: Date.now(), site });
  return site;
}

type Meta = { ua: string; country: string; city: string };

/**
 * Writes one beacon. The session row is created by its first page view and
 * updated by everything after; goals are evaluated as events arrive.
 */
export async function ingest(site: SiteRef, p: Payload, meta: Meta) {
  const siteId = site.id, siteDomain = site.domain;
  const ua = parseUA(meta.ua);
  if (ua.bot) return;
  const sid = `${siteId}:${p.s}`;
  const now = new Date();
  // Clamp client clocks: never in the future, never more than a day old.
  const at = (ts: number) => new Date(Math.min(now.getTime(), Math.max(now.getTime() - 86_400_000, ts)));

  let session = await db.trackSession.findUnique({ where: { id: sid } });
  const first = p.e.find((e) => e.t === "pv") ?? p.e[0];
  if (!session) {
    if (!first) return;
    const u = first.u ?? {};
    const clickIdType = CLICK_IDS.find((k) => u[k]) ?? "";
    const refHost = hostOf(first.r ?? "");
    const self = siteDomain.replace(/^www\./, "");
    const external = refHost && refHost !== self && !refHost.endsWith("." + self);
    session = await db.trackSession.upsert({
      where: { id: sid },
      update: {},
      create: {
        id: sid, siteId, visitorId: p.v, isNew: first.nv ?? true, startedAt: at(first.ts), lastSeenAt: at(first.ts),
        landingPath: first.p, exitPath: first.p, referrer: external ? (first.r ?? "").slice(0, 500) : "", refHost: external ? refHost : "",
        utmSource: u.utm_source ?? "", utmMedium: u.utm_medium ?? "", utmCampaign: u.utm_campaign ?? "", utmContent: u.utm_content ?? "", utmTerm: u.utm_term ?? "",
        clickIdType, channel: classifyChannel({ utmSource: u.utm_source, utmMedium: u.utm_medium, refHost: external ? refHost : "", clickIdType, selfHost: self }),
        device: first.d ?? ua.device, browser: ua.browser, os: ua.os, country: meta.country, city: meta.city,
      },
    });
  }

  const goals = await db.goal.findMany({ where: { siteId } });
  let converted = session.converted;
  let pageviews = 0, events = 0, maxScroll = session.maxScroll, lastTs = session.lastSeenAt, exitPath = session.exitPath;
  const eventRows: Prisma.TrackEventCreateManyInput[] = [];

  type Who = NonNullable<Payload["e"][number]["id"]>;
  type Consent = NonNullable<Payload["e"][number]["cs"]>;
  const found: { identity: { who: Who; at: Date } | null; consent: Consent | null } = { identity: null, consent: null };
  let keyEvent = false;
  for (const e of p.e) {
    const ts = at(e.ts);
    // Contact details are never written to the event tables; they go to the encrypted customer profile.
    if (e.t === "identify") { if (e.id) found.identity = { who: { ...(found.identity?.who ?? {}), ...e.id }, at: ts }; continue; }
    if (e.t === "consent") { found.consent = { ...(found.consent ?? {}), ...(e.cs ?? {}) }; eventRows.push({ sessionId: sid, siteId, path: e.p, type: "consent", name: "", ts, device: e.d, props: e.cs ?? {} }); continue; }
    if ((e.t === "custom" && isKeyWebEvent(e.n ?? "")) || e.t === "form_submit" || e.t === "conversion") keyEvent = true;
    if (ts > lastTs) { lastTs = ts; exitPath = e.p; }
    if (e.t === "pv") {
      pageviews++;
      await db.trackPageview.create({ data: { sessionId: sid, siteId, path: e.p, title: e.ti ?? "", ts, viewportW: Math.round(e.vw ?? 0), viewportH: Math.round(e.vh ?? 0), docH: Math.round(e.dh ?? 0), device: e.d, pvKey: e.pv ?? "" } });
      if (goals.some((g) => g.kind === "path" && e.p.startsWith(g.match))) converted = true;
      continue;
    }
    if (e.t === "leave") {
      maxScroll = Math.max(maxScroll, Math.round(e.sc ?? 0));
      if (e.pv) await db.trackPageview.updateMany({ where: { sessionId: sid, pvKey: e.pv }, data: { activeSec: Math.min(3600, Math.round(e.a ?? 0)), scrollPct: Math.min(100, Math.round(e.sc ?? 0)), ...(e.dh ? { docH: Math.round(e.dh) } : {}) } });
      continue;
    }
    events++;
    if (e.t === "conversion" || (e.t === "custom" && goals.some((g) => g.kind === "event" && g.match === e.n)) || (e.t === "form_submit" && goals.some((g) => g.kind === "event" && g.match === "form_submit"))) converted = true;
    eventRows.push({
      sessionId: sid, siteId, path: e.p, type: e.t, name: e.n ?? "", value: e.val ?? null, ts, device: e.d,
      xPct: e.xp ?? null, yPx: e.y != null ? Math.round(e.y) : null, docW: e.dw != null ? Math.round(e.dw) : null, docH: e.dh != null ? Math.round(e.dh) : null,
      selector: e.sel ?? "", text: e.tx ?? "", ...(e.pr ? { props: e.pr } : {}),
    });
  }
  if (eventRows.length) await db.trackEvent.createMany({ data: eventRows });

  await db.trackSession.update({
    where: { id: sid },
    data: {
      pageviews: { increment: pageviews }, events: { increment: events }, lastSeenAt: lastTs, exitPath, maxScroll, converted,
      durationSec: Math.max(0, Math.round((lastTs.getTime() - session.startedAt.getTime()) / 1000)),
    },
  });

  // Customers: a browser that identifies itself joins (or creates) a profile; a known
  // browser's key actions refresh that profile's stage and scores.
  const { identity, consent } = found;
  if (identity) await identifyVisitor(site, p.v, identity.who, consent, identity.at);
  else if (keyEvent || consent) {
    const customerId = await customerOfVisitor(site, p.v);
    if (customerId) {
      if (consent) await recordConsent(site.clientId, customerId, consent, "website");
      await refreshCustomer(customerId);
    }
  }
}
