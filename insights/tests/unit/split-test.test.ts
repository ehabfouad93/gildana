import { describe, expect, it } from "vitest";
import { buildStats, buildStructure } from "@/platforms/demo";
import { breakdownValue } from "@/platforms/meta/normalize";
import { armTargeting, splitBudget } from "@/platforms/meta/split-test";

describe("meta split test", () => {
  const src = {
    age_min: 25, age_max: 45, geo_locations: { countries: ["EG"] }, publisher_platforms: ["instagram"],
    flexible_spec: [{ interests: [{ id: "1", name: "Real estate" }, { id: "2", name: "Travel" }], behaviors: [{ id: "9", name: "Expats" }] }],
    custom_audiences: [{ id: "ca1" }],
  };
  it("keeps everything but the detailed targeting, which becomes one interest", () => {
    const t = armTargeting(src, { type: "interest", value: "Travel", externalId: "2" });
    expect(t.flexible_spec).toEqual([{ interests: [{ id: "2", name: "Travel" }] }]);
    expect(t.age_min).toBe(25);
    expect(t.geo_locations).toEqual({ countries: ["EG"] });
    expect(t.custom_audiences).toEqual([{ id: "ca1" }]);
    expect(src.flexible_spec[0].interests).toHaveLength(2); // source untouched
    expect(armTargeting(src, { type: "behavior", value: "Expats", externalId: "9" }).flexible_spec).toEqual([{ behaviors: [{ id: "9", name: "Expats" }] }]);
  });
  it("splits a budget exactly", () => {
    expect(splitBudget(10000, 3)).toEqual([3334, 3333, 3333]);
    expect(splitBudget(10000, 3).reduce((a, b) => a + b)).toBe(10000);
  });
});

describe("meta breakdown values", () => {
  it("maps customer segments and creative assets", () => {
    expect(breakdownValue("customer_segment", { user_segment_key: "existing_users" })).toBe("Existing customers");
    expect(breakdownValue("asset_title", { title_asset: { text: "Book a visit", id: "1" } })).toBe("Book a visit");
    expect(breakdownValue("asset_video", { video_asset: { video_id: "7", video_name: "Tour" } })).toBe("Tour");
    expect(breakdownValue("frequency", { frequency_value: "4-5" })).toBe("4-5");
  });
});

describe("demo reported segments and split tests", () => {
  it("reports TikTok interests per ad group, adding up to the ad group", () => {
    const { stats, breakdowns } = buildStats("TIKTOK", "acc-x", "2026-01-01", "2026-01-01");
    const seg = breakdowns!.filter((b) => b.level === "ADSET" && b.dimension === "seg_interest");
    expect(seg.length).toBeGreaterThan(0);
    const set = seg[0].externalId;
    const total = stats.find((s) => s.level === "ADSET" && s.externalId === set)!;
    expect(seg.filter((b) => b.externalId === set).reduce((a, b) => a + (b.conversions ?? 0), 0)).toBe(total.conversions);
  });
  it("adds split-test arms as ad sets with one interest each", () => {
    const base = buildStructure("META", "acc-y");
    const src = base.adSets.find((s) => s.targeting.filter((t) => t.type === "interest").length >= 2)!;
    const interests = src.targeting.filter((t) => t.type === "interest");
    const built = buildStructure("META", "acc-y", [{ sourceAdSet: src.externalId, arms: interests.map((i, k) => ({ externalId: `${src.externalId}-split${k}`, interest: i })) }]);
    const arms = built.adSets.filter((s) => s.externalId.startsWith(`${src.externalId}-split`));
    expect(arms).toHaveLength(interests.length);
    expect(arms.every((a) => a.targeting.filter((t) => t.type === "interest").length === 1)).toBe(true);
    expect(built.adSets.find((s) => s.externalId === src.externalId)!.status).toBe("PAUSED");
  });
});

describe("split-test campaign", () => {
  it("plans one paused ad set per interest with its own equal budget and Advantage+ audience off", async () => {
    const { splitCampaignPlan, toMinor } = await import("@/platforms/meta/split-test");
    const src = {
      name: "Prospecting", billing_event: "IMPRESSIONS", optimization_goal: "LEAD_GENERATION", bid_strategy: "LOWEST_COST_WITHOUT_CAP", promoted_object: { page_id: "1" },
      targeting: { geo_locations: { countries: ["EG"] }, age_min: 25, flexible_spec: [{ interests: [{ id: "1", name: "A" }, { id: "2", name: "B" }] }], targeting_automation: { advantage_audience: 1 } },
      campaign: { objective: "OUTCOME_LEADS", special_ad_categories: ["HOUSING"], buying_type: "AUCTION" },
    };
    const items = [{ type: "interest", value: "A", externalId: "1" }, { type: "behavior", value: "Expats", externalId: "9" }];
    const plan = splitCampaignPlan(src, items, { name: "Test", dailyBudget: 300, currency: "EGP", days: 7, startNow: false }, new Date("2026-11-13T00:00:00Z"));
    expect(plan.campaign).toMatchObject({ name: "Test", objective: "OUTCOME_LEADS", status: "PAUSED", special_ad_categories: ["HOUSING"], is_adset_budget_sharing_enabled: false });
    expect(plan.adSets).toHaveLength(2);
    expect(plan.adSets[0]).toMatchObject({ name: "A", status: "PAUSED", daily_budget: 30000, optimization_goal: "LEAD_GENERATION", end_time: "2026-11-20T00:00:00.000Z" });
    expect(plan.adSets[0].targeting.flexible_spec).toEqual([{ interests: [{ id: "1", name: "A" }] }]);
    expect(plan.adSets[1].targeting.flexible_spec).toEqual([{ behaviors: [{ id: "9", name: "Expats" }] }]);
    expect(plan.adSets[0].targeting.targeting_automation.advantage_audience).toBe(0);
    expect(plan.adSets[0].targeting.geo_locations).toEqual({ countries: ["EG"] });
    // "Start now" is the only way anything is created active; no end date when days = 0.
    const live = splitCampaignPlan(src, items, { name: "Test", dailyBudget: 300, currency: "JPY", days: 0, startNow: true });
    expect(live.campaign.status).toBe("ACTIVE");
    expect(live.adSets[0]).toMatchObject({ status: "ACTIVE", daily_budget: 300, end_time: undefined });
    expect(toMinor(12.5, "USD")).toBe(1250);
  });
});

describe("split test verdict", () => {
  it("waits until every interest had a fair chance, then names a clear winner and the losers", async () => {
    const { splitTestStatus } = await import("@/lib/interest-advice");
    const arm = (value: string, spend: number, results: number) => ({ id: value, value, spend, results });
    expect(splitTestStatus([arm("A", 20, 3), arm("B", 25, 4)]).state).toBe("too_early");
    // B hasn't reached 10 results or spent 3× A's cost per result yet.
    expect(splitTestStatus([arm("A", 100, 20), arm("B", 10, 1)]).state).toBe("too_early");
    const done = splitTestStatus([arm("A", 100, 20), arm("B", 150, 12), arm("C", 40, 0)]);
    expect(done).toMatchObject({ state: "winner", winnerId: "A" });
    expect(done.loserIds.sort()).toEqual(["B", "C"]);
    expect(splitTestStatus([arm("A", 100, 20), arm("B", 110, 20)]).state).toBe("no_clear_winner");
  });
});
