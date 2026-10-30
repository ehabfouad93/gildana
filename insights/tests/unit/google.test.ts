import { describe, expect, it } from "vitest";
import { adCopy, criterionItem, gMetrics } from "@/platforms/google/normalize";

describe("google normalize", () => {
  it("converts micros and counts conversions as leads", () => {
    expect(gMetrics({ costMicros: "12500000", impressions: "100", clicks: "7", conversions: 2.6, conversionsValue: 40 }))
      .toMatchObject({ spend: 12.5, impressions: 100, clicks: 7, conversions: 2.6, leads: 3, value: 40 });
  });

  it("maps criteria to targeting items", () => {
    expect(criterionItem({ type: "KEYWORD", criterionId: "1", keyword: { text: "villas cairo", matchType: "PHRASE" } })).toMatchObject({ type: "keyword", value: "villas cairo" });
    expect(criterionItem({ type: "AGE_RANGE", ageRange: { type: "AGE_RANGE_25_34" } })).toMatchObject({ type: "age", value: "25-34" });
    expect(criterionItem({ type: "USER_LIST", displayName: "Converters 30d", negative: true })).toMatchObject({ type: "audience", exclude: true });
    expect(criterionItem({ type: "LOCATION", displayName: "Cairo, Egypt" })).toMatchObject({ type: "geo", value: "Cairo, Egypt" });
    expect(criterionItem({ type: "UNKNOWN_THING", displayName: "x" })).toBeNull();
  });

  it("extracts responsive search ad copy", () => {
    const c = adCopy({ responsiveSearchAd: { headlines: [{ text: "A" }, { text: "B" }, { text: "C" }, { text: "D" }], descriptions: [{ text: "x" }, { text: "y" }] } });
    expect(c).toEqual({ headline: "A | B | C", body: "x | y", format: "responsive_search" });
  });
});
