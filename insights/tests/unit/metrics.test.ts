import { describe, expect, it } from "vitest";
import { ZERO, add, delta, derive } from "@/lib/metrics";
import { addDays, daysBetween, eachDay, previousRange, rangeFromParams } from "@/lib/dates";
import { convert } from "@/lib/fx";

describe("derive", () => {
  it("computes ratios from summed counts", () => {
    const m = derive({ ...ZERO, spend: 100, impressions: 20_000, clicks: 400, conversions: 20, leads: 10, value: 300, reach: 10_000 });
    expect(m.ctr).toBeCloseTo(2);
    expect(m.cpc).toBeCloseTo(0.25);
    expect(m.cpm).toBeCloseTo(5);
    expect(m.cpl).toBeCloseTo(10);
    expect(m.cpa).toBeCloseTo(5);
    expect(m.roas).toBeCloseTo(3);
    expect(m.frequency).toBeCloseTo(2);
  });

  it("returns null instead of dividing by zero", () => {
    const m = derive({ ...ZERO });
    expect(m.ctr).toBeNull();
    expect(m.cpl).toBeNull();
    expect(m.roas).toBeNull();
  });

  it("does not average ratios when adding days", () => {
    // day 1: 1 click / 100 impr (1%), day 2: 90 clicks / 900 impr (10%) → 9.1%, not 5.5%
    const t = add(add({ ...ZERO }, { impressions: 100, clicks: 1 }), { impressions: 900, clicks: 90 });
    expect(derive(t).ctr).toBeCloseTo(9.1);
  });

  it("computes period-over-period change", () => {
    expect(delta(120, 100)).toBeCloseTo(20);
    expect(delta(1, 0)).toBeNull();
  });
});

describe("dates", () => {
  it("builds inclusive ranges and the previous window", () => {
    const r = { from: "2026-01-01", to: "2026-01-10" };
    expect(daysBetween(r)).toBe(10);
    expect(previousRange(r)).toEqual({ from: "2025-12-22", to: "2025-12-31" });
    expect(eachDay({ from: "2026-02-27", to: "2026-03-01" })).toEqual(["2026-02-27", "2026-02-28", "2026-03-01"]);
    expect(addDays("2026-12-31", 1)).toBe("2027-01-01");
  });

  it("rejects malformed params", () => {
    expect(daysBetween(rangeFromParams({ from: "2026-02-10", to: "2026-01-01" }))).toBe(30);
    expect(daysBetween(rangeFromParams({ days: "7" }))).toBe(7);
    expect(daysBetween(rangeFromParams({ days: "99999" }))).toBe(1100);
  });
});

describe("fx", () => {
  it("converts through USD", () => {
    const t = { USD: 1, EGP: 50, SAR: 3.75 };
    expect(convert(100, "EGP", "USD", t)).toBeCloseTo(2);
    expect(convert(375, "SAR", "EGP", t)).toBeCloseTo(5000);
    expect(convert(10, "XXX", "USD", t)).toBe(10);
  });
});
