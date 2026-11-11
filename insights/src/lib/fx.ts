import { db } from "@/lib/db";
import { FALLBACK } from "@/lib/fx-rates";
import { fetchJson } from "@/lib/http";

/**
 * Currency conversion into the client's reporting currency. Rates are units
 * of the currency per 1 USD. Rows in FxRate (admin-editable) override these
 * fallbacks; the latest row per currency wins.
 */


export type FxTable = Record<string, number>;

export async function fxTable(): Promise<FxTable> {
  const t = { ...FALLBACK };
  const rows = await db.fxRate.findMany({ where: { base: "USD" }, orderBy: { date: "asc" } });
  for (const r of rows) t[r.quote] = Number(r.rate);
  return t;
}

/** Date of the newest stored rates, or null when only the built-in fallbacks are used. */
export async function fxUpdatedAt(): Promise<Date | null> {
  const r = await db.fxRate.findFirst({ where: { base: "USD" }, orderBy: { date: "desc" }, select: { date: true } });
  return r?.date ?? null;
}

/** Turns the rates API's answer into units-per-USD, keeping only sane positive numbers. */
export function parseRates(data: unknown): FxTable {
  const rates = (data as { rates?: Record<string, unknown> } | null)?.rates ?? {};
  const out: FxTable = {};
  for (const [k, v] of Object.entries(rates)) if (/^[A-Z]{3}$/.test(k) && typeof v === "number" && v > 0 && Number.isFinite(v)) out[k] = v;
  return out;
}

/**
 * Stores today's exchange rates (units per USD) from a free daily feed, so
 * converted totals follow the real market. Runs from the worker at most
 * every 12 hours; on failure the last stored rates keep being used.
 */
let lastFailure = 0;

export async function refreshFxRates(force = false): Promise<number> {
  if (!force && Date.now() - lastFailure < 30 * 60_000) return 0; // don't hammer the feed after a failure
  const last = await fxUpdatedAt();
  const today = new Date(new Date().toISOString().slice(0, 10) + "T00:00:00Z");
  if (!force && last && last.getTime() >= today.getTime()) return 0;
  const url = process.env.FX_RATES_URL || "https://open.er-api.com/v6/latest/USD";
  let rates: FxTable;
  try {
    rates = parseRates(await fetchJson(url, { tries: 2, timeoutMs: 20_000 }));
    if (!rates.EUR || !rates.EGP) throw new Error("Exchange-rate feed returned no usable rates");
  } catch (e) {
    lastFailure = Date.now();
    throw e;
  }
  await db.$transaction(Object.entries(rates).map(([quote, rate]) =>
    db.fxRate.upsert({ where: { date_base_quote: { date: today, base: "USD", quote } }, create: { date: today, base: "USD", quote, rate }, update: { rate } })));
  return Object.keys(rates).length;
}

export function convert(amount: number, from: string, to: string, t: FxTable): number {
  if (from === to) return amount;
  const a = t[from], b = t[to];
  if (!a || !b) return amount; // unknown currency: show as-is rather than drop it
  return (amount / a) * b;
}
