import { describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
vi.mock("@/lib/db", () => ({ db: {} }));
const { choiceDiff } = await import("@/lib/account-choice");
const { demoAccounts } = await import("@/platforms/demo");

describe("choosing ad accounts", () => {
  it("adds ticked reachable accounts, removes unticked ones, keeps the rest", () => {
    expect(choiceDiff(["a", "b", "c"], ["a", "b", "d", "e"], ["a", "d", "x"])).toEqual({ add: ["d"], remove: ["b", "c"], keep: ["a"] });
  });
  it("never adds an account the login can't reach", () => {
    expect(choiceDiff([], ["a"], ["zzz"]).add).toEqual([]);
  });
  it("demo logins label accounts like Meta: some picked in the window, some through a business", () => {
    const list = demoAccounts("META", "demo-token-META");
    expect(list.some((a) => a.source === "chosen")).toBe(true);
    expect(list.some((a) => a.source !== "chosen")).toBe(true);
  });
});
