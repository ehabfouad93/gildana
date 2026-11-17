import { describe, expect, it } from "vitest";
import { GOALS, buildCampaignPlan, describe as say, validate, type CampaignSpec } from "@/platforms/meta/new-campaign";

const base = (o: Partial<CampaignSpec> = {}): CampaignSpec => ({
  name: "Porto Golf – Leads", goal: "leads_form", pageId: "pg1", formId: "form1", special: "", budget: 500, budgetType: "daily", campaignBudget: false,
  start: "2026-11-20", end: "2026-11-30", countries: ["EG"], ageMin: 25, ageMax: 55, gender: "all", groups: [], exclusions: [], advantageAudience: false,
  placements: "auto", startNow: false, currency: "EGP",
  ads: [{ name: "Ad 1", source: "new", headline: "Own your home", text: "Pay over 8 years", imageHash: "h1" }], ...o,
});

describe("new campaign: goals", () => {
  it("maps every goal to Meta's objective, optimisation and destination", () => {
    const pick = (goal: CampaignSpec["goal"], o: Partial<CampaignSpec> = {}) => buildCampaignPlan(base({ goal, url: "https://x.test", pixelId: "px", ...o }));
    expect(pick("leads_form").campaign.objective).toBe("OUTCOME_LEADS");
    expect(pick("leads_form").adSet).toMatchObject({ optimization_goal: "LEAD_GENERATION", destination_type: "ON_AD", promoted_object: { page_id: "pg1" } });
    expect(pick("whatsapp", { whatsappNumber: "+20100" }).adSet).toMatchObject({ optimization_goal: "CONVERSATIONS", destination_type: "WHATSAPP", promoted_object: { page_id: "pg1", whatsapp_phone_number: "+20100" } });
    expect(pick("messenger").adSet).toMatchObject({ destination_type: "MESSENGER" });
    expect(pick("traffic").campaign.objective).toBe("OUTCOME_TRAFFIC");
    expect(pick("traffic").adSet.promoted_object).toBeUndefined();
    expect(pick("website_leads").adSet).toMatchObject({ optimization_goal: "OFFSITE_CONVERSIONS", promoted_object: { pixel_id: "px", custom_event_type: "LEAD" }, destination_type: "WEBSITE" });
    expect(pick("awareness").campaign.objective).toBe("OUTCOME_AWARENESS");
    expect(pick("video_views").adSet.optimization_goal).toBe("THRUPLAY");
    expect(pick("post_engagement").adSet.optimization_goal).toBe("POST_ENGAGEMENT");
    expect(Object.keys(GOALS)).toHaveLength(8);
  });
});

describe("new campaign: budget, schedule, status", () => {
  it("puts a daily budget on the ad set in minor units, paused by default", () => {
    const p = buildCampaignPlan(base());
    expect(p.campaign).toMatchObject({ status: "PAUSED", special_ad_categories: [], is_adset_budget_sharing_enabled: false });
    expect(p.adSet).toMatchObject({ status: "PAUSED", daily_budget: 50000, start_time: "2026-11-20T00:00:00.000Z", end_time: "2026-11-30T00:00:00.000Z" });
    expect(p.ads[0].status).toBe("PAUSED");
  });
  it("can put a lifetime budget on the campaign and start now", () => {
    const p = buildCampaignPlan(base({ budgetType: "lifetime", campaignBudget: true, startNow: true, currency: "USD", budget: 1000 }));
    expect(p.campaign).toMatchObject({ status: "ACTIVE", lifetime_budget: 100000 });
    expect(p.adSet.lifetime_budget).toBeUndefined();
  });
  it("sets the special ad category with its country", () => {
    expect(buildCampaignPlan(base({ special: "HOUSING", ageMin: 18, ageMax: 65 })).campaign).toMatchObject({ special_ad_categories: ["HOUSING"], special_ad_category_country: ["EG"] });
  });
});

describe("new campaign: targeting", () => {
  it("builds location, age, gender, audience groups, exclusions and placements", () => {
    const t = buildCampaignPlan(base({
      gender: "female", cities: [{ key: "123", name: "Cairo", radius: 25 }],
      groups: [[{ type: "interest", value: "Real estate", externalId: "1" }]], exclusions: [{ type: "audience", value: "Customers", externalId: "77" }],
      placements: { facebook: ["feed"], instagram: ["stream", "story"] },
    })).adSet.targeting as any;
    expect(t.geo_locations).toEqual({ countries: ["EG"], cities: [{ key: "123", radius: 25, distance_unit: "kilometer" }] });
    expect(t).toMatchObject({ age_min: 25, age_max: 55, genders: [2], publisher_platforms: ["facebook", "instagram"], facebook_positions: ["feed"], instagram_positions: ["stream", "story"] });
    expect(t.flexible_spec).toEqual([{ interests: [{ id: "1", name: "Real estate" }] }]);
    expect(t.excluded_custom_audiences).toEqual([{ id: "77" }]);
    expect(t.targeting_automation.advantage_audience).toBe(0);
  });
  it("Advantage+ audience is never on with a special ad category", () => {
    expect((buildCampaignPlan(base({ advantageAudience: true })).adSet.targeting as any).targeting_automation.advantage_audience).toBe(1);
    expect((buildCampaignPlan(base({ advantageAudience: true, special: "HOUSING", ageMin: 18, ageMax: 65 })).adSet.targeting as any).targeting_automation.advantage_audience).toBe(0);
  });
});

describe("new campaign: ads", () => {
  it("builds an image lead ad with the form on the button", () => {
    const c = buildCampaignPlan(base()).ads[0].creative as any;
    expect(c.object_story_spec.page_id).toBe("pg1");
    expect(c.object_story_spec.link_data).toMatchObject({ image_hash: "h1", name: "Own your home", message: "Pay over 8 years", call_to_action: { type: "SIGN_UP", value: { lead_gen_form_id: "form1" } } });
  });
  it("builds a WhatsApp video ad, an existing post and a reused ad", () => {
    const p = buildCampaignPlan(base({ goal: "whatsapp", ads: [
      { name: "V", source: "new", videoId: "v1", text: "Chat with us", thumbnailUrl: "https://t.test/a.jpg" },
      { name: "P", source: "post", postId: "pg1_99" },
      { name: "IG", source: "post", postId: "ig55", instagram: true },
      { name: "C", source: "copy", creativeId: "cr9" },
    ] }));
    expect((p.ads[0].creative as any).object_story_spec.video_data).toMatchObject({ video_id: "v1", message: "Chat with us", image_url: "https://t.test/a.jpg", call_to_action: { type: "WHATSAPP_MESSAGE", value: { app_destination: "WHATSAPP" } } });
    expect(p.ads[1].creative).toEqual({ name: "P", object_story_id: "pg1_99" });
    expect(p.ads[2].creative).toMatchObject({ source_instagram_media_id: "ig55", object_id: "pg1" });
    expect(p.ads[3].creative).toEqual({ creative_id: "cr9" });
  });
});

describe("new campaign: validation and summary", () => {
  it("lists what is missing in words", () => {
    expect(validate(base())).toEqual([]);
    const p = validate(base({ formId: undefined, budget: 0, countries: [], ads: [{ name: "A", source: "new" }] }));
    expect(p).toEqual(expect.arrayContaining(["Choose the lead form.", "Enter a budget.", "Choose at least one location.", "Ad 1: add an image or a video.", "Ad 1: write the main text."]));
    expect(validate(base({ special: "HOUSING" }))[0]).toMatch(/18–65/);
    expect(validate(base({ goal: "traffic", url: "nope" }))).toContain("Enter the website address (https://…).");
    expect(validate(base({ budgetType: "lifetime", end: undefined }))).toContain("A lifetime budget needs an end date.");
  });
  it("describes the campaign for the review step and the client", () => {
    const lines = say(base({ groups: [[{ type: "interest", value: "Golf", externalId: "1" }]] }), "Porto", (n) => `EGP ${n}`);
    expect(lines[0]).toContain("Leads — instant form");
    expect(lines[1]).toContain("interested in Golf");
    expect(lines[2]).toBe("Budget: EGP 500 per day for 10 days — up to EGP 5000, from 2026-11-20 to 2026-11-30");
    expect(lines.at(-1)).toMatch(/paused/);
  });
});

describe("copy an existing campaign", () => {
  it("recognises the goal of old and new objectives", async () => {
    const { goalOf } = await import("@/platforms/meta/new-campaign");
    expect(goalOf("OUTCOME_LEADS", "LEAD_GENERATION")).toBe("leads_form");
    expect(goalOf("OUTCOME_LEADS", "OFFSITE_CONVERSIONS")).toBe("website_leads");
    expect(goalOf("OUTCOME_ENGAGEMENT", "CONVERSATIONS", "WHATSAPP")).toBe("whatsapp");
    expect(goalOf("MESSAGES", "CONVERSATIONS", "MESSENGER")).toBe("messenger");
    expect(goalOf("OUTCOME_ENGAGEMENT", "THRUPLAY")).toBe("video_views");
    expect(goalOf("LINK_CLICKS")).toBe("traffic");
    expect(goalOf("OUTCOME_AWARENESS")).toBe("awareness");
  });
  it("prefills budget, location, age, audience, placements and reuses the ads", async () => {
    const { specFromExisting } = await import("@/platforms/meta/new-campaign");
    const s = specFromExisting({
      name: "Porto Golf", objective: "OUTCOME_LEADS", dailyBudget: null, totalBudget: null,
      adSet: { optimizationGoal: "LEAD_GENERATION", dailyBudget: 300, promoted: { page_id: "pg1" }, targetingRaw: {
        geo_locations: { countries: ["EG"], cities: [{ key: "9", name: "Cairo", radius: 20 }] }, age_min: 28, age_max: 60, genders: [1],
        flexible_spec: [{ interests: [{ id: "1", name: "Golf" }] }], excluded_custom_audiences: [{ id: "77", name: "Customers" }],
        publisher_platforms: ["facebook", "instagram"], facebook_positions: ["feed"], targeting_automation: { advantage_audience: 0 },
      } },
      ads: [{ name: "Ad A", creativeExternalId: "cr1", headline: "H" }, { name: "No creative", creativeExternalId: "", headline: "" }],
    });
    expect(s).toMatchObject({ name: "Porto Golf – copy", goal: "leads_form", pageId: "pg1", budget: 300, budgetType: "daily", campaignBudget: false, countries: ["EG"], ageMin: 28, ageMax: 60, gender: "male", advantageAudience: false });
    expect(s.cities).toEqual([{ key: "9", name: "Cairo", radius: 20 }]);
    expect(s.groups).toEqual([[{ type: "interest", value: "Golf", externalId: "1" }]]);
    expect(s.exclusions).toEqual([{ type: "audience", value: "Customers", externalId: "77" }]);
    expect(s.placements).toMatchObject({ facebook: ["feed"], instagram: ["stream", "story", "reels", "explore", "explore_home"] });
    expect(s.ads).toEqual([{ name: "Ad A", source: "copy", creativeId: "cr1", headline: "H" }]);
  });
});
