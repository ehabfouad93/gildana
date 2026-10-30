import { describe, expect, it } from "vitest";
import { liMetrics, liTargeting, liValue } from "@/platforms/linkedin";
import { snapMetrics, snapTargeting } from "@/platforms/snapchat";
import { ttAge, ttMetrics, ttTargeting } from "@/platforms/tiktok";

describe("linkedin", () => {
  it("flattens targeting criteria with exclusions", () => {
    const items = liTargeting({
      include: { and: [{ or: { "urn:li:adTargetingFacet:locations": ["urn:li:geo:106155005"] } }, { or: { "urn:li:adTargetingFacet:titles": ["urn:li:title:9"], "urn:li:adTargetingFacet:seniorities": ["urn:li:seniority:6"] } }] },
      exclude: { or: { "urn:li:adTargetingFacet:staffCountRanges": ["urn:li:staffCountRange:(1,1)"] } },
    });
    expect(items).toContainEqual({ type: "geo", value: "urn:li:geo:106155005", externalId: "urn:li:geo:106155005", exclude: false });
    expect(items.find((i) => i.type === "seniority")?.value).toBe("Director");
    expect(items.find((i) => i.type === "company_size")).toMatchObject({ value: "1", exclude: true });
    expect(liValue("urn:li:staffCountRange:(51,200)")).toBe("51-200");
  });

  it("counts one-click leads as leads and conversions", () => {
    expect(liMetrics({ costInLocalCurrency: "40.5", impressions: 1000, clicks: 9, oneClickLeads: 3, externalWebsiteConversions: 1 }))
      .toMatchObject({ spend: 40.5, leads: 3, conversions: 4 });
  });
});

describe("snapchat", () => {
  it("converts micro spend and swipes", () => {
    expect(snapMetrics({ spend: 25_000_000, impressions: 5000, swipes: 40, conversion_sign_ups: 2 })).toMatchObject({ spend: 25, clicks: 40, leads: 2 });
  });
  it("flattens ad squad targeting", () => {
    const t = snapTargeting({ demographics: [{ min_age: 18, max_age: 34, gender: "FEMALE" }], geos: [{ country_code: "sa" }], interests: [{ category_id: ["SLC_1"] }] });
    expect(t).toEqual(expect.arrayContaining([{ type: "age", value: "18-34" }, { type: "gender", value: "female" }, { type: "geo", value: "SA" }, expect.objectContaining({ type: "interest", value: "SLC_1" })]));
  });
});

describe("tiktok", () => {
  it("maps metrics and age buckets", () => {
    expect(ttMetrics({ spend: "12.3", impressions: "900", clicks: "11", conversion: "3", form: "2" })).toMatchObject({ spend: 12.3, conversions: 3, leads: 2 });
    expect(ttAge("AGE_25_34")).toBe("25-34");
  });
  it("flattens ad group targeting", () => {
    const t = ttTargeting({ age_groups: ["AGE_18_24"], gender: "GENDER_FEMALE", location_ids: ["6252001"], interest_category_ids: ["10"], excluded_audience_ids: ["77"] });
    expect(t).toEqual(expect.arrayContaining([{ type: "age", value: "18-24" }, { type: "gender", value: "Female" }, expect.objectContaining({ type: "audience", exclude: true })]));
  });
});
