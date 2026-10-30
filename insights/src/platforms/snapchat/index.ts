import { addDays } from "@/lib/dates";
import { fetchJson } from "@/lib/http";
import type { Connector, Metrics, RemoteAd, RemoteAdSet, RemoteBreakdown, RemoteCampaign, RemoteStat, RemoteTargetingItem, StatLevel, SyncContext, TokenSet } from "../types";

/** Snapchat Marketing API v1. campaign → Campaign, ad squad → AdSet, ad → Ad. */
const API = "https://adsapi.snapchat.com/v1";
const h = (t: string) => ({ Authorization: `Bearer ${t}` });
const micro = (v: unknown) => Number(v ?? 0) / 1_000_000;

async function token(params: Record<string, string>): Promise<TokenSet> {
  const r: any = await fetchJson("https://accounts.snapchat.com/login/oauth2/access_token", {
    method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ client_id: process.env.SNAPCHAT_CLIENT_ID!, client_secret: process.env.SNAPCHAT_CLIENT_SECRET!, ...params }),
  });
  return { accessToken: r.access_token, refreshToken: r.refresh_token, expiresAt: new Date(Date.now() + (r.expires_in ?? 1800) * 1000), scopes: r.scope };
}

/** Follows paging.next_link. Entities come wrapped: {campaigns:[{campaign:{…}}]}. */
async function list(t: string, url: string, key: string, one: string): Promise<any[]> {
  const out: any[] = [];
  let next: string | undefined = url;
  for (let i = 0; next && i < 50; i++) {
    const r: any = await fetchJson(next, { headers: h(t) });
    out.push(...(r[key] ?? []).map((x: any) => x[one]));
    next = r.paging?.next_link;
  }
  return out;
}

export function snapMetrics(s: Record<string, any>): Metrics {
  const purchases = Number(s.conversion_purchases ?? 0);
  const signups = Number(s.conversion_sign_ups ?? 0);
  return {
    spend: micro(s.spend), impressions: Number(s.impressions ?? 0), reach: Number(s.uniques ?? 0),
    clicks: Number(s.swipes ?? 0), linkClicks: Number(s.swipes ?? 0), purchases, leads: signups, conversions: purchases + signups,
    value: micro(s.conversion_purchases_value), videoViews: Number(s.video_views ?? 0),
  };
}

export function snapTargeting(t: any): RemoteTargetingItem[] {
  const out: RemoteTargetingItem[] = [];
  for (const d of t?.demographics ?? []) {
    if (d.min_age || d.max_age) out.push({ type: "age", value: `${d.min_age ?? 13}-${d.max_age ?? "50+"}` });
    if (d.gender) out.push({ type: "gender", value: String(d.gender).toLowerCase() });
    for (const l of d.languages ?? []) out.push({ type: "language", value: l });
  }
  for (const g of t?.geos ?? []) out.push({ type: "geo", value: [g.country_code?.toUpperCase(), ...(g.region_id ?? []).map(String)].filter(Boolean).join(" ") });
  for (const i of t?.interests ?? []) for (const id of i.category_id ?? []) out.push({ type: "interest", value: String(id), externalId: String(id), exclude: i.operation === "EXCLUDE" });
  for (const s of t?.segments ?? []) for (const id of s.segment_id ?? []) out.push({ type: "audience", value: `Segment ${id}`, externalId: String(id), exclude: s.operation === "EXCLUDE" });
  for (const d of t?.devices ?? []) if (d.os_type) out.push({ type: "device", value: d.os_type });
  return out;
}

const FIELDS = "impressions,swipes,spend,uniques,video_views,conversion_purchases,conversion_purchases_value,conversion_sign_ups";

export const snapchat: Connector = {
  platform: "SNAPCHAT",
  configured: () => !!(process.env.SNAPCHAT_CLIENT_ID && process.env.SNAPCHAT_CLIENT_SECRET),

  authUrl(state, redirectUri) {
    return "https://accounts.snapchat.com/login/oauth2/authorize?" + new URLSearchParams({ client_id: process.env.SNAPCHAT_CLIENT_ID!, redirect_uri: redirectUri, response_type: "code", scope: "snapchat-marketing-api", state });
  },
  async exchange(code, redirectUri) {
    return { ...(await token({ grant_type: "authorization_code", code, redirect_uri: redirectUri })), label: "Snapchat login" };
  },
  refresh: (refreshToken) => token({ grant_type: "refresh_token", refresh_token: refreshToken }),

  async listAccounts(t) {
    const orgs = await list(t, `${API}/me/organizations?with_ad_accounts=true`, "organizations", "organization");
    return orgs.flatMap((o) => (o.ad_accounts ?? []).map((a: any) => ({ externalId: a.id, name: a.name, currency: a.currency, timezone: a.timezone, status: a.status })));
  },

  async fetchStructure(ctx: SyncContext) {
    const t = ctx.accessToken, a = ctx.account.externalId;
    const [camps, squads, ads, creatives] = await Promise.all([
      list(t, `${API}/adaccounts/${a}/campaigns`, "campaigns", "campaign"),
      list(t, `${API}/adaccounts/${a}/adsquads`, "adsquads", "adsquad"),
      list(t, `${API}/adaccounts/${a}/ads`, "ads", "ad"),
      list(t, `${API}/adaccounts/${a}/creatives`, "creatives", "creative"),
    ]);
    const cr = new Map(creatives.map((c) => [c.id, c]));
    const squadCampaign = new Map(squads.map((s) => [s.id, s.campaign_id]));
    const campaigns: RemoteCampaign[] = camps.map((c) => ({
      externalId: c.id, name: c.name, objective: c.objective ?? "", status: c.status ?? "",
      dailyBudget: c.daily_budget_micro ? micro(c.daily_budget_micro) : undefined, startDate: c.start_time?.slice(0, 10), endDate: c.end_time?.slice(0, 10),
    }));
    const adSets: RemoteAdSet[] = squads.map((s) => ({
      externalId: s.id, campaignExternalId: s.campaign_id, name: s.name, status: s.status ?? "", optimizationGoal: s.optimization_goal ?? "",
      bidStrategy: s.bid_strategy ?? "", dailyBudget: s.daily_budget_micro ? micro(s.daily_budget_micro) : undefined,
      targetingRaw: s.targeting, targeting: snapTargeting(s.targeting),
    }));
    const adList: RemoteAd[] = ads.map((x) => {
      const c = cr.get(x.creative_id) ?? {};
      return {
        externalId: x.id, adSetExternalId: x.ad_squad_id, campaignExternalId: squadCampaign.get(x.ad_squad_id) ?? "", name: x.name, status: x.status ?? "",
        format: String(c.type ?? x.type ?? "snap_ad").toLowerCase(), headline: c.headline ?? "", body: c.brand_name ?? "", cta: c.call_to_action ?? "",
        landingUrl: c.web_view_properties?.url ?? "",
      };
    });
    return { campaigns, adSets, ads: adList };
  },

  async fetchStats(ctx, from, to) {
    const t = ctx.accessToken, a = ctx.account.externalId;
    // Snapchat wants whole days in the account's own timezone, end exclusive.
    const tzRange = `start_time=${from}T00:00:00.000&end_time=${addDays(to, 1)}T00:00:00.000`;
    const series = async (url: string) => {
      const r: any = await fetchJson(url, { headers: h(t) });
      return (r.timeseries_stats ?? []).map((x: any) => x.timeseries_stat);
    };

    const stats: RemoteStat[] = [];
    const acc = await series(`${API}/adaccounts/${a}/stats?granularity=DAY&fields=${FIELDS}&${tzRange}`);
    for (const s of acc) for (const p of s.timeseries ?? []) stats.push({ level: "ACCOUNT", externalId: a, date: p.start_time.slice(0, 10), ...snapMetrics(p.stats) });
    for (const [breakdown, level] of [["campaign", "CAMPAIGN"], ["adsquad", "ADSET"], ["ad", "AD"]] as [string, StatLevel][]) {
      const rows = await series(`${API}/adaccounts/${a}/stats?granularity=DAY&breakdown=${breakdown}&fields=${FIELDS}&${tzRange}`);
      for (const s of rows) for (const e of s.breakdown_stats?.[breakdown] ?? []) for (const p of e.timeseries ?? []) {
        stats.push({ level, externalId: e.id, date: p.start_time.slice(0, 10), ...snapMetrics(p.stats) });
      }
    }

    // Audience splits per campaign. Snapchat reports these by day with report_dimension.
    const breakdowns: RemoteBreakdown[] = [];
    const campaigns = [...new Set(stats.filter((s) => s.level === "CAMPAIGN").map((s) => s.externalId))];
    for (const c of campaigns) {
      for (const dimension of ["age", "gender", "country", "os"]) {
        const dim = dimension === "age" ? "age_bucket" : dimension === "os" ? "operating_system" : dimension;
        let rows: any[] = [];
        try { rows = await series(`${API}/campaigns/${c}/stats?granularity=DAY&report_dimension=${dim}&fields=${FIELDS}&${tzRange}`); } catch { continue; }
        for (const s of rows) for (const p of s.timeseries ?? []) for (const d of p.dimension_stats ?? []) {
          const bucket = String(d[dim] ?? d.dimension ?? "unknown");
          breakdowns.push({ level: "CAMPAIGN", externalId: c, date: p.start_time.slice(0, 10), dimension, bucket, ...snapMetrics(d) });
        }
      }
    }
    return { stats, breakdowns };
  },
};
