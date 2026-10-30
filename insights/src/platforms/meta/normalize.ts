import type { Metrics, RemoteTargetingItem } from "../types";

/** Meta money fields on campaigns/ad sets are in the currency's minor unit. */
export const minor = (v: unknown) => (v == null || v === "" ? undefined : Number(v) / 100);

type Action = { action_type: string; value: string };

const LEAD_TYPES = ["lead", "onsite_conversion.lead_grouped", "offsite_conversion.fb_pixel_lead", "leadgen_grouped"];
const PURCHASE_TYPES = ["omni_purchase", "purchase", "offsite_conversion.fb_pixel_purchase", "onsite_web_purchase"];

/** Several action types report the same event at different scopes; take the broadest one present. */
function firstOf(actions: Action[] | undefined, types: string[]): number {
  if (!actions) return 0;
  for (const t of types) {
    const hit = actions.find((a) => a.action_type === t);
    if (hit) return Number(hit.value) || 0;
  }
  return 0;
}

export function insightMetrics(row: Record<string, any>): Metrics {
  const leads = firstOf(row.actions, LEAD_TYPES);
  const purchases = firstOf(row.actions, PURCHASE_TYPES);
  return {
    spend: Number(row.spend ?? 0),
    impressions: Number(row.impressions ?? 0),
    reach: Number(row.reach ?? 0),
    clicks: Number(row.clicks ?? 0),
    linkClicks: Number(row.inline_link_clicks ?? 0),
    leads,
    purchases,
    conversions: leads + purchases,
    value: firstOf(row.action_values, PURCHASE_TYPES),
    videoViews: firstOf(row.actions, ["video_view"]),
    engagements: firstOf(row.actions, ["post_engagement"]),
  };
}

const GENDER: Record<number, string> = { 1: "Male", 2: "Female" };

/** Flattens a Meta ad set `targeting` object into rankable items. */
export function flattenTargeting(t: Record<string, any> | null | undefined): RemoteTargetingItem[] {
  if (!t) return [];
  const out: RemoteTargetingItem[] = [];
  const push = (type: string, list: any[] | undefined, exclude = false) =>
    (list ?? []).forEach((x) => out.push({ type, value: String(x.name ?? x.id), externalId: String(x.id ?? ""), exclude }));

  for (const spec of t.flexible_spec ?? []) {
    push("interest", spec.interests);
    push("behavior", spec.behaviors);
    push("demographic", [...(spec.life_events ?? []), ...(spec.family_statuses ?? []), ...(spec.education_statuses ?? []), ...(spec.work_positions ?? []), ...(spec.industries ?? [])]);
  }
  push("interest", t.interests);
  push("behavior", t.behaviors);
  const ex = t.exclusions ?? {};
  push("interest", ex.interests, true);
  push("behavior", ex.behaviors, true);
  push("audience", t.custom_audiences);
  push("audience", t.excluded_custom_audiences, true);

  if (t.age_min || t.age_max) out.push({ type: "age", value: `${t.age_min ?? 18}-${t.age_max ?? 65}` });
  const g = (t.genders ?? []) as number[];
  out.push({ type: "gender", value: g.length === 1 ? GENDER[g[0]] ?? "All" : "All" });

  const geo = t.geo_locations ?? {};
  (geo.countries ?? []).forEach((c: string) => out.push({ type: "geo", value: c }));
  (geo.regions ?? []).forEach((r: any) => out.push({ type: "geo", value: r.name, externalId: String(r.key ?? "") }));
  (geo.cities ?? []).forEach((c: any) => out.push({ type: "geo", value: `${c.name}${c.radius ? ` +${c.radius}${c.distance_unit === "mile" ? "mi" : "km"}` : ""}`, externalId: String(c.key ?? "") }));
  (t.locales ?? []).forEach((l: number) => out.push({ type: "language", value: String(l) }));

  const pubs = t.publisher_platforms as string[] | undefined;
  if (!pubs) out.push({ type: "placement", value: "Advantage+ placements" });
  else {
    const pos = [...(t.facebook_positions ?? []).map((p: string) => `facebook_${p}`), ...(t.instagram_positions ?? []).map((p: string) => `instagram_${p}`)];
    (pos.length ? pos : pubs).forEach((p: string) => out.push({ type: "placement", value: p }));
  }
  (t.device_platforms ?? []).forEach((d: string) => out.push({ type: "device", value: d }));
  return out;
}

/** Maps a Meta breakdown row to our dimension name + value. */
export function breakdownValue(dimension: string, row: Record<string, any>): string {
  switch (dimension) {
    case "age_gender": return `${row.age}|${row.gender}`;
    case "placement": return `${row.publisher_platform}_${row.platform_position}`;
    case "device": return String(row.device_platform ?? row.impression_device ?? "unknown");
    case "hour": return String(row.hourly_stats_aggregated_by_advertiser_time_zone ?? "").slice(0, 2);
    default: return String(row[dimension] ?? "unknown");
  }
}

/** Our dimension name → the `breakdowns` parameter Meta expects. */
export const META_BREAKDOWNS: Record<string, string> = {
  age: "age",
  gender: "gender",
  age_gender: "age,gender",
  country: "country",
  region: "region",
  device: "device_platform",
  placement: "publisher_platform,platform_position",
  hour: "hourly_stats_aggregated_by_advertiser_time_zone",
};
