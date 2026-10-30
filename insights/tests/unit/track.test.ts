import { describe, expect, it } from "vitest";
import { classifyChannel, originAllowed, parseUA, payloadSchema } from "@/lib/track";

describe("channel grouping", () => {
  it("classifies paid and organic traffic", () => {
    expect(classifyChannel({ utmSource: "facebook", utmMedium: "paid_social" })).toBe("Paid social");
    expect(classifyChannel({ clickIdType: "gclid" })).toBe("Paid search");
    expect(classifyChannel({ clickIdType: "ttclid" })).toBe("Paid social");
    expect(classifyChannel({ refHost: "google.com" })).toBe("Organic search");
    expect(classifyChannel({ refHost: "instagram.com" })).toBe("Organic social");
    expect(classifyChannel({ utmMedium: "email", utmSource: "newsletter" })).toBe("Email");
    expect(classifyChannel({ refHost: "chatgpt.com" })).toBe("AI assistants");
    expect(classifyChannel({ refHost: "perplexity.ai" })).toBe("AI assistants");
    expect(classifyChannel({ refHost: "propertyfinder.eg" })).toBe("Referral");
    expect(classifyChannel({})).toBe("Direct");
  });
});

describe("user agent", () => {
  it("detects devices and bots", () => {
    expect(parseUA("Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1")).toMatchObject({ device: "mobile", os: "iOS", browser: "Safari", bot: false });
    expect(parseUA("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0 Safari/537.36")).toMatchObject({ device: "desktop", os: "Windows", browser: "Chrome" });
    expect(parseUA("Mozilla/5.0 (compatible; Googlebot/2.1)").bot).toBe(true);
    expect(parseUA("Mozilla/5.0 HeadlessChrome/129.0").bot).toBe(true);
  });
});

describe("origin check", () => {
  it("accepts the domain and its subdomains only", () => {
    expect(originAllowed("https://www.example.com", "example.com")).toBe(true);
    expect(originAllowed("https://shop.example.com/x", "example.com")).toBe(true);
    expect(originAllowed("https://evil-example.com", "example.com")).toBe(false);
    expect(originAllowed("", "example.com")).toBe(false);
  });
});

describe("payload", () => {
  it("rejects unknown event types and oversized batches", () => {
    const base = { k: "key-12345678", v: "visitor", s: "session" };
    expect(payloadSchema.safeParse({ ...base, e: [{ t: "pv", ts: 1, p: "/", d: "desktop" }] }).success).toBe(true);
    expect(payloadSchema.safeParse({ ...base, e: [{ t: "drop_table", ts: 1, p: "/", d: "desktop" }] }).success).toBe(false);
    expect(payloadSchema.safeParse({ ...base, e: Array(201).fill({ t: "pv", ts: 1, p: "/", d: "desktop" }) }).success).toBe(false);
  });
});
