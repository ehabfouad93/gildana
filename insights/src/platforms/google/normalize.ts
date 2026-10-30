import type { Metrics, RemoteTargetingItem } from "../types";

/** Google reports money in micros of the account currency. */
export const micros = (v: unknown) => Number(v ?? 0) / 1_000_000;

export function gMetrics(m: Record<string, any> | undefined): Metrics {
  const x = m ?? {};
  const conv = Number(x.conversions ?? 0);
  return {
    spend: micros(x.costMicros),
    impressions: Number(x.impressions ?? 0),
    clicks: Number(x.clicks ?? 0),
    linkClicks: Number(x.clicks ?? 0),
    conversions: conv,
    // Google has no separate lead metric; conversions are the lead count for lead-gen accounts.
    leads: Math.round(conv),
    value: Number(x.conversionsValue ?? 0),
    videoViews: Number(x.videoViews ?? 0),
    engagements: Number(x.interactions ?? 0),
  };
}

const CRITERION_TYPE: Record<string, string> = {
  KEYWORD: "keyword", AGE_RANGE: "age", GENDER: "gender", INCOME_RANGE: "demographic", PARENTAL_STATUS: "demographic",
  USER_INTEREST: "interest", USER_LIST: "audience", CUSTOM_AUDIENCE: "audience", COMBINED_AUDIENCE: "audience",
  PLACEMENT: "placement", MOBILE_APPLICATION: "placement", YOUTUBE_CHANNEL: "placement", YOUTUBE_VIDEO: "placement",
  TOPIC: "interest", WEBPAGE: "placement", LOCATION: "geo", LANGUAGE: "language", PROXIMITY: "geo", DEVICE: "device",
};

const AGE: Record<string, string> = {
  AGE_RANGE_18_24: "18-24", AGE_RANGE_25_34: "25-34", AGE_RANGE_35_44: "35-44", AGE_RANGE_45_54: "45-54",
  AGE_RANGE_55_64: "55-64", AGE_RANGE_65_UP: "65+", AGE_RANGE_UNDETERMINED: "undetermined",
};
const GENDER: Record<string, string> = { MALE: "male", FEMALE: "female", UNDETERMINED: "undetermined" };

export const ageLabel = (t: string) => AGE[t] ?? t;
export const genderLabel = (t: string) => GENDER[t] ?? t.toLowerCase();

/** One ad_group_criterion / campaign_criterion row → a targeting item. */
export function criterionItem(c: Record<string, any>): RemoteTargetingItem | null {
  const type = CRITERION_TYPE[c.type];
  if (!type) return null;
  let value: string = c.displayName ?? "";
  if (c.type === "KEYWORD") value = c.keyword?.text ?? value;
  if (c.type === "AGE_RANGE") value = ageLabel(c.ageRange?.type ?? value);
  if (c.type === "GENDER") value = genderLabel(c.gender?.type ?? value);
  if (c.type === "PLACEMENT") value = c.placement?.url ?? value;
  if (!value) return null;
  const match = c.type === "KEYWORD" && c.keyword?.matchType ? ` [${String(c.keyword.matchType).toLowerCase()}]` : "";
  return { type, value: type === "keyword" ? value : value + match, externalId: String(c.criterionId ?? ""), exclude: !!c.negative };
}

/** Ad copy across the ad types we can show as a preview. */
export function adCopy(ad: Record<string, any>) {
  const rsa = ad.responsiveSearchAd, rda = ad.responsiveDisplayAd, eta = ad.expandedTextAd, video = ad.videoResponsiveAd;
  const first = (xs?: { text?: string }[]) => xs?.[0]?.text ?? "";
  const join = (xs?: { text?: string }[], n = 3) => (xs ?? []).slice(0, n).map((x) => x.text).filter(Boolean).join(" | ");
  return {
    headline: rsa ? join(rsa.headlines) : rda ? first(rda.headlines) : eta ? `${eta.headlinePart1} | ${eta.headlinePart2}` : video ? first(video.headlines) : "",
    body: rsa ? join(rsa.descriptions, 2) : rda ? first(rda.descriptions) : eta ? eta.description ?? "" : video ? first(video.descriptions) : "",
    format: rsa ? "responsive_search" : rda ? "responsive_display" : video ? "video" : String(ad.type ?? "").toLowerCase(),
  };
}
