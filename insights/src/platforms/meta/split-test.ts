import { fetchJson } from "@/lib/http";
import type { RemoteTargetingItem } from "../types";

/**
 * Interest split test on Meta: copies one ad set into one ad set per
 * interest — same campaign, optimisation, placements, ads and the rest of
 * its targeting — so each interest's results are measured directly. Every
 * copy is created PAUSED; nothing spends until someone switches it on.
 */

const V = () => process.env.META_GRAPH_VERSION || "v21.0";
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

async function post(path: string, token: string, params: Record<string, unknown>) {
  const body = new URLSearchParams({ access_token: token });
  for (const [k, v] of Object.entries(params)) if (v != null && v !== "") body.set(k, typeof v === "string" ? v : JSON.stringify(v));
  return fetchJson<{ id?: string; copied_ad_id?: string }>(`${G()}/${path}`, { method: "POST", body, tries: 1 });
}

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
