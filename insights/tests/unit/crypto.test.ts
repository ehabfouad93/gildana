import { beforeAll, describe, expect, it } from "vitest";

beforeAll(() => {
  process.env.ENCRYPTION_KEY = Buffer.alloc(32, 7).toString("base64");
});

describe("crypto", () => {
  it("round-trips and detects tampering", async () => {
    const { decrypt, encrypt } = await import("@/lib/crypto");
    const c = encrypt("EAAB-secret-token");
    expect(c.startsWith("v1:")).toBe(true);
    expect(decrypt(c)).toBe("EAAB-secret-token");
    const parts = c.split(":");
    parts[3] = Buffer.from("tampered").toString("base64");
    expect(() => decrypt(parts.join(":"))).toThrow();
  });
});
