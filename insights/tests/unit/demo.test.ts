import { describe, expect, it } from "vitest";
import { buildStats, buildStructure, splitInt } from "@/platforms/demo";

describe("splitInt", () => {
  it("keeps the total exact", () => {
    for (const total of [0, 1, 7, 1000, 12345]) {
      const parts = splitInt(total, [0.3, 1.7, 2.2, 0.01]);
      expect(parts.reduce((a, b) => a + b, 0)).toBe(total);
    }
  });
});

describe("demo connector", () => {
  it("is deterministic", () => {
    expect(buildStructure("META", "acc-1")).toEqual(buildStructure("META", "acc-1"));
    expect(buildStats("GOOGLE", "acc-2", "2026-01-01", "2026-01-03").stats).toEqual(buildStats("GOOGLE", "acc-2", "2026-01-01", "2026-01-03").stats);
  });

  it("rolls ad rows up to campaigns and the account exactly", () => {
    const { stats } = buildStats("META", "acc-3", "2026-01-01", "2026-01-01");
    const sum = (lvl: string) => stats.filter((s) => s.level === lvl).reduce((a, s) => a + (s.impressions ?? 0), 0);
    expect(sum("AD")).toBe(sum("ACCOUNT"));
    expect(sum("CAMPAIGN")).toBe(sum("ACCOUNT"));
  });

  it("breakdown buckets add up to the campaign", () => {
    const { stats, breakdowns } = buildStats("TIKTOK", "acc-4", "2026-01-05", "2026-01-05");
    const camp = stats.find((s) => s.level === "CAMPAIGN")!;
    const age = breakdowns.filter((b) => b.externalId === camp.externalId && b.dimension === "age");
    expect(age.reduce((a, b) => a + (b.impressions ?? 0), 0)).toBe(camp.impressions);
  });

  it("produces Google keywords, search terms and placements", () => {
    const b = buildStats("GOOGLE", "acc-5", "2026-01-01", "2026-01-02");
    expect((b.keywords ?? []).length + (b.placements ?? []).length).toBeGreaterThan(0);
  });
});
