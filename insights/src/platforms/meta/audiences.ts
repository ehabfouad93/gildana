import type { RemoteTargetingItem } from "../types";
import { act, metaGet, metaPost, toMinor } from "./write";

/**
 * Audiences on Meta: building an ad set's targeting from mixed groups,
 * estimating its reach, creating ready-made custom and lookalike audiences,
 * and launching ad sets for them. The builders are pure and unit-tested; the
 * calls run only from user-pressed actions.
 */

export type MixItem = RemoteTargetingItem; // type: interest | behavior | audience

const FACET: Record<string, string> = { interest: "interests", behavior: "behaviors", audience: "custom_audiences", lookalike: "custom_audiences" };
const DETAILED = ["interests", "behaviors", "flexible_spec", "exclusions", "custom_audiences", "excluded_custom_audiences", "targeting_optimization"];

const ref = (i: MixItem): { id: string; name?: string } => (FACET[i.type] === "custom_audiences" ? { id: i.externalId ?? "" } : { id: i.externalId ?? "", name: i.value });

/**
 * The source ad set's targeting (location, age, placements…) with its
 * audience replaced by the mix: any item inside a group, every group
 * required (OR inside, AND between), minus the exclusions. Advantage+
 * audience is switched off so delivery stays inside the mix.
 */
export function mixTargeting(base: Record<string, any> | null | undefined, groups: MixItem[][], exclusions: MixItem[] = []): Record<string, any> {
  const t = JSON.parse(JSON.stringify(base ?? {}));
  for (const k of DETAILED) delete t[k];
  const spec = groups.filter((g) => g.length).map((g) => {
    const o: Record<string, { id: string; name?: string }[]> = {};
    for (const i of g) (o[FACET[i.type] ?? "interests"] ??= []).push(ref(i));
    return o;
  });
  if (spec.length) t.flexible_spec = spec;
  const ex: Record<string, { id: string; name?: string }[]> = {};
  for (const i of exclusions) {
    if (FACET[i.type] === "custom_audiences") (t.excluded_custom_audiences ??= []).push({ id: i.externalId ?? "" });
    else (ex[FACET[i.type] ?? "interests"] ??= []).push(ref(i));
  }
  if (Object.keys(ex).length) t.exclusions = ex;
  t.targeting_automation = { ...(t.targeting_automation ?? {}), advantage_audience: 0 };
  return t;
}

/** A readable name for a mix: "A or B + C, excl. D". */
export function mixName(groups: MixItem[][], exclusions: MixItem[] = []): string {
  const g = groups.filter((x) => x.length).map((x) => x.map((i) => i.value).join(" or ")).join(" + ");
  return `${g || "Broad"}${exclusions.length ? ` (excl. ${exclusions.map((i) => i.value).join(", ")})` : ""}`.slice(0, 200);
}

/** Meta's estimate of how many people a targeting can reach (monthly), as a range. */
export async function reachEstimate(token: string, accountExternalId: string, targeting: Record<string, any>, optimizationGoal = "REACH"): Promise<{ low: number; high: number } | null> {
  const r: { data?: { estimate_mau_lower_bound?: number; estimate_mau_upper_bound?: number; estimate_mau?: number; estimate_ready?: boolean }[] } =
    await metaGet(`${act(accountExternalId)}/delivery_estimate`, token, { optimization_goal: optimizationGoal, targeting_spec: JSON.stringify(targeting) });
  const d = r.data?.[0];
  if (!d) return null;
  const low = Number(d.estimate_mau_lower_bound ?? d.estimate_mau ?? 0), high = Number(d.estimate_mau_upper_bound ?? d.estimate_mau ?? 0);
  return low || high ? { low, high } : null;
}

export async function searchTargeting(token: string, accountExternalId: string, q: string): Promise<(MixItem & { size?: string; path?: string })[]> {
  const r: { data?: { id: string; name: string; type: string; audience_size_lower_bound?: number; audience_size_upper_bound?: number; path?: string[] }[] } =
    await metaGet(`${act(accountExternalId)}/targetingsearch`, token, { q, limit: 25 });
  return (r.data ?? []).filter((x) => x.type === "interests" || x.type === "behaviors").map((x) => ({
    type: x.type === "behaviors" ? "behavior" : "interest", value: x.name, externalId: String(x.id),
    size: x.audience_size_upper_bound ? `${compact(x.audience_size_lower_bound ?? 0)}–${compact(x.audience_size_upper_bound)}` : undefined, path: (x.path ?? []).slice(0, -1).join(" › "),
  }));
}

const compact = (n: number) => new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(n);

export type RemoteAudience = { externalId: string; name: string; kind: string; sizeLow: number | null; sizeHigh: number | null };

const KIND: Record<string, string> = { WEBSITE: "website", ENGAGEMENT: "page", VIDEO: "video", LOOKALIKE: "lookalike", CUSTOM: "customer_list", LEAD_AD: "lead_form", IG_BUSINESS: "instagram" };

export async function listAudiences(token: string, accountExternalId: string): Promise<RemoteAudience[]> {
  const r: { data?: { id: string; name: string; subtype?: string; approximate_count_lower_bound?: number; approximate_count_upper_bound?: number }[] } =
    await metaGet(`${act(accountExternalId)}/customaudiences`, token, { fields: "id,name,subtype,approximate_count_lower_bound,approximate_count_upper_bound", limit: 300 });
  return (r.data ?? []).map((x) => ({ externalId: String(x.id), name: x.name, kind: KIND[x.subtype ?? ""] ?? "other", sizeLow: x.approximate_count_lower_bound ?? null, sizeHigh: x.approximate_count_upper_bound ?? null }));
}

/** What ready-made audiences can be built from: the account's pixels, pages, Instagram accounts and videos. */
export async function listSources(token: string, accountExternalId: string) {
  const a = act(accountExternalId);
  const safe = async <T>(p: Promise<{ data?: T[] }>) => { try { return (await p).data ?? []; } catch { return [] as T[]; } };
  const [pixels, pages, videos] = await Promise.all([
    safe<{ id: string; name: string }>(metaGet(`${a}/adspixels`, token, { fields: "id,name", limit: 50 })),
    safe<{ id: string; name: string; instagram_business_account?: { id: string; username?: string } }>(metaGet(`${a}/promote_pages`, token, { fields: "id,name,instagram_business_account{id,username}", limit: 50 })),
    safe<{ id: string; title?: string }>(metaGet(`${a}/advideos`, token, { fields: "id,title", limit: 50 })),
  ]);
  return {
    pixels: pixels.map((p) => ({ id: String(p.id), name: p.name })),
    pages: pages.map((p) => ({ id: String(p.id), name: p.name })),
    instagram: pages.flatMap((p) => (p.instagram_business_account ? [{ id: String(p.instagram_business_account.id), name: `@${p.instagram_business_account.username ?? p.name}` }] : [])),
    videos: videos.map((v) => String(v.id)),
  };
}

// ───────── ready-made audiences (the launcher) ─────────

export type PresetKind = "website" | "page" | "instagram" | "video";
export type Preset = { key: string; kind: PresetKind; stage: "reengagement" | "retargeting"; label: string; days: number; event?: string };

/** The audiences worth having in every account, by funnel stage. */
export const PRESETS: Preset[] = [
  { key: "web_7", kind: "website", stage: "retargeting", label: "Website visitors — last 7 days", days: 7 },
  { key: "web_30", kind: "website", stage: "retargeting", label: "Website visitors — last 30 days", days: 30 },
  { key: "web_180", kind: "website", stage: "retargeting", label: "Website visitors — last 180 days", days: 180 },
  { key: "page_90", kind: "page", stage: "reengagement", label: "Facebook page engagers — last 90 days", days: 90 },
  { key: "page_365", kind: "page", stage: "reengagement", label: "Facebook page engagers — last 365 days", days: 365 },
  { key: "ig_90", kind: "instagram", stage: "reengagement", label: "Instagram engagers — last 90 days", days: 90 },
  { key: "ig_365", kind: "instagram", stage: "reengagement", label: "Instagram engagers — last 365 days", days: 365 },
  { key: "video_50", kind: "video", stage: "reengagement", label: "Video viewers — watched 50%", days: 365, event: "video_view_50_percent" },
  { key: "video_75", kind: "video", stage: "reengagement", label: "Video viewers — watched 75%", days: 365, event: "video_view_75_percent" },
  { key: "video_95", kind: "video", stage: "reengagement", label: "Video viewers — watched 95%", days: 365, event: "video_completed" },
];

/** The request body for a ready-made audience. Pure; unit-tested. */
export function presetAudience(p: Preset, src: { pixelId?: string; pageId?: string; instagramId?: string; videoIds?: string[] }, name: string): Record<string, unknown> | null {
  const seconds = p.days * 86_400;
  const rule = (source: { id: string; type: string }, filter: Record<string, unknown>) => ({ inclusions: { operator: "or", rules: [{ event_sources: [source], retention_seconds: seconds, filter }] } });
  if (p.kind === "website" && src.pixelId) return { name, rule: rule({ id: src.pixelId, type: "pixel" }, { operator: "and", filters: [{ field: "url", operator: "i_contains", value: "" }] }), prefill: 1 };
  if (p.kind === "page" && src.pageId) return { name, rule: rule({ id: src.pageId, type: "page" }, { operator: "and", filters: [{ field: "event", operator: "eq", value: "page_engaged" }] }), prefill: 1 };
  if (p.kind === "instagram" && src.instagramId) return { name, rule: rule({ id: src.instagramId, type: "ig_business" }, { operator: "and", filters: [{ field: "event", operator: "eq", value: "ig_business_profile_all" }] }), prefill: 1 };
  if (p.kind === "video" && src.videoIds?.length) return { name, subtype: "ENGAGEMENT", retention_days: p.days, rule: src.videoIds.slice(0, 50).map((id) => ({ object_id: id, event_name: p.event })), prefill: 1 };
  return null;
}

/** The request body for a lookalike of an audience: `percent` of a country's people most like it. Pure; unit-tested. */
export function lookalikeAudience(originExternalId: string, originName: string, country: string, percent: number): Record<string, unknown> {
  const pct = Math.min(10, Math.max(1, Math.round(percent)));
  return { name: `Lookalike (${country.toUpperCase()}, ${pct}%) - ${originName}`.slice(0, 200), subtype: "LOOKALIKE", origin_audience_id: originExternalId, lookalike_spec: { type: "similarity", ratio: pct / 100, country: country.toUpperCase() } };
}

export const createAudience = async (token: string, accountExternalId: string, body: Record<string, unknown>): Promise<string> =>
  String((await metaPost(`${act(accountExternalId)}/customaudiences`, token, body)).id);

// ───────── launching ad sets for audiences ─────────

const COPY = ["campaign_id", "billing_event", "optimization_goal", "bid_strategy", "bid_amount", "promoted_object", "attribution_spec", "destination_type", "targeting", "name"];

export type LaunchOptions = { dailyBudget: number; currency: string; days: number; startNow: boolean; newCampaignName?: string };

/** The ad-set (and optional campaign) requests for launching audiences from a source ad set. Pure; unit-tested. */
export function launchPlan(src: Record<string, any>, specs: { name: string; targeting: Record<string, any> }[], o: LaunchOptions, now = new Date()) {
  const status = o.startNow ? "ACTIVE" : "PAUSED";
  const c = src.campaign ?? {};
  return {
    campaign: o.newCampaignName
      ? { name: o.newCampaignName.slice(0, 250), objective: c.objective, status, special_ad_categories: c.special_ad_categories ?? [], special_ad_category_country: c.special_ad_category_country, buying_type: c.buying_type ?? "AUCTION", is_adset_budget_sharing_enabled: false }
      : null,
    adSets: specs.map((s) => ({
      name: s.name.slice(0, 250), billing_event: src.billing_event, optimization_goal: src.optimization_goal, bid_strategy: src.bid_strategy, bid_amount: src.bid_amount,
      daily_budget: toMinor(o.dailyBudget, o.currency), end_time: o.days > 0 ? new Date(now.getTime() + o.days * 86_400_000).toISOString() : undefined,
      promoted_object: src.promoted_object, attribution_spec: src.attribution_spec, destination_type: src.destination_type, targeting: s.targeting, status,
    })),
  };
}

/** Creates the ad sets (in the source's campaign, or a new one) and copies the source ad set's ads into each. */
export async function launchAdSets(token: string, accountExternalId: string, sourceAdSetExternalId: string, build: (srcTargeting: Record<string, any>) => { name: string; targeting: Record<string, any> }[], o: LaunchOptions) {
  const a = act(accountExternalId);
  const src: Record<string, any> = await metaGet(sourceAdSetExternalId, token, { fields: [...COPY, "campaign{objective,special_ad_categories,special_ad_category_country,buying_type}"].join(",") });
  const ads: { data?: { id: string }[] } = await metaGet(`${sourceAdSetExternalId}/ads`, token, { fields: "id", limit: 50 });
  const plan = launchPlan(src, build(src.targeting ?? {}), o);
  let campaignId = String(src.campaign_id);
  if (plan.campaign) {
    try { campaignId = String((await metaPost(`${a}/campaigns`, token, plan.campaign)).id); }
    catch (e) {
      if (!/is_adset_budget_sharing_enabled/i.test((e as Error).message)) throw e;
      const { is_adset_budget_sharing_enabled: _drop, ...rest } = plan.campaign;
      campaignId = String((await metaPost(`${a}/campaigns`, token, rest)).id);
    }
  }
  const created: { externalId: string; name: string; adErrors: string[] }[] = [];
  for (const s of plan.adSets) {
    const r = await metaPost(`${a}/adsets`, token, { ...s, campaign_id: campaignId });
    const adErrors: string[] = [];
    for (const ad of ads.data ?? []) {
      try { await metaPost(`${ad.id}/copies`, token, { adset_id: r.id, status_option: o.startNow ? "ACTIVE" : "PAUSED" }); } catch (e) { adErrors.push((e as Error).message); }
    }
    created.push({ externalId: String(r.id), name: s.name, adErrors });
  }
  return { campaignExternalId: campaignId, newCampaign: !!plan.campaign, adSets: created };
}
