/** Date ranges are inclusive, whole UTC days, carried as YYYY-MM-DD strings. */
export type Range = { from: string; to: string };

export function ymd(d: Date): string {
  return d.toISOString().slice(0, 10);
}

export function addDays(day: string, n: number): string {
  const d = new Date(day + "T00:00:00Z");
  d.setUTCDate(d.getUTCDate() + n);
  return ymd(d);
}

export function daysBetween(r: Range): number {
  return Math.round((Date.parse(r.to) - Date.parse(r.from)) / 86_400_000) + 1;
}

export function lastNDays(n: number, today = ymd(new Date())): Range {
  const to = addDays(today, -1); // today is still incomplete on every platform
  return { from: addDays(to, -(n - 1)), to };
}

/** The same-length window immediately before `r`, for period-over-period. */
export function previousRange(r: Range): Range {
  const n = daysBetween(r);
  return { from: addDays(r.from, -n), to: addDays(r.from, -1) };
}

export function eachDay(r: Range): string[] {
  const out: string[] = [];
  for (let d = r.from; d <= r.to; d = addDays(d, 1)) out.push(d);
  return out;
}

const RE = /^\d{4}-\d{2}-\d{2}$/;

/** Reads ?from=&to= or ?days= from search params, defaulting to last 30 days. */
export function rangeFromParams(p: { from?: string; to?: string; days?: string }): Range {
  if (p.from && p.to && RE.test(p.from) && RE.test(p.to) && p.from <= p.to) return { from: p.from, to: p.to };
  const n = Math.min(1100, Math.max(1, Number(p.days) || 30));
  return lastNDays(n);
}

export function toDate(day: string): Date {
  return new Date(day + "T00:00:00Z");
}
