import { fetchJson } from "@/lib/http";
import type { Connector, RemoteAd, RemoteAdSet, RemoteBreakdown, RemoteCampaign, RemoteLead, RemoteStat, StatLevel, SyncContext } from "../types";
import { PlatformError } from "../types";
import { META_BREAKDOWNS, breakdownValue, flattenTargeting, insightMetrics, minor } from "./normalize";
import { setting } from "@/lib/settings";

const V = () => setting("META_GRAPH_VERSION") || "v21.0";
const G = () => `https://graph.facebook.com/${V()}`;

const SCOPES = ["ads_read", "leads_retrieval", "business_management", "pages_show_list", "pages_read_engagement", "pages_manage_ads"];
/** Only requested when the client opts in to interest split tests, which create (paused) ad sets. */
export const WRITE_SCOPE = "ads_management";

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

/** Runs one step of the login and names it in the error, so a failure says where it happened. */
async function step<T>(what: string, fn: () => Promise<T>): Promise<T> {
  try { return await fn(); } catch (e) { throw new PlatformError(`Meta, while ${what}: ${(e as Error).message}`, (e as PlatformError).status ?? 0); }
}

/** The permissions the person actually granted (they can untick some in the dialog). */
async function grantedScopes(token: string): Promise<string> {
  try {
    const r: any = await fetchJson(q("me/permissions", token));
    return (r.data ?? []).filter((p: any) => p.status === "granted").map((p: any) => p.permission).join(",");
  } catch {
    return SCOPES.join(",");
  }
}

const act = (id: string) => (id.startsWith("act_") ? id : `act_${id}`);

const LEVEL_PARAM: Record<StatLevel, string> = { ACCOUNT: "account", CAMPAIGN: "campaign", ADSET: "adset", AD: "ad" };
const LEVEL_ID: Record<StatLevel, string> = { ACCOUNT: "account_id", CAMPAIGN: "campaign_id", ADSET: "adset_id", AD: "ad_id" };
const INSIGHT_FIELDS = "account_id,campaign_id,adset_id,ad_id,spend,impressions,reach,clicks,inline_link_clicks,actions,action_values";

export const meta: Connector & { preview(token: string, adId: string, format?: string): Promise<string> } = {
  platform: "META",
  configured: () => !!(setting("META_APP_ID") && setting("META_APP_SECRET")),

  authUrl(state, redirectUri, opts) {
    const u = new URL(`https://www.facebook.com/${V()}/dialog/oauth`);
    u.searchParams.set("client_id", setting("META_APP_ID"));
    u.searchParams.set("redirect_uri", redirectUri);
    u.searchParams.set("state", state);
    // Business-type apps use Facebook Login for Business: permissions come from a saved
    // configuration (config_id) rather than a scope list. Split tests can use their own configuration.
    const config = (opts?.write && setting("META_LOGIN_CONFIG_ID_WRITE")) || setting("META_LOGIN_CONFIG_ID");
    if (config) {
      u.searchParams.set("config_id", config);
      u.searchParams.set("response_type", "code");
      u.searchParams.set("override_default_response_type", "true");
    } else {
      u.searchParams.set("scope", [...SCOPES, ...(opts?.write ? [WRITE_SCOPE] : [])].join(","));
    }
    return u.toString();
  },

  async exchange(code, redirectUri) {
    const id = setting("META_APP_ID"), secret = setting("META_APP_SECRET");
    const short: any = await step("exchanging the login code", () => fetchJson(`${G()}/oauth/access_token?` + new URLSearchParams({ client_id: id, client_secret: secret, redirect_uri: redirectUri, code })));
    // Swap for a ~60-day token so syncs keep working without the user present. Facebook Login for
    // Business tokens can't always be swapped — they are long-lived already — so keep the original then.
    let token: string = short.access_token, expiresIn: number | undefined = short.expires_in;
    try {
      const long: any = await fetchJson(`${G()}/oauth/access_token?` + new URLSearchParams({ grant_type: "fb_exchange_token", client_id: id, client_secret: secret, fb_exchange_token: token }));
      if (long.access_token) { token = long.access_token; expiresIn = long.expires_in; }
    } catch { /* keep the short-lived token */ }
    let me: { id?: string; name?: string } = {};
    try { me = await fetchJson(q("me", token, { fields: "id,name" })); } catch { /* business tokens may not read /me fields */ }
    return {
      accessToken: token,
      expiresAt: expiresIn ? new Date(Date.now() + expiresIn * 1000) : undefined,
      scopes: await grantedScopes(token), externalUser: me.id ?? "", label: me.name ?? "Meta login",
    };
  },

  /**
   * Every ad account the login can reach: the person's own list plus, for
   * each Business Manager they can see, its owned and client (partner-shared)
   * ad accounts — Facebook Login for Business tokens often only see the latter.
   */
  async listAccounts(token) {
    const fields = "account_id,name,currency,timezone_name,account_status";
    const found = new Map<string, any>();
    const errors: string[] = [];
    const take = async (label: string, path: string) => {
      try { for (const r of await all(q(path, token, { fields, limit: 200 }))) found.set(String(r.account_id), r); }
      catch (e) { errors.push(`${label}: ${(e as Error).message}`); }
    };
    await take("your ad accounts", "me/adaccounts");
    let businesses: { id: string }[] = [];
    try { businesses = await all(q("me/businesses", token, { fields: "id,name", limit: 100 })); } catch (e) { errors.push(`businesses: ${(e as Error).message}`); }
    for (const biz of businesses) {
      await take("business ad accounts", `${biz.id}/owned_ad_accounts`);
      await take("partner ad accounts", `${biz.id}/client_ad_accounts`);
    }
    if (!found.size && errors.length) throw new PlatformError(`Meta didn't return any ad accounts (${errors[0]}). Check that the login configuration includes Ad accounts and the ads_read and business_management permissions.`, 400);
    return [...found.values()].map((r: any) => ({ externalId: String(r.account_id), name: r.name, currency: r.currency, timezone: r.timezone_name, status: String(r.account_status) }));
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
      let rows: any[];
      try {
        rows = await all(q(`${a}/insights`, t, { level: "campaign", time_increment: 1, time_range: range, breakdowns: param, fields: "campaign_id,spend,impressions,reach,clicks,actions,action_values", limit: 500 }), 200);
      } catch (e) {
        // A breakdown this account or its campaigns don't support (e.g. asset splits without dynamic creative) is skipped, not fatal.
        if (e instanceof PlatformError && e.status >= 400 && e.status < 500) continue;
        throw e;
      }
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
