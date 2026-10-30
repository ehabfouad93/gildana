import { LOWER_IS_BETTER, delta, type Derived } from "@/lib/metrics";
import { compact, money, num, pct, ratio } from "@/lib/format";
import type { Dict } from "@/lib/i18n";

export type MetricKey = "spend" | "impressions" | "reach" | "clicks" | "ctr" | "cpc" | "cpm" | "conversions" | "leads" | "cpl" | "cpa" | "roas" | "value" | "frequency" | "convRate" | "videoViews";

export function fmtMetric(k: MetricKey, v: number | null, currency: string): string {
  switch (k) {
    case "spend": case "value": return money(v, currency, 0);
    case "cpc": case "cpm": case "cpl": case "cpa": return money(v, currency, 2);
    case "ctr": case "convRate": return pct(v);
    case "roas": return ratio(v);
    case "frequency": return num(v, 2);
    case "impressions": case "reach": case "videoViews": return compact(v);
    default: return num(v, 0);
  }
}

export const METRIC_LABEL = (t: Dict): Record<MetricKey, string> => ({
  spend: t.spendLabel, impressions: t.impressions, reach: t.reach, clicks: t.clicks, ctr: t.ctr, cpc: t.cpc, cpm: t.cpm,
  conversions: t.conversions, leads: t.leads, cpl: t.cpl, cpa: t.cpa, roas: t.roas, value: t.value, frequency: t.frequency,
  convRate: "Conv. rate", videoViews: "Video views",
});

export function KpiGrid({ cur, prev, keys, currency, t }: { cur: Derived; prev?: Derived; keys: MetricKey[]; currency: string; t: Dict }) {
  const labels = METRIC_LABEL(t);
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
      {keys.map((k) => {
        const d = prev ? delta(cur[k] as number | null, prev[k] as number | null) : null;
        const good = d == null ? null : LOWER_IS_BETTER.has(k) ? d < 0 : d > 0;
        return (
          <div key={k} className="card !p-3">
            <div className="text-xs text-muted">{labels[k]}</div>
            <div className="mt-1 text-lg font-semibold tabular-nums">{fmtMetric(k, cur[k] as number | null, currency)}</div>
            {d != null && (
              <div className={`text-xs tabular-nums ${good ? "text-good" : "text-bad"}`} title={t.vsPrev}>
                {d > 0 ? "▲" : "▼"} {Math.abs(d).toFixed(1)}%
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
