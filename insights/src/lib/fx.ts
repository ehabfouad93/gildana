import { db } from "@/lib/db";
import { FALLBACK } from "@/lib/fx-rates";

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

export function convert(amount: number, from: string, to: string, t: FxTable): number {
  if (from === to) return amount;
  const a = t[from], b = t[to];
  if (!a || !b) return amount; // unknown currency: show as-is rather than drop it
  return (amount / a) * b;
}
