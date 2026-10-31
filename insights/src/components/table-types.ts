export type Fmt = "text" | "money" | "money2" | "num" | "compact" | "pct" | "ratio" | "dec" | "badge";
export type Col = { key: string; label: string; fmt?: Fmt; href?: string; sub?: string; wrap?: boolean };
export type Row = Record<string, string | number | null | undefined>;

/** Standard metric columns, shared by every performance table. */
export const METRIC_COLS: Col[] = [
  { key: "spend", label: "Spend", fmt: "money" },
  { key: "impressions", label: "Impr.", fmt: "compact" },
  { key: "clicks", label: "Clicks", fmt: "num" },
  { key: "ctr", label: "CTR", fmt: "pct" },
  { key: "cpc", label: "CPC", fmt: "money2" },
  { key: "cpm", label: "CPM", fmt: "money2" },
  { key: "conversions", label: "Conv.", fmt: "num" },
  { key: "leads", label: "Leads", fmt: "num" },
  { key: "cpl", label: "CPL", fmt: "money2" },
  { key: "roas", label: "ROAS", fmt: "ratio" },
];

/** Where a per-segment number comes from (see audienceInsights in src/lib/queries.ts). */
export const SOURCE_LABEL: Record<string, string> = {
  reported: "Reported", tested: "Tested", measured: "Measured", estimated: "Estimated", adset: "Ad-set ranking", setting: "Setting",
};
