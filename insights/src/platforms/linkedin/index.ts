import { fetchJson } from "@/lib/http";
import type { Connector, Metrics, RemoteAd, RemoteAdSet, RemoteBreakdown, RemoteCampaign, RemoteStat, RemoteTargetingItem, StatLevel, SyncContext, TokenSet } from "../types";

/**
 * LinkedIn Marketing API (versioned REST). Mapping onto our levels:
 * campaign group → Campaign, campaign → AdSet, creative → Ad.
 */
const VERSION = () => process.env.LINKEDIN_API_VERSION || "202409";
const API = "https://api.linkedin.com/rest";
const SCOPES = ["r_ads", "r_ads_reporting", "r_organization_social", "r_basicprofile"];

const h = (token: string) => ({ Authorization: `Bearer ${token}`, "LinkedIn-Version": VERSION(), "X-Restli-Protocol-Version": "2.0.0" });
const urnId = (urn: string) => String(urn ?? "").split(":").pop() ?? "";
const enc = (s: string) => encodeURIComponent(s);

async function token(params: Record<string, string>): Promise<TokenSet> {
  const r: any = await fetchJson("https://www.linkedin.com/oauth/v2/accessToken", {
    method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ client_id: process.env.LINKEDIN_CLIENT_ID!, client_secret: process.env.LINKEDIN_CLIENT_SECRET!, ...params }),
  });
  return { accessToken: r.access_token, refreshToken: r.refresh_token, expiresAt: new Date(Date.now() + (r.expires_in ?? 5_184_000) * 1000), scopes: r.scope };
}

/** Pages through a LinkedIn finder (start/count paging). */
async function all(token: string, url: string, max = 50): Promise<any[]> {
  const out: any[] = [];
  for (let start = 0, i = 0; i < max; i++, start += 100) {
    const r: any = await fetchJson(`${url}${url.includes("?") ? "&" : "?"}start=${start}&count=100`, { headers: h(token) });
    out.push(...(r.elements ?? []));
    if ((r.elements ?? []).length < 100) break;
  }
  return out;
}

const dateParam = (d: string) => { const [y, m, dd] = d.split("-").map(Number); return `(year:${y},month:${m},day:${dd})`; };

export function liMetrics(e: Record<string, any>): Metrics {
  const leads = Number(e.oneClickLeads ?? 0);
  const conv = Number(e.externalWebsiteConversions ?? 0);
  return {
    spend: Number(e.costInLocalCurrency ?? 0), impressions: Number(e.impressions ?? 0), reach: Number(e.approximateMemberReach ?? 0),
    clicks: Number(e.clicks ?? 0), linkClicks: Number(e.landingPageClicks ?? 0), leads, conversions: conv + leads,
    value: Number(e.conversionValueInLocalCurrency ?? 0), videoViews: Number(e.videoViews ?? 0), engagements: Number(e.totalEngagements ?? 0),
  };
}

const FACET: Record<string, string> = {
  "urn:li:adTargetingFacet:titles": "job_title", "urn:li:adTargetingFacet:seniorities": "seniority", "urn:li:adTargetingFacet:industries": "industry",
  "urn:li:adTargetingFacet:staffCountRanges": "company_size", "urn:li:adTargetingFacet:locations": "geo", "urn:li:adTargetingFacet:interfaceLocales": "language",
  "urn:li:adTargetingFacet:skills": "interest", "urn:li:adTargetingFacet:interests": "interest", "urn:li:adTargetingFacet:memberBehaviors": "behavior",
  "urn:li:adTargetingFacet:audienceMatchingSegments": "audience", "urn:li:adTargetingFacet:dynamicSegments": "audience", "urn:li:adTargetingFacet:employers": "company",
  "urn:li:adTargetingFacet:groups": "interest", "urn:li:adTargetingFacet:degrees": "demographic", "urn:li:adTargetingFacet:fieldsOfStudy": "demographic",
  "urn:li:adTargetingFacet:ageRanges": "age", "urn:li:adTargetingFacet:genders": "gender",
};
const SENIORITY: Record<string, string> = { "1": "Unpaid", "2": "Training", "3": "Entry", "4": "Senior", "5": "Manager", "6": "Director", "7": "VP", "8": "CXO", "9": "Partner", "10": "Owner" };
const STAFF: Record<string, string> = { "(1,1)": "1", "(2,10)": "2-10", "(11,50)": "11-50", "(51,200)": "51-200", "(201,500)": "201-500", "(501,1000)": "501-1000", "(1001,5000)": "1001-5000", "(5001,10000)": "5001-10000", "(10001,2147483647)": "10001+" };

/** Readable value for a targeting/pivot URN where it can be decoded offline. */
export function liValue(urn: string): string {
  if (urn.startsWith("urn:li:seniority:")) return SENIORITY[urnId(urn)] ?? urn;
  if (urn.startsWith("urn:li:staffCountRange:")) return STAFF[urn.slice("urn:li:staffCountRange:".length)] ?? urn;
  return urn;
}

/** Flattens targetingCriteria {include:{and:[{or:{facet:[urns]}}]}, exclude:{or:{facet:[urns]}}}. */
export function liTargeting(tc: any): RemoteTargetingItem[] {
  const out: RemoteTargetingItem[] = [];
  const add = (facets: Record<string, string[]>, exclude: boolean) => {
    for (const [facet, urns] of Object.entries(facets ?? {})) {
      const type = FACET[facet] ?? "other";
      for (const u of urns ?? []) out.push({ type, value: liValue(u), externalId: u, exclude });
    }
  };
  for (const clause of tc?.include?.and ?? []) add(clause.or ?? {}, false);
  add(tc?.exclude?.or ?? {}, true);
  return out;
}

/** Resolves title / industry / geo URNs to names in batches; anything unresolved keeps its URN. */
async function resolveNames(token: string, urns: string[]): Promise<Map<string, string>> {
  const names = new Map<string, string>();
  const groups: Record<string, { path: string; field: (e: any) => string }> = {
    "urn:li:title:": { path: "titles", field: (e) => e.name?.localized?.en_US ?? e.name?.localized?.[Object.keys(e.name?.localized ?? {})[0]] },
    "urn:li:industry:": { path: "industries", field: (e) => e.name?.localized?.en_US ?? "" },
    "urn:li:geo:": { path: "geo", field: (e) => e.defaultLocalizedName?.value ?? "" },
  };
  for (const [prefix, g] of Object.entries(groups)) {
    const ids = [...new Set(urns.filter((u) => u.startsWith(prefix)).map(urnId))];
    for (let i = 0; i < ids.length; i += 50) {
      try {
        const r: any = await fetchJson(`${API}/${g.path}?ids=List(${ids.slice(i, i + 50).join(",")})`, { headers: h(token) });
        for (const [id, e] of Object.entries(r.results ?? {})) names.set(`${prefix}${id}`, g.field(e) || `${prefix}${id}`);
      } catch { /* names are cosmetic — keep URNs */ }
    }
  }
  return names;
}

const PIVOT_LEVEL: [string, StatLevel][] = [["ACCOUNT", "ACCOUNT"], ["CAMPAIGN_GROUP", "CAMPAIGN"], ["CAMPAIGN", "ADSET"], ["CREATIVE", "AD"]];
const DEMO_PIVOTS: [string, string][] = [["MEMBER_JOB_TITLE", "job_title"], ["MEMBER_SENIORITY", "seniority"], ["MEMBER_INDUSTRY", "industry"], ["MEMBER_COMPANY_SIZE", "company_size"], ["MEMBER_COUNTRY_V2", "country"]];
const FIELDS = "dateRange,pivotValues,costInLocalCurrency,impressions,clicks,landingPageClicks,externalWebsiteConversions,oneClickLeads,videoViews,totalEngagements,approximateMemberReach,conversionValueInLocalCurrency";

export const linkedin: Connector = {
  platform: "LINKEDIN",
  configured: () => !!(process.env.LINKEDIN_CLIENT_ID && process.env.LINKEDIN_CLIENT_SECRET),

  authUrl(state, redirectUri) {
    return `https://www.linkedin.com/oauth/v2/authorization?` + new URLSearchParams({ response_type: "code", client_id: process.env.LINKEDIN_CLIENT_ID!, redirect_uri: redirectUri, state, scope: SCOPES.join(" ") });
  },
  async exchange(code, redirectUri) {
    return { ...(await token({ grant_type: "authorization_code", code, redirect_uri: redirectUri })), label: "LinkedIn login" };
  },
  refresh: (refreshToken) => token({ grant_type: "refresh_token", refresh_token: refreshToken }),

  async listAccounts(t) {
    const rows = await all(t, `${API}/adAccounts?q=search&search=(status:(values:List(ACTIVE,DRAFT,CANCELED)))`);
    return rows.map((a) => ({ externalId: String(a.id), name: a.name, currency: a.currency ?? "USD", timezone: "UTC", status: a.status }));
  },

  async fetchStructure(ctx: SyncContext) {
    const t = ctx.accessToken, acc = ctx.account.externalId;
    const [groups, camps, creatives] = await Promise.all([
      all(t, `${API}/adAccounts/${acc}/adCampaignGroups?q=search`),
      all(t, `${API}/adAccounts/${acc}/adCampaigns?q=search`),
      all(t, `${API}/adAccounts/${acc}/creatives?q=criteria`),
    ]);
    const targeting = new Map(camps.map((c) => [String(c.id), liTargeting(c.targetingCriteria)]));
    const names = await resolveNames(t, [...targeting.values()].flat().map((x) => x.externalId ?? ""));
    const campaigns: RemoteCampaign[] = groups.map((g) => ({
      externalId: String(g.id), name: g.name, objective: g.objectiveType ?? "", status: g.status ?? "",
      totalBudget: g.totalBudget?.amount ? Number(g.totalBudget.amount) : undefined,
      startDate: g.runSchedule?.start ? new Date(g.runSchedule.start).toISOString().slice(0, 10) : undefined,
    }));
    const adSets: RemoteAdSet[] = camps.map((c) => {
      const items = (targeting.get(String(c.id)) ?? []).map((x) => ({ ...x, value: names.get(x.externalId ?? "") ?? x.value }));
      return {
        externalId: String(c.id), campaignExternalId: urnId(c.campaignGroup), name: c.name, status: c.status ?? "",
        optimizationGoal: c.objectiveType ?? "", bidStrategy: c.costType ?? "", dailyBudget: c.dailyBudget?.amount ? Number(c.dailyBudget.amount) : undefined,
        targetingRaw: c.targetingCriteria, targeting: items,
      };
    });
    const groupOf = new Map(camps.map((c) => [String(c.id), urnId(c.campaignGroup)]));
    const ads: RemoteAd[] = creatives.map((cr) => {
      const campaignId = urnId(cr.campaign);
      return {
        externalId: urnId(cr.id), adSetExternalId: campaignId, campaignExternalId: groupOf.get(campaignId) ?? "",
        name: cr.name ?? `Creative ${urnId(cr.id)}`, status: cr.intendedStatus ?? "", format: cr.content ? Object.keys(cr.content)[0] ?? "" : "",
        headline: cr.content?.textAd?.headline ?? "", body: cr.content?.textAd?.description ?? "", landingUrl: cr.content?.textAd?.landingPage ?? "",
      };
    }).filter((a) => a.campaignExternalId);
    return { campaigns, adSets, ads };
  },

  async fetchStats(ctx, from, to) {
    const t = ctx.accessToken, acc = ctx.account.externalId;
    const base = `${API}/adAnalytics?q=analytics&timeGranularity=DAILY&dateRange=(start:${dateParam(from)},end:${dateParam(to)})&accounts=List(${enc(`urn:li:sponsoredAccount:${acc}`)})&fields=${FIELDS}`;
    const day = (e: any) => { const s = e.dateRange.start; return `${s.year}-${String(s.month).padStart(2, "0")}-${String(s.day).padStart(2, "0")}`; };

    const stats: RemoteStat[] = [];
    for (const [pivot, level] of PIVOT_LEVEL) {
      const r: any = await fetchJson(`${base}&pivot=${pivot}`, { headers: h(t) });
      for (const e of r.elements ?? []) stats.push({ level, externalId: level === "ACCOUNT" ? acc : urnId(e.pivotValues?.[0]), date: day(e), ...liMetrics(e) });
    }

    // Demographic pivots are per campaign group (our campaign level), one call per pivot.
    const groups = await all(t, `${API}/adAccounts/${acc}/adCampaignGroups?q=search`);
    const breakdowns: RemoteBreakdown[] = [];
    const urns: string[] = [];
    for (const g of groups) {
      for (const [pivot, dimension] of DEMO_PIVOTS) {
        const r: any = await fetchJson(`${base.replace(/&accounts=[^&]+/, "")}&campaignGroups=List(${enc(`urn:li:sponsoredCampaignGroup:${g.id}`)})&pivot=${pivot}`, { headers: h(t) });
        for (const e of r.elements ?? []) {
          const u = String(e.pivotValues?.[0] ?? "");
          urns.push(u);
          breakdowns.push({ level: "CAMPAIGN", externalId: String(g.id), date: day(e), dimension, bucket: u, ...liMetrics(e) });
        }
      }
    }
    const names = await resolveNames(t, urns);
    for (const b of breakdowns) b.bucket = names.get(b.bucket) ?? liValue(b.bucket);
    return { stats, breakdowns };
  },
};
