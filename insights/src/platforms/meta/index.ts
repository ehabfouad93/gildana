import { fetchJson } from "@/lib/http";
import type { Connector, RemoteAd, RemoteAdSet, RemoteBreakdown, RemoteCampaign, RemoteLead, RemoteStat, StatLevel, SyncContext } from "../types";
import { PlatformError } from "../types";
import { META_BREAKDOWNS, breakdownValue, flattenTargeting, insightMetrics, minor } from "./normalize";

const V = () => process.env.META_GRAPH_VERSION || "v21.0";
const G = () => `https://graph.facebook.com/${V()}`;

const SCOPES = ["ads_read", "leads_retrieval", "business_management", "pages_show_list", "pages_read_engagement", "pages_manage_ads"];

/** Follows Graph `paging.next` until exhausted. */
async function all<T = any>(url: string, max = 50): Promise<T[]> {
  const out: T[] = [];
  let next: string | undefined = url;
  for (let i = 0; next && i < max; i++) {
    const page: { data?: T[]; paging?: { next?: string } } = await fetchJson(next);
    out.push(...(page.data ?? []));
    next = page.paging?.next;
  }
  return out;
}

function q(path: string, token: string, params: Record<string, string | number> = {}): string {
  const u = new URL(`${G()}/${path}`);
  u.searchParams.set("access_token", token);
  for (const [k, v] of Object.entries(params)) u.searchParams.set(k, String(v));
  return u.toString();
}

const act = (id: string) => (id.startsWith("act_") ? id : `act_${id}`);

const LEVEL_PARAM: Record<StatLevel, string> = { ACCOUNT: "account", CAMPAIGN: "campaign", ADSET: "adset", AD: "ad" };
const LEVEL_ID: Record<StatLevel, string> = { ACCOUNT: "account_id", CAMPAIGN: "campaign_id", ADSET: "adset_id", AD: "ad_id" };
const INSIGHT_FIELDS = "account_id,campaign_id,adset_id,ad_id,spend,impressions,reach,clicks,inline_link_clicks,actions,action_values";

export const meta: Connector & { preview(token: string, adId: string, format?: string): Promise<string> } = {
  platform: "META",
  configured: () => !!(process.env.META_APP_ID && process.env.META_APP_SECRET),

  authUrl(state, redirectUri) {
    const u = new URL(`https://www.facebook.com/${V()}/dialog/oauth`);
    u.searchParams.set("client_id", process.env.META_APP_ID!);
    u.searchParams.set("redirect_uri", redirectUri);
    u.searchParams.set("state", state);
    u.searchParams.set("scope", SCOPES.join(","));
    return u.toString();
  },

  async exchange(code, redirectUri) {
    const id = process.env.META_APP_ID!, secret = process.env.META_APP_SECRET!;
    const short: any = await fetchJson(`${G()}/oauth/access_token?` + new URLSearchParams({ client_id: id, client_secret: secret, redirect_uri: redirectUri, code }));
    // Swap for a ~60-day token so syncs keep working without the user present.
    const long: any = await fetchJson(`${G()}/oauth/access_token?` + new URLSearchParams({ grant_type: "fb_exchange_token", client_id: id, client_secret: secret, fb_exchange_token: short.access_token }));
    const me: any = await fetchJson(q("me", long.access_token, { fields: "id,name" }));
    return {
      accessToken: long.access_token,
      expiresAt: long.expires_in ? new Date(Date.now() + long.expires_in * 1000) : undefined,
      scopes: SCOPES.join(","), externalUser: me.id, label: me.name,
    };
  },

  async listAccounts(token) {
    const rows = await all(q("me/adaccounts", token, { fields: "account_id,name,currency,timezone_name,account_status", limit: 200 }));
    return rows.map((r: any) => ({ externalId: r.account_id, name: r.name, currency: r.currency, timezone: r.timezone_name, status: String(r.account_status) }));
  },

  async fetchStructure(ctx: SyncContext) {
    const a = act(ctx.account.externalId), t = ctx.accessToken;
    const [c, s, ads] = await Promise.all([
      all(q(`${a}/campaigns`, t, { fields: "id,name,objective,effective_status,daily_budget,lifetime_budget,start_time,stop_time", limit: 500 })),
      all(q(`${a}/adsets`, t, { fields: "id,name,campaign_id,effective_status,optimization_goal,bid_strategy,daily_budget,targeting", limit: 500 })),
      all(q(`${a}/ads`, t, { fields: "id,name,adset_id,campaign_id,effective_status,creative{id,title,body,thumbnail_url,image_url,object_type,call_to_action_type,link_url,object_story_spec}", limit: 500 })),
    ]);
    const campaigns: RemoteCampaign[] = c.map((x: any) => ({
      externalId: x.id, name: x.name, objective: x.objective ?? "", status: x.effective_status ?? "",
      dailyBudget: minor(x.daily_budget), totalBudget: minor(x.lifetime_budget),
      startDate: x.start_time?.slice(0, 10), endDate: x.stop_time?.slice(0, 10), raw: x,
    }));
    const adSets: RemoteAdSet[] = s.map((x: any) => ({
      externalId: x.id, campaignExternalId: x.campaign_id, name: x.name, status: x.effective_status ?? "",
      optimizationGoal: x.optimization_goal, bidStrategy: x.bid_strategy, dailyBudget: minor(x.daily_budget),
      targetingRaw: x.targeting, targeting: flattenTargeting(x.targeting),
    }));
    const adList: RemoteAd[] = ads.map((x: any) => {
      const cr = x.creative ?? {};
      const link = cr.object_story_spec?.link_data ?? {};
      const video = cr.object_story_spec?.video_data ?? {};
      return {
        externalId: x.id, adSetExternalId: x.adset_id, campaignExternalId: x.campaign_id, name: x.name, status: x.effective_status ?? "",
        format: String(cr.object_type ?? "").toLowerCase(),
        headline: cr.title ?? link.name ?? video.title ?? "", body: cr.body ?? link.message ?? video.message ?? "",
        cta: cr.call_to_action_type ?? "", thumbUrl: cr.thumbnail_url ?? cr.image_url ?? "",
        landingUrl: cr.link_url ?? link.link ?? "",
      };
    });
    return { campaigns, adSets, ads: adList };
  },

  async fetchStats(ctx, from, to) {
    const a = act(ctx.account.externalId), t = ctx.accessToken;
    const range = JSON.stringify({ since: from, until: to });
    const stats: RemoteStat[] = [];
    for (const level of ["ACCOUNT", "CAMPAIGN", "ADSET", "AD"] as StatLevel[]) {
      const rows = await all(q(`${a}/insights`, t, { level: LEVEL_PARAM[level], time_increment: 1, time_range: range, fields: INSIGHT_FIELDS, limit: 500 }), 200);
      for (const r of rows) stats.push({ level, externalId: String(r[LEVEL_ID[level]]), date: r.date_start, ...insightMetrics(r) });
    }
    const breakdowns: RemoteBreakdown[] = [];
    for (const [dimension, param] of Object.entries(META_BREAKDOWNS)) {
      const rows = await all(q(`${a}/insights`, t, { level: "campaign", time_increment: 1, time_range: range, breakdowns: param, fields: "campaign_id,spend,impressions,reach,clicks,actions,action_values", limit: 500 }), 200);
      for (const r of rows) {
        const m = insightMetrics(r);
        breakdowns.push({ level: "CAMPAIGN", externalId: r.campaign_id, date: r.date_start, dimension, bucket: breakdownValue(dimension, r), ...m });
      }
    }
    return { stats, breakdowns };
  },

  async fetchLeads(ctx, from) {
    const t = ctx.accessToken;
    const ads = await all(q(`${act(ctx.account.externalId)}/ads`, t, { fields: "id,adset_id,campaign_id", limit: 500, effective_status: JSON.stringify(["ACTIVE", "PAUSED", "CAMPAIGN_PAUSED", "ADSET_PAUSED"]) }));
    const since = Math.floor(Date.parse(from + "T00:00:00Z") / 1000);
    const out: RemoteLead[] = [];
    for (const ad of ads) {
      let rows: any[] = [];
      try {
        rows = await all(q(`${ad.id}/leads`, t, { fields: "id,created_time,field_data,form_id", filtering: JSON.stringify([{ field: "time_created", operator: "GREATER_THAN", value: since }]), limit: 500 }), 20);
      } catch (e) {
        // Ads that are not lead ads, or a token without leads_retrieval, answer with an error; skip them.
        if (e instanceof PlatformError && e.status >= 400 && e.status < 500) continue;
        throw e;
      }
      for (const l of rows) {
        const fields: Record<string, string> = {};
        for (const f of l.field_data ?? []) fields[f.name] = (f.values ?? []).join(", ");
        out.push({ externalId: l.id, adExternalId: ad.id, adSetExternalId: ad.adset_id, campaignExternalId: ad.campaign_id, formName: l.form_id ?? "", createdAt: l.created_time, fields });
      }
    }
    return out;
  },

  async preview(token, adId, format = "MOBILE_FEED_STANDARD") {
    const r: any = await fetchJson(q(`${adId}/previews`, token, { ad_format: format }));
    return r.data?.[0]?.body ?? "";
  },
};
