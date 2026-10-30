import { fetchJson } from "@/lib/http";
import type { Connector, RemoteAd, RemoteAdSet, RemoteBreakdown, RemoteCampaign, RemoteKeyword, RemotePlacement, RemoteSearchTerm, RemoteStat, RemoteTargetingItem, SyncContext, TokenSet } from "../types";
import { adCopy, ageLabel, criterionItem, genderLabel, gMetrics, micros } from "./normalize";

const V = () => process.env.GOOGLE_ADS_API_VERSION || "v17";
const API = () => `https://googleads.googleapis.com/${V()}`;
const SCOPE = "https://www.googleapis.com/auth/adwords";

function headers(token: string, loginCustomerId?: string): Record<string, string> {
  const h: Record<string, string> = {
    Authorization: `Bearer ${token}`,
    "developer-token": process.env.GOOGLE_ADS_DEVELOPER_TOKEN ?? "",
    "content-type": "application/json",
  };
  const login = loginCustomerId || process.env.GOOGLE_ADS_LOGIN_CUSTOMER_ID;
  if (login) h["login-customer-id"] = login.replace(/-/g, "");
  return h;
}

/** Runs one GAQL query through searchStream and returns every result row. */
async function gaql(ctx: { token: string; customerId: string; login?: string }, query: string): Promise<any[]> {
  const batches: any[] = await fetchJson(`${API()}/customers/${ctx.customerId}/googleAds:searchStream`, {
    method: "POST", headers: headers(ctx.token, ctx.login), body: JSON.stringify({ query }),
  });
  return (batches ?? []).flatMap((b) => b.results ?? []);
}

async function token(params: Record<string, string>): Promise<TokenSet> {
  const r: any = await fetchJson("https://oauth2.googleapis.com/token", {
    method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ client_id: process.env.GOOGLE_ADS_CLIENT_ID!, client_secret: process.env.GOOGLE_ADS_CLIENT_SECRET!, ...params }),
  });
  return { accessToken: r.access_token, refreshToken: r.refresh_token, expiresAt: new Date(Date.now() + (r.expires_in ?? 3600) * 1000), scopes: r.scope };
}

const between = (from: string, to: string) => `segments.date BETWEEN '${from}' AND '${to}'`;
const M = "metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, metrics.video_views, metrics.interactions";

export const google: Connector = {
  platform: "GOOGLE",
  configured: () => !!(process.env.GOOGLE_ADS_CLIENT_ID && process.env.GOOGLE_ADS_CLIENT_SECRET && process.env.GOOGLE_ADS_DEVELOPER_TOKEN),

  authUrl(state, redirectUri) {
    const u = new URL("https://accounts.google.com/o/oauth2/v2/auth");
    u.search = new URLSearchParams({
      client_id: process.env.GOOGLE_ADS_CLIENT_ID!, redirect_uri: redirectUri, response_type: "code", scope: SCOPE,
      access_type: "offline", prompt: "consent", include_granted_scopes: "true", state,
    }).toString();
    return u.toString();
  },

  async exchange(code, redirectUri) {
    const t = await token({ code, redirect_uri: redirectUri, grant_type: "authorization_code" });
    return { ...t, label: "Google Ads login" };
  },

  refresh: (refreshToken) => token({ refresh_token: refreshToken, grant_type: "refresh_token" }),

  /**
   * Every non-manager account the login can reach, directly or through a
   * manager (MCC). The manager id is kept as parentId: API calls for a child
   * account must send it as login-customer-id.
   */
  async listAccounts(accessToken) {
    const r: any = await fetchJson(`${API()}/customers:listAccessibleCustomers`, { headers: headers(accessToken, "") });
    const roots: string[] = (r.resourceNames ?? []).map((n: string) => n.split("/")[1]);
    const seen = new Map<string, { externalId: string; name: string; currency: string; timezone: string; parentId?: string }>();
    for (const root of roots) {
      let rows: any[] = [];
      try {
        rows = await gaql({ token: accessToken, customerId: root, login: root },
          "SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.time_zone, customer_client.manager, customer_client.status FROM customer_client WHERE customer_client.status = 'ENABLED'");
      } catch { continue; } // accounts the login can list but not query (e.g. cancelled)
      for (const x of rows) {
        const c = x.customerClient;
        if (c.manager || seen.has(String(c.id))) continue;
        seen.set(String(c.id), { externalId: String(c.id), name: c.descriptiveName || String(c.id), currency: c.currencyCode, timezone: c.timeZone, parentId: String(c.id) === root ? "" : root });
      }
    }
    return [...seen.values()];
  },

  async fetchStructure(ctx: SyncContext) {
    const q = { token: ctx.accessToken, customerId: ctx.account.externalId, login: ctx.account.parentId };
    const [camps, groups, crit, campCrit, ads] = await Promise.all([
      gaql(q, "SELECT campaign.id, campaign.name, campaign.advertising_channel_type, campaign.status, campaign.start_date, campaign.end_date, campaign_budget.amount_micros FROM campaign WHERE campaign.status != 'REMOVED'"),
      gaql(q, "SELECT ad_group.id, ad_group.name, ad_group.status, ad_group.type, campaign.id, campaign.bidding_strategy_type, ad_group.cpc_bid_micros FROM ad_group WHERE ad_group.status != 'REMOVED'"),
      gaql(q, "SELECT ad_group.id, ad_group_criterion.criterion_id, ad_group_criterion.type, ad_group_criterion.display_name, ad_group_criterion.negative, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.age_range.type, ad_group_criterion.gender.type, ad_group_criterion.placement.url FROM ad_group_criterion WHERE ad_group_criterion.status != 'REMOVED'"),
      gaql(q, "SELECT campaign.id, campaign_criterion.criterion_id, campaign_criterion.type, campaign_criterion.display_name, campaign_criterion.negative FROM campaign_criterion WHERE campaign_criterion.type IN ('LOCATION', 'LANGUAGE', 'PROXIMITY', 'USER_LIST', 'KEYWORD')"),
      gaql(q, "SELECT ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.ad.type, ad_group_ad.status, ad_group_ad.ad.final_urls, ad_group_ad.ad.responsive_search_ad.headlines, ad_group_ad.ad.responsive_search_ad.descriptions, ad_group_ad.ad.responsive_display_ad.headlines, ad_group_ad.ad.responsive_display_ad.descriptions, ad_group_ad.ad.expanded_text_ad.headline_part1, ad_group_ad.ad.expanded_text_ad.headline_part2, ad_group_ad.ad.expanded_text_ad.description, ad_group_ad.ad.video_responsive_ad.headlines, ad_group_ad.ad.video_responsive_ad.descriptions, ad_group.id, campaign.id FROM ad_group_ad WHERE ad_group_ad.status != 'REMOVED'"),
    ]);

    const campaignItems = new Map<string, RemoteTargetingItem[]>();
    for (const r of campCrit) {
      const it = criterionItem(r.campaignCriterion);
      if (it) campaignItems.set(String(r.campaign.id), [...(campaignItems.get(String(r.campaign.id)) ?? []), it]);
    }
    const groupItems = new Map<string, RemoteTargetingItem[]>();
    for (const r of crit) {
      const it = criterionItem(r.adGroupCriterion);
      if (it) groupItems.set(String(r.adGroup.id), [...(groupItems.get(String(r.adGroup.id)) ?? []), it]);
    }

    const campaigns: RemoteCampaign[] = camps.map((r) => ({
      externalId: String(r.campaign.id), name: r.campaign.name, objective: r.campaign.advertisingChannelType ?? "", status: r.campaign.status ?? "",
      dailyBudget: r.campaignBudget?.amountMicros ? micros(r.campaignBudget.amountMicros) : undefined,
      startDate: r.campaign.startDate, endDate: r.campaign.endDate === "2037-12-30" ? undefined : r.campaign.endDate,
    }));
    const adSets: RemoteAdSet[] = groups.map((r) => {
      const items = [...(groupItems.get(String(r.adGroup.id)) ?? []), ...(campaignItems.get(String(r.campaign.id)) ?? [])];
      return {
        externalId: String(r.adGroup.id), campaignExternalId: String(r.campaign.id), name: r.adGroup.name, status: r.adGroup.status ?? "",
        optimizationGoal: r.adGroup.type ?? "", bidStrategy: r.campaign.biddingStrategyType ?? "", targetingRaw: { items }, targeting: items,
      };
    });
    const adList: RemoteAd[] = ads.map((r) => {
      const ad = r.adGroupAd.ad;
      const copy = adCopy(ad);
      return {
        externalId: String(ad.id), adSetExternalId: String(r.adGroup.id), campaignExternalId: String(r.campaign.id),
        name: ad.name || `${copy.format} ${ad.id}`, status: r.adGroupAd.status ?? "", ...copy, landingUrl: ad.finalUrls?.[0] ?? "",
      };
    });
    return { campaigns, adSets, ads: adList };
  },

  async fetchStats(ctx, from, to) {
    const q = { token: ctx.accessToken, customerId: ctx.account.externalId, login: ctx.account.parentId };
    const w = between(from, to);
    const [acc, camp, grp, ad, age, gender, geo, device, network, hour, kw, terms, places] = await Promise.all([
      gaql(q, `SELECT segments.date, ${M} FROM customer WHERE ${w}`),
      gaql(q, `SELECT campaign.id, segments.date, ${M} FROM campaign WHERE ${w}`),
      gaql(q, `SELECT ad_group.id, segments.date, ${M} FROM ad_group WHERE ${w}`),
      gaql(q, `SELECT ad_group_ad.ad.id, segments.date, ${M} FROM ad_group_ad WHERE ${w}`),
      gaql(q, `SELECT campaign.id, ad_group_criterion.age_range.type, segments.date, ${M} FROM age_range_view WHERE ${w}`),
      gaql(q, `SELECT campaign.id, ad_group_criterion.gender.type, segments.date, ${M} FROM gender_view WHERE ${w}`),
      gaql(q, `SELECT campaign.id, geographic_view.country_criterion_id, segments.date, ${M} FROM geographic_view WHERE ${w}`),
      gaql(q, `SELECT campaign.id, segments.device, segments.date, ${M} FROM campaign WHERE ${w}`),
      gaql(q, `SELECT campaign.id, segments.ad_network_type, segments.date, ${M} FROM campaign WHERE ${w}`),
      gaql(q, `SELECT campaign.id, segments.hour, segments.date, ${M} FROM campaign WHERE ${w}`),
      gaql(q, `SELECT campaign.id, ad_group.id, ad_group_criterion.criterion_id, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, ad_group_criterion.status, ad_group_criterion.quality_info.quality_score, segments.date, ${M} FROM keyword_view WHERE ${w}`),
      gaql(q, `SELECT campaign.id, ad_group.id, search_term_view.search_term, segments.keyword.info.text, segments.keyword.info.match_type, segments.date, ${M} FROM search_term_view WHERE ${w}`),
      gaql(q, `SELECT campaign.id, detail_placement_view.placement_type, detail_placement_view.target_url, detail_placement_view.display_name, segments.date, ${M} FROM detail_placement_view WHERE ${w}`),
    ]);

    // Country criterion ids → ISO codes for the geo breakdown.
    const geoIds = [...new Set(geo.map((r) => String(r.geographicView.countryCriterionId)))].filter(Boolean);
    const countries = new Map<string, string>();
    if (geoIds.length) {
      const rows = await gaql(q, `SELECT geo_target_constant.id, geo_target_constant.country_code FROM geo_target_constant WHERE geo_target_constant.id IN (${geoIds.join(",")})`);
      for (const r of rows) countries.set(String(r.geoTargetConstant.id), r.geoTargetConstant.countryCode);
    }

    const stats: RemoteStat[] = [
      ...acc.map((r) => ({ level: "ACCOUNT" as const, externalId: ctx.account.externalId, date: r.segments.date, ...gMetrics(r.metrics) })),
      ...camp.map((r) => ({ level: "CAMPAIGN" as const, externalId: String(r.campaign.id), date: r.segments.date, ...gMetrics(r.metrics) })),
      ...grp.map((r) => ({ level: "ADSET" as const, externalId: String(r.adGroup.id), date: r.segments.date, ...gMetrics(r.metrics) })),
      ...ad.map((r) => ({ level: "AD" as const, externalId: String(r.adGroupAd.ad.id), date: r.segments.date, ...gMetrics(r.metrics) })),
    ];

    // Segmented rows can repeat a (campaign, day, bucket) — e.g. age rows come per ad group — so sum them.
    const acc2 = new Map<string, RemoteBreakdown>();
    const push = (dimension: string, rows: any[], bucket: (r: any) => string) => {
      for (const r of rows) {
        const b: RemoteBreakdown = { level: "CAMPAIGN", externalId: String(r.campaign.id), date: r.segments.date, dimension, bucket: bucket(r), ...gMetrics(r.metrics) };
        const k = `${b.externalId}|${b.date}|${dimension}|${b.bucket}`;
        const prev = acc2.get(k);
        if (!prev) { acc2.set(k, b); continue; }
        for (const f of ["spend", "impressions", "clicks", "linkClicks", "conversions", "leads", "value", "videoViews", "engagements"] as const) prev[f] = (prev[f] ?? 0) + (b[f] ?? 0);
      }
    };
    push("age", age, (r) => ageLabel(r.adGroupCriterion.ageRange.type));
    push("gender", gender, (r) => genderLabel(r.adGroupCriterion.gender.type));
    push("country", geo, (r) => countries.get(String(r.geographicView.countryCriterionId)) ?? String(r.geographicView.countryCriterionId));
    push("device", device, (r) => r.segments.device);
    push("network", network, (r) => r.segments.adNetworkType);
    push("hour", hour, (r) => String(r.segments.hour).padStart(2, "0"));

    const keywords: RemoteKeyword[] = kw.map((r) => ({
      externalId: `${r.adGroup.id}~${r.adGroupCriterion.criterionId}`, campaignExternalId: String(r.campaign.id), adSetExternalId: String(r.adGroup.id),
      text: r.adGroupCriterion.keyword.text, matchType: r.adGroupCriterion.keyword.matchType, status: r.adGroupCriterion.status,
      qualityScore: r.adGroupCriterion.qualityInfo?.qualityScore ?? null, date: r.segments.date, ...gMetrics(r.metrics),
    }));
    const searchTerms: RemoteSearchTerm[] = terms.map((r) => ({
      campaignExternalId: String(r.campaign.id), adSetExternalId: String(r.adGroup.id), term: r.searchTermView.searchTerm,
      keywordText: r.segments.keyword?.info?.text ?? "", matchType: r.segments.keyword?.info?.matchType ?? "", date: r.segments.date, ...gMetrics(r.metrics),
    }));
    const placements: RemotePlacement[] = places.map((r) => ({
      campaignExternalId: String(r.campaign.id), kind: String(r.detailPlacementView.placementType ?? "").toLowerCase(),
      target: r.detailPlacementView.targetUrl ?? "", display: r.detailPlacementView.displayName ?? "", date: r.segments.date, ...gMetrics(r.metrics),
    }));
    return { stats, breakdowns: [...acc2.values()], keywords, searchTerms, placements };
  },
};
