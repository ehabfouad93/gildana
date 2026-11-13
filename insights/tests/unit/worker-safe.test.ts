import { describe, expect, it, vi } from "vitest";

vi.mock("@/lib/db", () => ({ db: {} }));

/**
 * The background worker runs outside Next.js, where importing a web-only
 * module ("server-only", next/headers, the session) throws at start-up and
 * stops every sync. These are the modules the worker loads for the daily
 * audit and rules; importing them here fails the same way if one of them
 * starts depending on web-only code.
 */
describe("modules the worker imports", () => {
  it("load outside the web app", async () => {
    const run = await import("@/lib/optimise-run");
    const proposals = await import("@/lib/proposals");
    expect(typeof run.runAudit).toBe("function");
    expect(typeof run.runRules).toBe("function");
    expect(typeof proposals.propose).toBe("function");
    expect(typeof (await import("@/lib/lead-sync")).deliverLeads).toBe("function");
  });
});
