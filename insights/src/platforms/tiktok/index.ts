import { addDays } from "@/lib/dates";
import { fetchJson } from "@/lib/http";
import type { Connector, Metrics, RemoteAd, RemoteAdSet, RemoteBreakdown, RemoteCampaign, RemoteStat, RemoteTargetingItem, StatLevel, SyncContext } from "../types";
import { PlatformError } from "../types";
import { setting } from "@/lib/settings";

/** TikTok Business API v1.3. campaign → Campaign, ad group → AdSet, ad → Ad. */
const API = "https://business-api.tiktok.com/open_api/v1.3";

/** TikTok answers HTTP 200 with {code, message, data}; non-zero code is an error. */
async function call(t: string, path: string, params: Record<string, unknown> = {}): Promise<any> {
  const u = new URL(`${API}${path}`);
  for (const [k, v] of Object.entries(params)) u.searchParams.set(k, typeof v === "string" ? v : JSON.stringify(v));
  const r: any = await fetchJson(u.toString(), { headers: { "Access-Token": t } });
  if (r.code !== 0) throw new PlatformError(`TikTok: ${r.message} (${r.code})`, 400, r.code === 40100 || r.code === 50000);
  return r.data;
}

async function pages(t: string, path: string, params: Record<string, unknown>): Promise<any[]> {
  const out: any[] = [];
  for (let page = 1; page <= 50; page++) {
    const d = await call(t, path, { ...params, page, page_size: 1000 });
    out.push(...(d.list ?? []));
    if (page >= (d.page_info?.total_page ?? 1)) break;
  }
  return out;
}

export function ttMetrics(m: Record<string, any>): Metrics {
  const conv = Number(m.conversion ?? 0);
  const leads = Number(m.form ?? m.real_time_form ?? 0);
  return {
    spend: Number(m.spend ?? 0), impressions: Number(m.impressions ?? 0), reach: Number(m.reach ?? 0), clicks: Number(m.clicks ?? 0),
    linkClicks: Number(m.clicks ?? 0), conversions: conv, leads: leads || 0, purchases: Number(m.complete_payment ?? 0),
    // "total_complete_payment_rate" is TikTok's name for total purchase value
    value: Number(m.total_complete_payment_rate ?? 0), videoViews: Number(m.video_play_actions ?? 0),
    engagements: Number(m.engagements ?? 0),
  };
}

const AGE: Record<string, string> = { AGE_13_17: "13-17", AGE_18_24: "18-24", AGE_25_34: "25-34", AGE_35_44: "35-44", AGE_45_54: "45-54", AGE_55_100: "55+" };
export const ttAge = (a: string) => AGE[a] ?? a;

export function ttTargeting(g: any): RemoteTargetingItem[] {
  const out: RemoteTargetingItem[] = [];
  for (const a of g.age_groups ?? []) out.push({ type: "age", value: ttAge(a) });
  out.push({ type: "gender", value: g.gender === "GENDER_MALE" ? "Male" : g.gender === "GENDER_FEMALE" ? "Female" : "All" });
  for (const l of g.location_ids ?? []) out.push({ type: "geo", value: String(l), externalId: String(l) });
  for (const l of g.languages ?? []) out.push({ type: "language", value: l });
  for (const i of g.interest_category_ids ?? []) out.push({ type: "interest", value: String(i), externalId: String(i) });
  for (const k of g.interest_keyword_ids ?? []) out.push({ type: "interest", value: `Keyword ${k}`, externalId: String(k) });
  for (const b of g.action_category_ids ?? []) out.push({ type: "behavior", value: String(b), externalId: String(b) });
  for (const a of g.audience_ids ?? []) out.push({ type: "audience", value: `Audience ${a}`, externalId: String(a) });
  for (const a of g.excluded_audience_ids ?? []) out.push({ type: "audience", value: `Audience ${a}`, externalId: String(a), exclude: true });
  for (const p of g.placements ?? []) out.push({ type: "placement", value: p });
  for (const o of g.operating_systems ?? []) out.push({ type: "device", value: o });
  return out;
}

const METRICS = ["spend", "impressions", "reach", "clicks", "conversion", "form", "complete_payment", "total_complete_payment_rate", "video_play_actions", "engagements"];
const LEVEL: [string, string, StatLevel][] = [["AUCTION_ADVERTISER", "advertiser_id", "ACCOUNT"], ["AUCTION_CAMPAIGN", "campaign_id", "CAMPAIGN"], ["AUCTION_ADGROUP", "adgroup_id", "ADSET"], ["AUCTION_AD", "ad_id", "AD"]];
const AUDIENCE: [string, string][] = [["age", "age"], ["gender", "gender"], ["country_code", "country"], ["platform", "platform"], ["interest_category", "interest_category"]];

export const tiktok: Connector = {
  platform: "TIKTOK",
  configured: () => !!(setting("TIKTOK_APP_ID") && setting("TIKTOK_APP_SECRET")),

  authUrl(state, redirectUri) {
    return "https://business-api.tiktok.com/portal/auth?" + new URLSearchParams({ app_id: setting("TIKTOK_APP_ID"), state, redirect_uri: redirectUri });
  },
  async exchange(code) {
    // TikTok sends ?auth_code=; the callback passes it through as `code`. Tokens do not expire.
    const r: any = await fetchJson(`${API}/oauth2/access_token/`, {
      method: "POST", headers: { "content-type": "application/json" },
      body: JSON.stringify({ app_id: setting("TIKTOK_APP_ID"), secret: setting("TIKTOK_APP_SECRET"), auth_code: code }),
    });
    if (r.code !== 0) throw new PlatformError(`TikTok: ${r.message}`, 400);
    return { accessToken: r.data.access_token, scopes: (r.data.scope ?? []).join(","), label: "TikTok login" };
  },

  async listAccounts(t) {
    const d = await call(t, "/oauth2/advertiser/get/", { app_id: setting("TIKTOK_APP_ID"), secret: setting("TIKTOK_APP_SECRET") });
    const ids = (d.list ?? []).map((x: any) => String(x.advertiser_id));
    if (!ids.length) return [];
    const info = await call(t, "/advertiser/info/", { advertiser_ids: ids, fields: ["advertiser_id", "name", "currency", "timezone", "status"] });
    return (info.list ?? []).map((a: any) => ({ externalId: String(a.advertiser_id), name: a.name, currency: a.currency, timezone: a.timezone, status: a.status }));
  },

  async fetchStructure(ctx: SyncContext) {
    const t = ctx.accessToken, adv = ctx.account.externalId;
    const [camps, groups, ads] = await Promise.all([
      pages(t, "/campaign/get/", { advertiser_id: adv }),
      pages(t, "/adgroup/get/", { advertiser_id: adv }),
      pages(t, "/ad/get/", { advertiser_id: adv }),
    ]);
    const campaigns: RemoteCampaign[] = camps.map((c) => ({
      externalId: String(c.campaign_id), name: c.campaign_name, objective: c.objective_type ?? "", status: c.operation_status ?? "",
      dailyBudget: c.budget_mode === "BUDGET_MODE_DAY" ? Number(c.budget) : undefined, totalBudget: c.budget_mode === "BUDGET_MODE_TOTAL" ? Number(c.budget) : undefined,
    }));
    const names = await interestNames(t, adv);
    const adSets: RemoteAdSet[] = groups.map((g) => ({
      externalId: String(g.adgroup_id), campaignExternalId: String(g.campaign_id), name: g.adgroup_name, status: g.operation_status ?? "",
      optimizationGoal: g.optimization_goal ?? "", bidStrategy: g.bid_type ?? "", dailyBudget: g.budget_mode === "BUDGET_MODE_DAY" ? Number(g.budget) : undefined,
      targetingRaw: g, targeting: ttTargeting(g).map((x) => (x.type === "interest" && names.has(x.value) ? { ...x, value: names.get(x.value)! } : x)),
    }));
    const adList: RemoteAd[] = ads.map((a) => ({
      externalId: String(a.ad_id), adSetExternalId: String(a.adgroup_id), campaignExternalId: String(a.campaign_id), name: a.ad_name, status: a.operation_status ?? "",
      format: String(a.ad_format ?? "in_feed_video").toLowerCase(), headline: a.ad_name ?? "", body: a.ad_text ?? "", cta: a.call_to_action ?? "",
      landingUrl: a.landing_page_url ?? "", thumbUrl: a.image_urls?.[0] ?? "",
    }));
    return { campaigns, adSets, ads: adList };
  },

  async fetchStats(ctx, from, to) {
    const t = ctx.accessToken, adv = ctx.account.externalId;
    const stats: RemoteStat[] = [];
    const breakdowns: RemoteBreakdown[] = [];
    // Daily reports are limited to 30 days per request.
    for (let start = from; start <= to; start = addDays(start, 30)) {
      const end = addDays(start, 29) < to ? addDays(start, 29) : to;
      for (const [dataLevel, idDim, level] of LEVEL) {
        const rows = await pages(t, "/report/integrated/get/", {
          advertiser_id: adv, report_type: "BASIC", data_level: dataLevel, dimensions: level === "ACCOUNT" ? ["stat_time_day"] : [idDim, "stat_time_day"],
          metrics: METRICS, start_date: start, end_date: end,
        });
        for (const r of rows) stats.push({ level, externalId: level === "ACCOUNT" ? adv : String(r.dimensions[idDim]), date: String(r.dimensions.stat_time_day).slice(0, 10), ...ttMetrics(r.metrics) });
      }
      for (const [dim, dimension] of AUDIENCE) {
        let rows: any[] = [];
        try {
          rows = await pages(t, "/report/integrated/get/", {
            advertiser_id: adv, report_type: "AUDIENCE", data_level: "AUCTION_CAMPAIGN", dimensions: ["campaign_id", "stat_time_day", dim],
            metrics: ["spend", "impressions", "clicks", "conversion"], start_date: start, end_date: end,
          });
        } catch { continue; } // not every dimension is available on every account
        for (const r of rows) {
          const raw = String(r.dimensions[dim] ?? "unknown");
          breakdowns.push({
            level: "CAMPAIGN", externalId: String(r.dimensions.campaign_id), date: String(r.dimensions.stat_time_day).slice(0, 10), dimension,
            bucket: dim === "age" ? ttAge(raw) : raw.replace(/^GENDER_/, "").toLowerCase(), ...ttMetrics(r.metrics),
          });
        }
      }
    }
    // Per ad group: results for each interest category TikTok delivered to — reported, not estimated.
    const names = await interestNames(t, adv);
    for (let start = from; start <= to; start = addDays(start, 30)) {
      const end = addDays(start, 29) < to ? addDays(start, 29) : to;
      let rows: any[] = [];
      try {
        rows = await pages(t, "/report/integrated/get/", {
          advertiser_id: adv, report_type: "AUDIENCE", data_level: "AUCTION_ADGROUP", dimensions: ["adgroup_id", "stat_time_day", "interest_category"],
          metrics: ["spend", "impressions", "clicks", "conversion"], start_date: start, end_date: end,
        });
      } catch { continue; }
      for (const r of rows) {
        const id = String(r.dimensions.interest_category ?? "");
        if (!id || id === "-") continue;
        breakdowns.push({ level: "ADSET", externalId: String(r.dimensions.adgroup_id), date: String(r.dimensions.stat_time_day).slice(0, 10), dimension: "seg_interest", bucket: names.get(id) ?? id, ...ttMetrics(r.metrics) });
      }
    }
    return { stats, breakdowns };
  },
};

/** Interest category ids → names, so reported rows line up with ad group targeting. */
async function interestNames(t: string, adv: string): Promise<Map<string, string>> {
  const out = new Map<string, string>();
  try {
    const d = await call(t, "/tool/interest_category/", { advertiser_id: adv, version: 2 });
    const walk = (xs: any[]) => xs?.forEach((x) => { out.set(String(x.interest_category_id), x.interest_category_name); walk(x.children ?? []); });
    walk(d.interest_categories ?? d.list ?? []);
  } catch { /* names are cosmetic */ }
  return out;
}
