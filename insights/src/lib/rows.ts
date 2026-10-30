import type { Derived } from "@/lib/metrics";

/** Flattens derived metrics into a table row. */
export function mrow(m: Derived) {
  return {
    spend: m.spend, impressions: m.impressions, reach: m.reach, clicks: m.clicks, ctr: m.ctr, cpc: m.cpc, cpm: m.cpm,
    conversions: m.conversions, leads: m.leads, cpl: m.cpl, cpa: m.cpa, roas: m.roas, value: m.value, frequency: m.frequency,
    convRate: m.convRate, videoViews: m.videoViews,
  };
}
