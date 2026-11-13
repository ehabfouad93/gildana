import { createHmac } from "node:crypto";
import { describe, expect, it, vi } from "vitest";

vi.mock("@/lib/db", () => ({ db: {} }));
const { retryDelayMinutes, sign, webhookProblem } = await import("@/lib/lead-sync");
const { normalEmail, normalPhone, parseCustomerList, hashAll } = await import("@/platforms/meta/customer-list");

describe("lead webhooks", () => {
  it("signs the exact body with the destination's secret", () => {
    const body = JSON.stringify({ id: "1" });
    expect(sign(body, "s3cret")).toBe(`sha256=${createHmac("sha256", "s3cret").update(body).digest("hex")}`);
    expect(sign(body, "other")).not.toBe(sign(body, "s3cret"));
  });
  it("backs off between retries", () => {
    expect([1, 2, 3, 4, 9].map(retryDelayMinutes)).toEqual([2, 10, 30, 120, 120]);
  });
  it("only accepts public https addresses", () => {
    expect(webhookProblem("https://hooks.zapier.com/hooks/catch/1/abc", false)).toBeNull();
    expect(webhookProblem("https://script.google.com/macros/s/x/exec", false)).toBeNull();
    for (const bad of ["http://example.com/hook", "https://localhost/x", "https://127.0.0.1/x", "https://10.0.0.5/x", "https://192.168.1.10/x", "https://172.20.0.3/x", "https://169.254.169.254/latest", "https://[::1]/x", "https://intranet/x", "https://db.internal/x", "ftp://example.com", "not a url"])
      expect(webhookProblem(bad, false), bad).not.toBeNull();
  });
});

describe("customer lists", () => {
  it("normalises emails and phone numbers", () => {
    expect(normalEmail("  Ahmed@Example.COM ")).toBe("ahmed@example.com");
    expect(normalEmail("not-an-email")).toBeNull();
    expect(normalPhone("+20 100 123 4567")).toBe("201001234567");
    expect(normalPhone("0020 100 123 4567")).toBe("201001234567");
    expect(normalPhone("0100 123 4567", "20")).toBe("201001234567");
    expect(normalPhone("123")).toBeNull();
  });
  it("reads a mixed CSV and removes duplicates", () => {
    const r = parseCustomerList('name,email,phone\n"Ahmed",ahmed@example.com,+201001234567\nMona,MONA@example.com,01001234567\nx,ahmed@example.com,', "20");
    expect(r.emails).toEqual(["ahmed@example.com", "mona@example.com"]);
    expect(r.phones).toEqual(["201001234567"]);
  });
  it("hashes with SHA-256", () => {
    expect(hashAll(["ahmed@example.com"])[0]).toMatch(/^[0-9a-f]{64}$/);
    expect(hashAll(["a"])[0]).toBe("ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48bb");
  });
});
