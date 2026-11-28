import { describe, expect, it } from "vitest";
import { AUTO_ATTACH, cleanIds, countryCodeFor, decide, idHash, mask, normalise, scopeFor, strength, type Found } from "@/modules/identity/rules";
import { createHash } from "node:crypto";

const ids = (raw: Parameters<typeof cleanIds>[0]) => cleanIds(raw, "20");
const hashOf = (type: Parameters<typeof idHash>[0], v: string) => idHash(type, normalise(type, v, "20")!);

describe("normalising identifiers", () => {
  it("phones get the country code and match however they were typed", () => {
    expect(normalise("phone", "010 1234 5678", "20")).toBe("201012345678");
    expect(normalise("phone", "+20 10 1234-5678", "20")).toBe("201012345678");
    expect(normalise("phone", "00201012345678", "20")).toBe("201012345678");
    expect(normalise("phone", "abc", "20")).toBeNull();
  });
  it("emails are lower-cased and checked", () => {
    expect(normalise("email", "  Sara.Ali@Example.COM ")).toBe("sara.ali@example.com");
    expect(normalise("email", "not-an-email")).toBeNull();
  });
  it("phone and email hashes are plain SHA-256 of the normalised value (what ad platforms accept)", () => {
    expect(idHash("phone", "201012345678")).toBe(createHash("sha256").update("201012345678").digest("hex"));
    expect(idHash("crm_id", "42")).not.toBe(idHash("account_id", "42"));
  });
  it("a WhatsApp id also counts as a verified phone", () => {
    const c = ids([{ type: "whatsapp_id", value: "+201012345678" }]);
    expect(c.map((x) => [x.type, x.verified])).toEqual([["whatsapp_id", true], ["phone", true]]);
  });
  it("masks contact details", () => {
    expect(mask("phone", "201012345678")).toBe("+20••••5678");
    expect(mask("email", "sara@example.com")).toBe("s•••@example.com");
  });
  it("time zones give the local country code", () => {
    expect(countryCodeFor("Africa/Cairo")).toBe("20");
    expect(countryCodeFor("Asia/Riyadh")).toBe("966");
  });
  it("agency companies get separate identity spaces; brand companies share one", () => {
    expect(scopeFor({ id: "c1", isAgency: false }, "co1")).toBe("c1");
    expect(scopeFor({ id: "c1", isAgency: true }, "co1")).not.toBe(scopeFor({ id: "c1", isAgency: true }, "co2"));
  });
});

describe("deciding where a record belongs", () => {
  it("a new person makes a new profile", () => {
    const d = decide(ids([{ type: "phone", value: "01012345678" }]), []);
    expect(d.customerId).toBeNull();
    expect(d.attach).toHaveLength(1);
  });

  it("an exact phone match attaches to the existing customer", () => {
    const rec = ids([{ type: "phone", value: "01012345678" }, { type: "email", value: "new@x.com" }]);
    const d = decide(rec, [{ type: "phone", hash: hashOf("phone", "01012345678"), customerId: "A" }]);
    expect(d.customerId).toBe("A");
    expect(d.attach.map((x) => x.type)).toEqual(["email"]);
    expect(d.confidence).toBeGreaterThanOrEqual(AUTO_ATTACH);
  });

  it("an email-only match is too weak: new profile plus a review item, no merge", () => {
    const rec = ids([{ type: "email", value: "sara@x.com" }, { type: "lead_id", value: "META:1" }]);
    const d = decide(rec, [{ type: "email", hash: hashOf("email", "sara@x.com"), customerId: "A" }]);
    expect(d.customerId).toBeNull();
    expect(d.merges).toHaveLength(0);
    expect(d.reviews).toEqual([expect.objectContaining({ other: "A" })]);
    // The email stays with A; only the new lead id goes to the new profile.
    expect(d.attach.map((x) => x.type)).toEqual(["lead_id"]);
  });

  it("CRM ID on one profile and verified phone on another prove one person: merge", () => {
    const rec = ids([{ type: "crm_id", value: "C-9" }, { type: "phone", value: "01012345678", verified: true }]);
    const found: Found[] = [
      { type: "crm_id", hash: hashOf("crm_id", "C-9"), customerId: "A" },
      { type: "phone", hash: hashOf("phone", "01012345678"), customerId: "B" },
    ];
    const d = decide(rec, found);
    expect(d.customerId).toBe("A");
    expect(d.merges).toEqual([expect.objectContaining({ from: "B" })]);
  });

  it("an unverified phone shared with another profile is a review, not a merge", () => {
    const rec = ids([{ type: "crm_id", value: "C-9" }, { type: "phone", value: "01012345678" }]);
    const d = decide(rec, [
      { type: "crm_id", hash: hashOf("crm_id", "C-9"), customerId: "A" },
      { type: "phone", hash: hashOf("phone", "01012345678"), customerId: "B" },
    ]);
    expect(d.merges).toHaveLength(0);
    expect(d.reviews.map((r) => r.other)).toEqual(["B"]);
  });

  it("two CRM contacts sharing one phone (family, office number) are never merged", () => {
    const rec = ids([{ type: "crm_id", value: "C-2" }, { type: "phone", value: "01012345678", verified: true }]);
    const d = decide(rec, [{ type: "phone", hash: hashOf("phone", "01012345678"), customerId: "A" }], { A: { crm_id: [hashOf("crm_id", "C-1")] } });
    expect(d.customerId).toBeNull();
    expect(d.merges).toHaveLength(0);
    expect(d.reviews[0].reason).toMatch(/different CRM/);
  });

  it("a browser id never merges people: a shared device stays with its first owner", () => {
    const rec = ids([{ type: "anon_id", value: "site:v1" }, { type: "phone", value: "01099999999", verified: true }]);
    const d = decide(rec, [
      { type: "anon_id", hash: hashOf("anon_id", "site:v1"), customerId: "A" },
      { type: "phone", hash: hashOf("phone", "01099999999"), customerId: "B" },
    ]);
    expect(d.customerId).toBe("B");
    expect(d.merges).toHaveLength(0);
    expect(d.reviews).toHaveLength(0);
  });

  it("a known browser that sends a first email stays with its profile (the email is attached)", () => {
    const rec = ids([{ type: "anon_id", value: "site:v1" }, { type: "email", value: "x@y.com" }]);
    const d = decide(rec, [{ type: "anon_id", hash: hashOf("anon_id", "site:v1"), customerId: "A" }]);
    expect(d.customerId).toBe("A");
    expect(d.attach.map((x) => x.type)).toEqual(["email"]);
  });

  it("strengths follow the deterministic priority", () => {
    expect(strength("crm_id", true)).toBeGreaterThan(strength("phone", true));
    expect(strength("phone", true)).toBeGreaterThan(strength("email", true));
    expect(strength("email", true)).toBeGreaterThan(strength("anon_id", false));
  });
});
