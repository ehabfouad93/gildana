import { describe, expect, it } from "vitest";
import { PRESETS, audit, evaluateRule, windowOf, type Ent } from "@/lib/optimise";

const TODAY = "2026-11-14";
const days = (n: number, spend: number, results: number, o: { clicks?: number; impressions?: number } = {}) =>
  Array.from({ length: n }, (_, i) => ({ date: new Date(Date.parse(TODAY) - (n - 1 - i) * 86_400_000).toISOString().slice(0, 10), spend, results, impressions: o.impressions ?? 1000, reach: 800, clicks: o.clicks ?? 10 }));
const set = (id: string, d: ReturnType<typeof days>, o: Partial<Ent> = {}): Ent => ({ id, name: `Set ${id}`, level: "ADSET", status: "ACTIVE", dailyBudget: 100, campaignId: "c", campaignName: "C", objective: "LEADS", adSetId: id, days: d, ...o });
const ad = (id: string, adSetId: string, d: ReturnType<typeof days>, o: Partial<Ent> = {}): Ent => ({ id, name: `Ad ${id}`, level: "AD", status: "ACTIVE", dailyBudget: null, campaignId: "c", campaignName: "C", objective: "LEADS", adSetId, days: d, ...o });
const by = (list: ReturnType<typeof audit>) => new Map(list.map((x) => [x.entityId, x]));

describe("windows", () => {
  it("sums only the last n days", () => {
    expect(windowOf({ days: days(14, 10, 1) }, 7, TODAY)).toMatchObject({ spend: 70, results: 7, days: 7 });
  });
});

describe("daily audit", () => {
  // Usual cost per result ≈ 10 (set "ok": 100/day, 10 results/day).
  const ok = set("ok", days(30, 100, 10));
  it("suggests pausing what spends with no result, and clear losers", () => {
    const ideas = by(audit([ok, set("dry", days(7, 20, 0)), set("loser", days(14, 100, 2))], [], TODAY));
    expect(ideas.get("dry")).toMatchObject({ kind: "pause", level: "ADSET" });
    expect(ideas.get("loser")).toMatchObject({ kind: "pause" });
    expect(ideas.get("ok")?.kind).not.toBe("pause");
  });
  it("suggests +20% for a winner that spends its whole budget — and not for one with budget to spare", () => {
    const ideas = by(audit([ok, set("win", days(7, 100, 20)), set("roomy", days(7, 100, 20), { dailyBudget: 500 })], [], TODAY));
    expect(ideas.get("win")).toMatchObject({ kind: "budget", payload: { from: 100, to: 120, percent: 20 } });
    expect(ideas.has("roomy")).toBe(false);
  });
  it("never suggests pausing an ad set's only active ad, or anything already paused", () => {
    const ideas = by(audit([ok, set("s2", days(7, 50, 5))], [ad("lonely", "s2", days(7, 20, 0)), ad("a", "ok", days(7, 20, 0)), ad("b", "ok", days(7, 50, 10)), ad("off", "ok", days(7, 20, 0), { status: "PAUSED" })], TODAY));
    expect(ideas.has("lonely")).toBe(false);
    expect(ideas.get("a")).toMatchObject({ kind: "pause", level: "AD" });
    expect(ideas.has("off")).toBe(false);
  });
});

describe("automation rules", () => {
  const ok = set("ok", days(30, 100, 10));
  it("Stop Loss proposes a pause after 2× the usual cost per result with no result", () => {
    const ideas = evaluateRule(PRESETS.stop_loss, [ok, set("dry", days(3, 10, 0)), set("fine", days(3, 10, 1))], TODAY);
    expect(ideas.map((i) => i.entityId)).toEqual(["dry"]);
    expect(ideas[0].reason).toMatch(/Stop Loss/);
  });
  it("Surf raises and Sunsetting cuts the budget by 20%", () => {
    expect(evaluateRule(PRESETS.surf, [ok, set("win", days(3, 50, 10))], TODAY).find((i) => i.entityId === "win")).toMatchObject({ kind: "budget", payload: { to: 120, percent: 20 } });
    expect(evaluateRule(PRESETS.sunset, [ok, set("slow", days(7, 100, 5))], TODAY).find((i) => i.entityId === "slow")).toMatchObject({ kind: "budget", payload: { to: 80, percent: -20 } });
  });
  it("Revive only looks at paused ad sets", () => {
    const ideas = evaluateRule(PRESETS.revive, [ok, set("back", days(7, 50, 6), { status: "PAUSED" }), set("live", days(7, 50, 6))], TODAY);
    expect(ideas.map((i) => [i.entityId, i.kind])).toEqual([["back", "start"]]);
  });
  it("a custom rule needs every condition, and budget rules skip items without their own budget", () => {
    const rule = { name: "Cheap clicks", level: "ADSET" as const, windowDays: 7, conditions: [{ metric: "ctr" as const, op: ">=" as const, value: 2 }, { metric: "results" as const, op: ">=" as const, value: 5 }], action: { kind: "budget" as const, percent: 50 } };
    const ideas = evaluateRule(rule, [set("a", days(7, 10, 1, { clicks: 30 })), set("b", days(7, 10, 0, { clicks: 30 })), set("c", days(7, 10, 1, { clicks: 30 }), { dailyBudget: null })], TODAY);
    expect(ideas.map((i) => i.entityId)).toEqual(["a"]);
    expect(ideas[0].payload).toMatchObject({ percent: 20 }); // capped
  });
});
