import { describe, expect, it } from "vitest";
import { identityScore, intentScore, leadContact, matchProjects, parseBudget, stageOf, valueScore, type Ev } from "@/modules/customers/profile";

const now = new Date("2026-11-28T12:00:00Z");
const ago = (d: number) => new Date(now.getTime() - d * 86_400_000);
const ev = (type: string, d = 1, source = "website"): Ev => ({ type, occurredAt: ago(d), source });

describe("funnel stage", () => {
  it("anonymous with two visits is an engaged visitor", () => {
    expect(stageOf([], [{ startedAt: ago(1), pageviews: 2 }, { startedAt: ago(3), pageviews: 1 }], now)).toBe("engaged");
  });
  it("takes the furthest step reached", () => {
    expect(stageOf([ev("lead", 10), ev("meeting_booked", 5, "crm"), ev("qualified", 7, "crm")], [], now)).toBe("meeting_booked");
  });
  it("lost when sales' last outcome was lost", () => {
    expect(stageOf([ev("lead", 10), ev("qualified", 8, "crm"), ev("lost", 2, "crm")], [], now)).toBe("lost");
  });
  it("cold after 60 quiet days before a reservation; buyers never go cold", () => {
    expect(stageOf([ev("lead", 90)], [], now)).toBe("cold");
    expect(stageOf([ev("purchase", 200, "crm")], [], now)).toBe("purchase");
  });
});

describe("purchase intent", () => {
  it("adds up recent actions and explains each", () => {
    const r = intentScore([ev("payment_plan_view", 0), ev("price_view", 0), ev("whatsapp_click", 0)], [{ startedAt: ago(1), pageviews: 3 }, { startedAt: ago(2), pageviews: 2 }], now);
    expect(r.score).toBeGreaterThanOrEqual(50);
    expect(r.why.map((w) => w.label)).toContain("Viewed the payment plan");
    expect(r.why.find((w) => /website visits/.test(w.label))?.points).toBe(10);
  });
  it("old actions fade and stop counting after 30 days", () => {
    expect(intentScore([ev("payment_plan_view", 29)], [], now).score).toBeLessThan(intentScore([ev("payment_plan_view", 0)], [], now).score);
    expect(intentScore([ev("payment_plan_view", 40)], [], now).score).toBe(0);
  });
  it("the same action twice on one day counts once", () => {
    expect(intentScore([ev("price_view", 0), ev("price_view", 0)], [], now).score).toBe(intentScore([ev("price_view", 0)], [], now).score);
  });
});

describe("value and identity", () => {
  it("budget against project prices", () => {
    expect(valueScore({ budget: 6e6, prices: [{ from: 3e6, to: 5e6 }], purchases: 0, revenue: 0 }).score).toBe(40);
    expect(valueScore({ budget: 1e6, prices: [{ from: 3e6, to: 5e6 }], purchases: 0, revenue: 0 }).score).toBe(10);
    expect(valueScore({ budget: null, prices: [], purchases: 0, revenue: 0 }).why[0].label).toMatch(/No budget/);
  });
  it("identity confidence is the strongest identifier plus extra sources", () => {
    expect(identityScore([{ strength: 85, source: "meta lead", label: "Phone" }, { strength: 40, source: "website", label: "Website visitor" }]).score).toBe(90);
  });
});

describe("projects, budgets, lead forms", () => {
  const projects = [{ id: "p1", name: "Porto Sokhna", slug: "porto-sokhna", aliases: ["بورتو السخنة"] }, { id: "p2", name: "Porto Golf", slug: "porto-golf", aliases: [] }];
  it("finds projects in URLs, campaign names and Arabic text", () => {
    expect(matchProjects("/projects/porto-sokhna/payment-plan", projects)).toEqual(["p1"]);
    expect(matchProjects("LeadGen | Porto Golf | Nov", projects)).toEqual(["p2"]);
    expect(matchProjects("حملة بورتو السخنة", projects)).toEqual(["p1"]);
    expect(matchProjects("portoxsokhna", projects)).toEqual([]);
  });
  it("reads budgets", () => {
    expect(parseBudget("2M – 5M")).toBe(3.5e6);
    expect(parseBudget("2 – 5M")).toBe(3.5e6);
    expect(parseBudget("3,500,000")).toBe(3.5e6);
    expect(parseBudget("٣ مليون")).toBe(3e6);
    expect(parseBudget("ask me")).toBeNull();
  });
  it("pulls contact details out of any lead form and keeps them out of the answers", () => {
    const f = leadContact({ first_name: "Sara", last_name: "Ali", phone_number: "+2010", email: "s@x.com", budget: "2M – 5M", "preferred_unit": "Villa" });
    expect(f).toMatchObject({ name: "Sara Ali", phone: "+2010", email: "s@x.com", budget: "2M – 5M" });
    expect(f.answers).toEqual({ budget: "2M – 5M", preferred_unit: "Villa" });
  });
});
