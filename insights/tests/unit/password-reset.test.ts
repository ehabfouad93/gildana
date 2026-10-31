import { describe, expect, it } from "vitest";
import { hashToken, isUsable, passwordProblem } from "@/lib/password-reset";

describe("password reset", () => {
  it("stores a stable hash, never the token", () => {
    expect(hashToken("abc")).toBe(hashToken("abc"));
    expect(hashToken("abc")).not.toContain("abc");
    expect(hashToken("abc")).toHaveLength(64);
  });
  it("accepts a link only once and only before it expires", () => {
    const now = new Date("2026-01-01T12:00:00Z");
    expect(isUsable({ expiresAt: new Date("2026-01-01T12:30:00Z"), usedAt: null }, now)).toBe(true);
    expect(isUsable({ expiresAt: new Date("2026-01-01T11:59:00Z"), usedAt: null }, now)).toBe(false);
    expect(isUsable({ expiresAt: new Date("2026-01-01T12:30:00Z"), usedAt: now }, now)).toBe(false);
    expect(isUsable(null, now)).toBe(false);
  });
  it("checks length and confirmation", () => {
    expect(passwordProblem("short")).toMatch(/8/);
    expect(passwordProblem("longenough1", "different1")).toMatch(/match/);
    expect(passwordProblem("longenough1", "longenough1")).toBeNull();
  });
});
