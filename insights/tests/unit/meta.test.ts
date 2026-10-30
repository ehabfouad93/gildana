import { describe, expect, it } from "vitest";
import { breakdownValue, flattenTargeting, insightMetrics, minor } from "@/platforms/meta/normalize";

describe("meta normalize", () => {
  it("reads leads and purchases from actions without double counting", () => {
    const m = insightMetrics({
      spend: "12.50", impressions: "1000", clicks: "30", inline_link_clicks: "20", reach: "800",
      actions: [{ action_type: "lead", value: "4" }, { action_type: "onsite_conversion.lead_grouped", value: "4" }, { action_type: "omni_purchase", value: "2" }, { action_type: "purchase", value: "2" }],
      action_values: [{ action_type: "omni_purchase", value: "150.5" }],
    });
    expect(m).toMatchObject({ spend: 12.5, impressions: 1000, leads: 4, purchases: 2, conversions: 6, value: 150.5, linkClicks: 20 });
  });

  it("converts minor units", () => {
    expect(minor("2500")).toBe(25);
    expect(minor(undefined)).toBeUndefined();
  });

  it("flattens ad set targeting", () => {
    const items = flattenTargeting({
      age_min: 25, age_max: 45, genders: [2],
      geo_locations: { countries: ["EG"], cities: [{ key: "1", name: "Cairo", radius: 40, distance_unit: "kilometer" }] },
      flexible_spec: [{ interests: [{ id: "6003", name: "Real estate" }], behaviors: [{ id: "77", name: "Frequent travellers" }] }],
      exclusions: { interests: [{ id: "9", name: "Gaming" }] },
      custom_audiences: [{ id: "a1", name: "Site visitors" }],
      publisher_platforms: ["instagram"], instagram_positions: ["stream", "reels"],
    });
    const has = (type: string, value: string, exclude = false) => items.some((i) => i.type === type && i.value === value && !!i.exclude === exclude);
    expect(has("interest", "Real estate")).toBe(true);
    expect(has("interest", "Gaming", true)).toBe(true);
    expect(has("behavior", "Frequent travellers")).toBe(true);
    expect(has("audience", "Site visitors")).toBe(true);
    expect(has("age", "25-45")).toBe(true);
    expect(has("gender", "Female")).toBe(true);
    expect(has("geo", "Cairo +40km")).toBe(true);
    expect(has("placement", "instagram_reels")).toBe(true);
  });

  it("maps breakdown rows to bucket values", () => {
    expect(breakdownValue("age_gender", { age: "25-34", gender: "female" })).toBe("25-34|female");
    expect(breakdownValue("placement", { publisher_platform: "instagram", platform_position: "story" })).toBe("instagram_story");
    expect(breakdownValue("hour", { hourly_stats_aggregated_by_advertiser_time_zone: "13:00:00 - 13:59:59" })).toBe("13");
  });
});
