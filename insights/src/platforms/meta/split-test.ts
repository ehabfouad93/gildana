import { fetchJson } from "@/lib/http";
import type { RemoteTargetingItem } from "../types";
import { setting } from "@/lib/settings";
import { metaPost, toMinor } from "./write";

export { toMinor };

/**
 * Interest split test on Meta: copies one ad set into one ad set per
 * interest — same campaign, optimisation, placements, ads and the rest of
 * its targeting — so each interest's results are measured directly. Every
 * copy is created PAUSED; nothing spends until someone switches it on.
 */

const V = () => setting("META_GRAPH_VERSION") || "v21.0";
const G = () => `https://graph.facebook.com/${V()}`;

const FACET: Record<string, string> = { interest: "interests", behavior: "behaviors" };

/** The source targeting with its detailed targeting reduced to one interest (or behaviour). Pure; unit-tested. */
export function armTargeting(targeting: Record<string, any>, item: RemoteTargetingItem): Record<string, any> {
  const t = JSON.parse(JSON.stringify(targeting ?? {}));
  delete t.interests;
  delete t.behaviors;
  delete t.flexible_spec;
  t.flexible_spec = [{ [FACET[item.type] ?? "interests"]: [{ id: item.externalId, name: item.value }] }];
  return t;
}

/** Splits a budget in the account's minor units so the parts add back up exactly. */
export function splitBudget(minorUnits: number, n: number): number[] {
  const base = Math.floor(minorUnits / n);
  return Array.from({ length: n }, (_, i) => base + (i < minorUnits - base * n ? 1 : 0));
}

const COPY_FIELDS = ["campaign_id", "billing_event", "optimization_goal", "bid_strategy", "bid_amount", "daily_budget", "lifetime_budget", "end_time", "promoted_object", "attribution_spec", "destination_type", "targeting", "name"];

const post = metaPost;

export async function createInterestSplit(token: string, accountExternalId: string, adSetExternalId: string, items: RemoteTargetingItem[]) {
  const act = accountExternalId.startsWith("act_") ? accountExternalId : `act_${accountExternalId}`;
  const src: Record<string, any> = await fetchJson(`${G()}/${adSetExternalId}?` + new URLSearchParams({ access_token: token, fields: COPY_FIELDS.join(",") }));
  const ads: { data?: { id: string }[] } = await fetchJson(`${G()}/${adSetExternalId}/ads?` + new URLSearchParams({ access_token: token, fields: "id", limit: "50" }));
  const daily = src.daily_budget ? splitBudget(Number(src.daily_budget), items.length) : null;
  const lifetime = src.lifetime_budget ? splitBudget(Number(src.lifetime_budget), items.length) : null;

  const arms: { externalId: string; interest: RemoteTargetingItem; adErrors: string[] }[] = [];
  for (const [i, item] of items.entries()) {
    const created = await post(`${act}/adsets`, token, {
      name: `${src.name} – ${item.value}`.slice(0, 250), campaign_id: src.campaign_id, billing_event: src.billing_event,
      optimization_goal: src.optimization_goal, bid_strategy: src.bid_strategy, bid_amount: src.bid_amount,
      daily_budget: daily?.[i], lifetime_budget: lifetime?.[i], end_time: src.end_time, promoted_object: src.promoted_object,
      attribution_spec: src.attribution_spec, destination_type: src.destination_type, targeting: armTargeting(src.targeting, item), status: "PAUSED",
    });
    const adErrors: string[] = [];
    for (const ad of ads.data ?? []) {
      try { await post(`${ad.id}/copies`, token, { adset_id: created.id, status_option: "PAUSED" }); } catch (e) { adErrors.push((e as Error).message); }
    }
    arms.push({ externalId: String(created.id), interest: item, adErrors });
  }
  return { arms, dailyBudget: src.daily_budget ? Number(src.daily_budget) / 100 : null };
}

// ───────── a new campaign with one ad set per interest ─────────

export type SplitCampaignOptions = {
  name: string;
  /** Daily budget of each ad set, in the account's currency. */
  dailyBudget: number;
  currency: string;
  /** Days to run; 0 = no end date. */
  days: number;
  /** false (default) creates everything paused. */
  startNow: boolean;
};

/** One arm's targeting: the source's, with detailed targeting reduced to this interest and Advantage+ audience off so Meta stays inside it. Pure; unit-tested. */
export function strictArmTargeting(targeting: Record<string, any>, item: RemoteTargetingItem): Record<string, any> {
  const t = armTargeting(targeting, item);
  delete t.targeting_optimization;
  t.targeting_automation = { ...(t.targeting_automation ?? {}), advantage_audience: 0 };
  return t;
}

/** The campaign and ad-set requests for a split-test campaign, before any call is made. Pure; unit-tested. */
export function splitCampaignPlan(src: Record<string, any>, items: RemoteTargetingItem[], o: SplitCampaignOptions, now = new Date()) {
  const status = o.startNow ? "ACTIVE" : "PAUSED";
  const end = o.days > 0 ? new Date(now.getTime() + o.days * 86_400_000).toISOString() : undefined;
  const c = src.campaign ?? {};
  return {
    campaign: {
      name: o.name.slice(0, 250), objective: c.objective, status,
      special_ad_categories: c.special_ad_categories ?? [], special_ad_category_country: c.special_ad_category_country,
      buying_type: c.buying_type ?? "AUCTION",
      // Each ad set keeps its own budget, so every interest gets the same spend.
      is_adset_budget_sharing_enabled: false,
    },
    adSets: items.map((item) => ({
      name: `${item.value}`.slice(0, 250), billing_event: src.billing_event, optimization_goal: src.optimization_goal,
      bid_strategy: src.bid_strategy, bid_amount: src.bid_amount, daily_budget: toMinor(o.dailyBudget, o.currency), end_time: end,
      promoted_object: src.promoted_object, attribution_spec: src.attribution_spec, destination_type: src.destination_type,
      targeting: strictArmTargeting(src.targeting, item), status,
    })),
  };
}

/** Finds the Meta interest that matches a name (for AI-suggested interests the account has never used). */
export async function findInterest(token: string, accountExternalId: string, name: string): Promise<RemoteTargetingItem | null> {
  const act = accountExternalId.startsWith("act_") ? accountExternalId : `act_${accountExternalId}`;
  const r: { data?: { id: string; name: string; type: string }[] } = await fetchJson(`${G()}/${act}/targetingsearch?` + new URLSearchParams({ access_token: token, q: name, limit: "10" }));
  const list = (r.data ?? []).filter((x) => x.type === "interests");
  const hit = list.find((x) => x.name.toLowerCase() === name.toLowerCase()) ?? list[0];
  return hit ? { type: "interest", value: hit.name, externalId: String(hit.id) } : null;
}

/**
 * Creates a new campaign with one ad set per interest, each a copy of the
 * source ad set (ads, optimisation, placements, location, age) with its own
 * equal daily budget. Paused unless `startNow`.
 */
export async function createSplitCampaign(token: string, accountExternalId: string, adSetExternalId: string, items: RemoteTargetingItem[], o: SplitCampaignOptions) {
  const act = accountExternalId.startsWith("act_") ? accountExternalId : `act_${accountExternalId}`;
  const fields = [...COPY_FIELDS, "campaign{objective,special_ad_categories,special_ad_category_country,buying_type}"].join(",");
  const src: Record<string, any> = await fetchJson(`${G()}/${adSetExternalId}?` + new URLSearchParams({ access_token: token, fields }));
  const ads: { data?: { id: string }[] } = await fetchJson(`${G()}/${adSetExternalId}/ads?` + new URLSearchParams({ access_token: token, fields: "id", limit: "50" }));
  const plan = splitCampaignPlan(src, items, o);

  let campaign: { id?: string };
  try {
    campaign = await post(`${act}/campaigns`, token, plan.campaign);
  } catch (e) {
    // Graph versions before the budget-sharing switch reject the parameter; the default there is already per-ad-set budgets.
    if (!/is_adset_budget_sharing_enabled/i.test((e as Error).message)) throw e;
    const { is_adset_budget_sharing_enabled: _drop, ...rest } = plan.campaign;
    campaign = await post(`${act}/campaigns`, token, rest);
  }
  const campaignId = String(campaign.id);

  const arms: { externalId: string; interest: RemoteTargetingItem; adErrors: string[] }[] = [];
  for (const [i, item] of items.entries()) {
    const created = await post(`${act}/adsets`, token, { ...plan.adSets[i], campaign_id: campaignId });
    const adErrors: string[] = [];
    for (const ad of ads.data ?? []) {
      try { await post(`${ad.id}/copies`, token, { adset_id: created.id, status_option: o.startNow ? "ACTIVE" : "PAUSED" }); } catch (e) { adErrors.push((e as Error).message); }
    }
    arms.push({ externalId: String(created.id), interest: item, adErrors });
  }
  return { campaignExternalId: campaignId, arms };
}

/** Pauses ad sets (the losing arms of a finished test). */
export async function pauseAdSets(token: string, adSetExternalIds: string[]) {
  for (const id of adSetExternalIds) await post(id, token, { status: "PAUSED" });
}
