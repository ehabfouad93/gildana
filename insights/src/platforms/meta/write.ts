import { fetchJson } from "@/lib/http";
import { setting } from "@/lib/settings";

/**
 * Every call that changes something in a Meta ad account goes through here.
 * Nothing in this file runs by itself: callers are user-pressed actions
 * (see src/lib/actions-engine.ts and the split-test actions).
 */

const V = () => setting("META_GRAPH_VERSION") || "v21.0";
export const G = () => `https://graph.facebook.com/${V()}`;

/** Currencies Meta counts in whole units; every other currency is sent in hundredths. */
const WHOLE_UNIT = new Set(["CLP", "COP", "CRC", "HUF", "ISK", "IDR", "JPY", "KRW", "PYG", "TWD", "VND"]);
export const toMinor = (amount: number, currency: string) => Math.round(amount * (WHOLE_UNIT.has(currency.toUpperCase()) ? 1 : 100));

export const act = (accountExternalId: string) => (accountExternalId.startsWith("act_") ? accountExternalId : `act_${accountExternalId}`);

/** One write. No retries: a repeated create could duplicate an ad set or a charge. */
export async function metaPost<T = { id?: string; success?: boolean; copied_ad_id?: string }>(path: string, token: string, params: Record<string, unknown>): Promise<T> {
  const body = new URLSearchParams({ access_token: token });
  for (const [k, v] of Object.entries(params)) if (v != null && v !== "") body.set(k, typeof v === "string" ? v : JSON.stringify(v));
  return fetchJson<T>(`${G()}/${path}`, { method: "POST", body, tries: 1 });
}

export async function metaGet<T = unknown>(path: string, token: string, params: Record<string, string | number> = {}): Promise<T> {
  return fetchJson<T>(`${G()}/${path}?` + new URLSearchParams({ access_token: token, ...Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])) }));
}

/** Switches a campaign, ad set or ad on or off. */
export const setStatus = (token: string, externalId: string, status: "ACTIVE" | "PAUSED") => metaPost(externalId, token, { status });

/** Sets the daily budget of a campaign or ad set, given in the account's currency. */
export const setDailyBudget = (token: string, externalId: string, amount: number, currency: string) => metaPost(externalId, token, { daily_budget: toMinor(amount, currency) });
