import { describe, expect, it } from "vitest";
import { PRESETS, launchPlan, lookalikeAudience, mixName, mixTargeting, presetAudience } from "@/platforms/meta/audiences";

const I = (value: string, id: string, type = "interest") => ({ type, value, externalId: id });

describe("audience mixer", () => {
  const base = { geo_locations: { countries: ["EG"] }, age_min: 25, publisher_platforms: ["facebook"], flexible_spec: [{ interests: [{ id: "9", name: "Old" }] }], custom_audiences: [{ id: "77" }], targeting_automation: { advantage_audience: 1 } };
  it("joins items inside a group with OR and the groups with AND, and keeps the rest of the targeting", () => {
    const t = mixTargeting(base, [[I("Real estate", "1"), I("Luxury", "2")], [I("Expats", "3", "behavior"), I("Visitors 30d", "50", "audience")]]);
    expect(t.flexible_spec).toEqual([
      { interests: [{ id: "1", name: "Real estate" }, { id: "2", name: "Luxury" }] },
      { behaviors: [{ id: "3", name: "Expats" }], custom_audiences: [{ id: "50" }] },
    ]);
    expect(t.geo_locations).toEqual({ countries: ["EG"] });
    expect(t.custom_audiences).toBeUndefined();
    expect(t.targeting_automation.advantage_audience).toBe(0);
  });
  it("puts exclusions where Meta expects them", () => {
    const t = mixTargeting(base, [[I("Real estate", "1")]], [I("Students", "4"), I("Customers", "60", "audience")]);
    expect(t.exclusions).toEqual({ interests: [{ id: "4", name: "Students" }] });
    expect(t.excluded_custom_audiences).toEqual([{ id: "60" }]);
  });
  it("names the mix readably and leaves the source untouched", () => {
    expect(mixName([[I("A", "1"), I("B", "2")], [I("C", "3")]], [I("D", "4")])).toBe("A or B + C (excl. D)");
    expect(base.flexible_spec[0].interests[0].name).toBe("Old");
  });
});

describe("ready-made audiences", () => {
  const p = (key: string) => PRESETS.find((x) => x.key === key)!;
  it("builds website, page, Instagram and video audiences from the right source", () => {
    const web = presetAudience(p("web_30"), { pixelId: "px" }, "Visitors 30d") as any;
    expect(web.rule.inclusions.rules[0]).toMatchObject({ event_sources: [{ id: "px", type: "pixel" }], retention_seconds: 30 * 86400 });
    expect((presetAudience(p("page_90"), { pageId: "pg" }, "x") as any).rule.inclusions.rules[0].event_sources[0]).toEqual({ id: "pg", type: "page" });
    expect((presetAudience(p("ig_365"), { instagramId: "ig" }, "x") as any).rule.inclusions.rules[0].retention_seconds).toBe(365 * 86400);
    expect(presetAudience(p("video_75"), { videoIds: ["v1", "v2"] }, "x")).toMatchObject({ subtype: "ENGAGEMENT", rule: [{ object_id: "v1", event_name: "video_view_75_percent" }, { object_id: "v2", event_name: "video_view_75_percent" }] });
  });
  it("returns nothing when the account lacks the source", () => {
    expect(presetAudience(p("web_30"), { pageId: "pg" }, "x")).toBeNull();
    expect(presetAudience(p("video_50"), { videoIds: [] }, "x")).toBeNull();
  });
  it("builds a lookalike with a 1–10% ratio", () => {
    expect(lookalikeAudience("50", "Leads", "eg", 2)).toEqual({ name: "Lookalike (EG, 2%) - Leads", subtype: "LOOKALIKE", origin_audience_id: "50", lookalike_spec: { type: "similarity", ratio: 0.02, country: "EG" } });
    expect((lookalikeAudience("50", "Leads", "SA", 40).lookalike_spec as any).ratio).toBe(0.1);
  });
});

describe("launching ad sets", () => {
  const src = { billing_event: "IMPRESSIONS", optimization_goal: "LEAD_GENERATION", promoted_object: { page_id: "1" }, campaign: { objective: "OUTCOME_LEADS", special_ad_categories: [] } };
  it("creates paused ad sets in the source's campaign by default", () => {
    const plan = launchPlan(src, [{ name: "Mix", targeting: { a: 1 } }], { dailyBudget: 200, currency: "EGP", days: 0, startNow: false });
    expect(plan.campaign).toBeNull();
    expect(plan.adSets[0]).toMatchObject({ name: "Mix", status: "PAUSED", daily_budget: 20000, targeting: { a: 1 }, end_time: undefined });
  });
  it("can open a new campaign and start at once", () => {
    const plan = launchPlan(src, [{ name: "Mix", targeting: {} }], { dailyBudget: 10, currency: "USD", days: 3, startNow: true, newCampaignName: "Audiences" }, new Date("2026-11-14T00:00:00Z"));
    expect(plan.campaign).toMatchObject({ name: "Audiences", objective: "OUTCOME_LEADS", status: "ACTIVE" });
    expect(plan.adSets[0]).toMatchObject({ status: "ACTIVE", daily_budget: 1000, end_time: "2026-11-17T00:00:00.000Z" });
  });
});
