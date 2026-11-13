import { describe, expect, it } from "vitest";
import { advise, advicePrompt, benchmarks, exactStats, parseAiAdvice, type History, type HistorySet } from "@/lib/interest-advice";

const I = (value: string) => ({ type: "interest", value, externalId: `id-${value}` });
const set = (id: string, interests: string[], spend: number, results: number, o: Partial<HistorySet> = {}): HistorySet => ({
  id, name: `Set ${id}`, accountId: "a", campaignId: "c1", campaignName: "Leads", objective: "LEADS", interests: interests.map(I),
  widened: false, spend, results, firstDate: "2026-09-01", lastDate: "2026-11-01", ...o,
});
const h: History = {
  currency: "USD", today: "2026-11-13",
  sets: [
    set("1", ["Real estate"], 500, 50),            // 10 each — winner
    set("2", ["Luxury cars"], 900, 30),            // 30 each — loser
    set("3", ["Golf"], 400, 0),                    // dry
    set("4", ["Yachts"], 60, 3),                   // thin
    set("5", ["Travel", "Hotels", "Real estate"], 1000, 50), // mixed, 20 each
    set("6", ["Travel", "Fitness"], 600, 20),      // mixed, 30 each
    set("7", ["Old winner"], 300, 40, { lastDate: "2026-05-01" }),
  ],
};
const by = (s: ReturnType<typeof advise>) => new Map(s.map((x) => [x.value, x]));

describe("interest advice from history", () => {
  it("counts an interest's result only from ad sets where it ran alone", () => {
    const e = exactStats(h);
    expect(e.get("interest|real estate")).toMatchObject({ spend: 500, results: 50, cpr: 10, adSets: 1 });
    expect(e.has("interest|travel")).toBe(false);
    expect(benchmarks(h).all).toBeCloseTo(3760 / 193);
  });

  it("sorts interests into scale, stop, retest and test", () => {
    const s = by(advise(h));
    expect(s.get("Real estate")!.action).toBe("scale");
    expect(s.get("Luxury cars")!.action).toBe("stop");
    expect(s.get("Golf")!.action).toBe("stop");
    expect(s.get("Golf")!.reason).toMatch(/no result/);
    expect(s.get("Yachts")!.action).toBe("retest");
    expect(s.get("Old winner")!.action).toBe("retest");
    expect(s.get("Travel")!.action).toBe("test");
  });

  it("never gives a never-isolated interest a number of its own", () => {
    const travel = by(advise(h)).get("Travel")!;
    expect([travel.spend, travel.results, travel.cpr]).toEqual([null, null, null]);
    expect(travel.reason).toMatch(/Never run alone/);
    expect(travel.sourceAdSetId).toBe("5");
  });

  it("inside a campaign, offers winners proven elsewhere and hides other campaigns' losers", () => {
    const two: History = { ...h, sets: [...h.sets, set("8", ["Cooking", "Baking"], 200, 5, { campaignId: "c2" })] };
    const s = by(advise(two, { campaignId: "c2" }));
    expect(s.get("Real estate")!.action).toBe("reuse");
    expect(s.has("Luxury cars")).toBe(false);
    expect(s.get("Cooking")!.action).toBe("test");
  });

  it("builds a prompt that keeps mixed ad sets' results on the ad set", () => {
    const p = JSON.parse(advicePrompt(h, { language: "Arabic" }));
    expect(p.language).toBe("Arabic");
    expect(p.exact.map((x: { interest: string }) => x.interest)).toContain("Real estate");
    expect(p.mixed[0]).toMatchObject({ interests: ["Travel", "Hotels", "Real estate"], results: 50 });
  });

  it("reads an AI answer, matching known interests and marking the rest as new ideas", () => {
    const a = parseAiAdvice('Here you go:\n{"summary":"Scale real estate.","suggestions":[{"interest":"real estate","action":"scale","reason":"cheap","priority":1},{"interest":"Interior design","action":"scale","reason":"related","priority":2},{"interest":"","action":"stop"}]}', h);
    expect(a.summary).toBe("Scale real estate.");
    expect(a.suggestions).toHaveLength(2);
    expect(a.suggestions[0]).toMatchObject({ value: "Real estate", externalId: "id-Real estate", action: "scale", results: 50 });
    expect(a.suggestions[1]).toMatchObject({ value: "Interior design", externalId: "", action: "new", results: null });
    expect(() => parseAiAdvice("sorry, no", h)).toThrow();
  });
});
