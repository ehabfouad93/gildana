import { describe, expect, it } from "vitest";
import { estimateInterests, nnlsRidge } from "@/lib/attribution";

describe("nnlsRidge", () => {
  it("solves a simple system without going negative", () => {
    const b = nnlsRidge([[1, 0], [0, 1], [1, 1]], [2, 3, 5], 0);
    expect(b[0]).toBeCloseTo(2, 3);
    expect(b[1]).toBeCloseTo(3, 3);
    expect(nnlsRidge([[1], [1]], [-1, -2], 0)[0]).toBe(0);
  });
});

describe("estimateInterests", () => {
  // True cost per conversion: A = 5, B = 10, C = 20.
  const rate: Record<string, number> = { A: 1 / 5, B: 1 / 10, C: 1 / 20 };
  const mk = (id: string, spend: number, interests: string[]) => ({ id, spend, interests, conversions: interests.reduce((a, n) => a + (spend / interests.length) * rate[n], 0) });
  const sets = [mk("1", 1000, ["A", "B"]), mk("2", 800, ["B", "C"]), mk("3", 1200, ["A", "C"]), mk("4", 600, ["A", "B", "C"]), mk("5", 900, ["B"])];

  it("recovers per-interest cost per conversion from mixed ad sets", () => {
    const { estimates } = estimateInterests(sets);
    const cpa = Object.fromEntries(estimates.map((e) => [e.interest, e.cpa]));
    expect(cpa.A).toBeCloseTo(5, 0);
    expect(cpa.B).toBeCloseTo(10, 0);
    expect(cpa.C).toBeCloseTo(20, 0);
    expect(estimates.every((e) => e.identifiable)).toBe(true);
  });

  it("splits an ad set's conversions by contribution", () => {
    const { weights } = estimateInterests(sets);
    const w = weights.get("1")!;
    expect(w.get("A")! + w.get("B")!).toBeCloseTo(1, 6);
    expect(w.get("A")!).toBeGreaterThan(w.get("B")!);
  });

  it("refuses to separate interests that always travel together", () => {
    const { estimates } = estimateInterests([mk("1", 500, ["A", "B"]), mk("2", 700, ["A", "B"]), mk("3", 400, ["A", "B"])]);
    expect(estimates.find((e) => e.interest === "A")!.identifiable).toBe(false);
  });
});
