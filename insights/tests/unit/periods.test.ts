import { describe, expect, it } from "vitest";
import { periodRange, rangeFromParams } from "@/lib/dates";

describe("named periods", () => {
  it("covers whole calendar months and years", () => {
    expect(periodRange("last_month", "2026-03-15")).toEqual({ from: "2026-02-01", to: "2026-02-28" });
    expect(periodRange("last_month", "2026-01-10")).toEqual({ from: "2025-12-01", to: "2025-12-31" });
    expect(periodRange("this_month", "2026-03-15")).toEqual({ from: "2026-03-01", to: "2026-03-14" });
    expect(periodRange("this_month", "2026-03-01")).toEqual({ from: "2026-02-28", to: "2026-02-28" });
    expect(periodRange("last_year", "2026-06-01")).toEqual({ from: "2025-01-01", to: "2025-12-31" });
    expect(periodRange("nonsense")).toBeNull();
  });
  it("prefers a custom range, then a period, then days", () => {
    expect(rangeFromParams({ from: "2026-01-05", to: "2026-01-20", period: "last_month" })).toEqual({ from: "2026-01-05", to: "2026-01-20" });
    expect(rangeFromParams({ period: "last_year" }).from.endsWith("-01-01")).toBe(true);
  });
});
