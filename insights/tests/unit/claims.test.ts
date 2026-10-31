import { describe, expect, it } from "vitest";
import { normalizeAccountId } from "@/lib/claims";

describe("normalizeAccountId", () => {
  it("accepts ids the way people paste them", () => {
    expect(normalizeAccountId("META", " act_123456789 ")).toBe("123456789");
    expect(normalizeAccountId("GOOGLE", "123-456-7890")).toBe("1234567890");
    expect(normalizeAccountId("LINKEDIN", "urn:li:sponsoredAccount:5123")).toBe("5123");
    expect(normalizeAccountId("TIKTOK", "7012 3456")).toBe("70123456");
  });
});
