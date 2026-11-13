import { describe, expect, it } from "vitest";
import { byCreative, byElement, fatigue, type AdDay, type AdInput } from "@/lib/creative-insights";

const day = (i: number, impressions: number, clicks: number, spend: number, results: number, reach = impressions / 1.2): AdDay => ({ date: `2026-10-${String(i + 1).padStart(2, "0")}`, impressions, clicks, spend, results, reach });
const ad = (id: string, o: Partial<AdInput> = {}): AdInput => ({
  id, name: `Ad ${id}`, status: "ACTIVE", platform: "META", format: "image", creativeExternalId: "", headline: "Own it", body: "Text", cta: "LEARN_MORE", thumbUrl: "x.png",
  adSetId: `s${id}`, adSetName: "Set", campaignId: "c1", campaignName: "Camp", spend: 100, impressions: 10000, reach: 8000, clicks: 100, results: 10, days: [], ...o,
});

describe("creative fatigue", () => {
  it("needs enough days before judging", () => {
    expect(fatigue([day(0, 5000, 50, 20, 2)]).level).toBe("new");
  });
  it("is fresh when the trend holds", () => {
    expect(fatigue(Array.from({ length: 14 }, (_, i) => day(i, 1000, 12, 10, 2))).level).toBe("fresh");
  });
  it("is tired when click rate falls and cost per result rises", () => {
    const days = [...Array.from({ length: 7 }, (_, i) => day(i, 1000, 20, 10, 4)), ...Array.from({ length: 7 }, (_, i) => day(i + 7, 1000, 10, 10, 2))];
    const f = fatigue(days);
    expect(f.level).toBe("tired");
    expect(f.ctrChange).toBeCloseTo(-0.5);
    expect(f.costChange).toBeCloseTo(1);
  });
  it("is on watch when only the click rate slips", () => {
    const days = [...Array.from({ length: 7 }, (_, i) => day(i, 1000, 20, 10, 4)), ...Array.from({ length: 7 }, (_, i) => day(i + 7, 1000, 16, 10, 4))];
    expect(fatigue(days).level).toBe("watch");
  });
});

describe("creative roll-ups", () => {
  it("counts one creative together across ad sets", () => {
    const rows = byCreative([ad("1", { creativeExternalId: "cr1" }), ad("2", { creativeExternalId: "cr1", spend: 50, results: 10 }), ad("3", { creativeExternalId: "cr2" })]);
    expect(rows).toHaveLength(2);
    expect(rows[0]).toMatchObject({ ads: 2, adSets: 2, spend: 150, results: 20, cpr: 7.5 });
  });
  it("falls back to headline + text + image when there is no creative id", () => {
    expect(byCreative([ad("1"), ad("2"), ad("3", { headline: "Other" })])).toHaveLength(2);
  });
  it("ranks headlines by their combined results", () => {
    const rows = byElement([ad("1"), ad("2", { headline: "own it" }), ad("3", { headline: "Call now", spend: 10, results: 5 })], "headline");
    expect(rows[0]).toMatchObject({ value: "Own it", ads: 2, spend: 200, results: 20 });
    expect(rows[1]).toMatchObject({ value: "Call now", cpr: 2 });
  });
});
