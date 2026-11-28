import { createHash } from "node:crypto";
import { normalEmail, normalPhone } from "@/platforms/meta/customer-list";

/**
 * Identity rules — pure, unit-tested.
 *
 * Deterministic first: people are joined only on identifiers that prove who
 * they are (CRM ID, phone, email, account ID, WhatsApp ID). A first-party
 * anonymous browser ID joins a customer only when that browser identifies
 * itself with one of those. Nothing is ever joined on IP address, device or
 * behaviour, and there is no fingerprinting.
 */

export const ID_TYPES = ["crm_id", "account_id", "whatsapp_id", "phone", "email", "lead_id", "anon_id"] as const;
export type IdType = (typeof ID_TYPES)[number];

export const ID_LABEL: Record<IdType, string> = {
  crm_id: "CRM ID", account_id: "Account ID", whatsapp_id: "WhatsApp", phone: "Phone", email: "Email", lead_id: "Ad lead", anon_id: "Website visitor",
};

export type RawId = { type: IdType; value: string; verified?: boolean };
export type CleanId = { type: IdType; value: string; hash: string; verified: boolean; strength: number; display: string };

/** Above this, a match on one existing customer is attached to it automatically. */
export const AUTO_ATTACH = 80;
/** Two existing customers are merged automatically only when both sides match at this strength. */
export const AUTO_MERGE = 90;

/**
 * How sure an exact match on this identifier makes us that it is the same person.
 * WhatsApp proves the phone; an unverified email (typed into a form) is the weakest
 * contact key and on its own only produces a review item, never a merge.
 */
export function strength(type: IdType, verified: boolean): number {
  switch (type) {
    case "crm_id": return 100;
    case "account_id": return 95;
    case "whatsapp_id": return 95;
    case "phone": return verified ? 95 : 85;
    case "email": return verified ? 90 : 70;
    case "lead_id": return 60;
    case "anon_id": return 40;
  }
}

/** Identifiers that may identify a person. anon_id and lead_id only point at one. */
export const PROVES_IDENTITY = (t: IdType) => t !== "anon_id" && t !== "lead_id";

const sha = (s: string) => createHash("sha256").update(s).digest("hex");

/**
 * Normalised value, or null when it isn't valid. Phones become digits with the
 * country code (a local 0… number gets `countryCode`), emails lower-case —
 * the same forms Meta, Google and TikTok hash, so the hash can be uploaded as is.
 */
export function normalise(type: IdType, value: string, countryCode = ""): string | null {
  const v = String(value ?? "").trim();
  if (!v) return null;
  if (type === "phone" || type === "whatsapp_id") return normalPhone(v, countryCode);
  if (type === "email") return normalEmail(v);
  return v.length <= 200 ? v : null;
}

/** Phones and emails hash bare (platform-compatible); the rest are namespaced by type. */
export function idHash(type: IdType, normalised: string): string {
  return type === "phone" || type === "email" || type === "whatsapp_id" ? sha(normalised) : sha(`${type}:${normalised}`);
}

/** Masked for lists and for users who may not see contact details. */
export function mask(type: IdType, v: string): string {
  if (type === "phone" || type === "whatsapp_id") return v.length > 4 ? `+${v.slice(0, Math.min(2, v.length - 4))}••••${v.slice(-4)}` : "••••";
  if (type === "email") { const [u, d] = v.split("@"); return `${u.slice(0, 1)}•••@${d ?? ""}`; }
  if (type === "anon_id") return `visitor …${v.slice(-6)}`;
  return v.length > 12 ? `${v.slice(0, 4)}…${v.slice(-4)}` : v;
}

/** Cleans incoming identifiers: drops invalid ones, de-duplicates, and adds a phone for a WhatsApp ID. */
export function cleanIds(raw: RawId[], countryCode = ""): CleanId[] {
  const out = new Map<string, CleanId>();
  const add = (type: IdType, value: string, verified: boolean) => {
    const n = normalise(type, value, countryCode);
    if (!n) return;
    const hash = idHash(type, n);
    const k = `${type}:${hash}`;
    const prev = out.get(k);
    if (prev && (prev.verified || !verified)) return;
    out.set(k, { type, value: n, hash, verified, strength: strength(type, verified), display: mask(type, n) });
  };
  for (const r of raw) {
    if (!ID_TYPES.includes(r.type)) continue;
    add(r.type, r.value, !!r.verified || r.type === "crm_id" || r.type === "account_id" || r.type === "whatsapp_id");
    // A WhatsApp conversation proves the person owns that number.
    if (r.type === "whatsapp_id") add("phone", r.value, true);
  }
  return [...out.values()].sort((a, b) => b.strength - a.strength);
}

export type Found = { type: IdType; hash: string; customerId: string };

export type Decision = {
  /** The customer the record belongs to; null = create a new one. */
  customerId: string | null;
  /** How the record was matched to that customer (null for a new one). */
  method: string | null;
  confidence: number;
  /** Incoming identifiers not yet known, to add to the customer. */
  attach: CleanId[];
  /** Existing customers proven to be the same person, merged into `customerId`. */
  merges: { from: string; confidence: number; evidence: string[] }[];
  /** Possible matches a person should check. `a` is the record's customer (null = the new one). */
  reviews: { other: string; confidence: number; evidence: string[]; reason: string }[];
};

/**
 * Decides where an incoming record belongs, given the existing identifiers it
 * matched (`found`) and, per candidate, the hashes of its single-valued keys
 * (CRM ID, account ID) so two different CRM contacts sharing one phone (family,
 * a company number) are never merged automatically.
 */
export function decide(ids: CleanId[], found: Found[], keysOf: Record<string, Partial<Record<IdType, string[]>>> = {}): Decision {
  const known = new Set(found.map((f) => `${f.type}:${f.hash}`));
  const attachAll = ids.filter((i) => !known.has(`${i.type}:${i.hash}`));
  const byCustomer = new Map<string, { score: number; types: IdType[]; proving: boolean }>();
  for (const f of found) {
    const id = ids.find((i) => i.type === f.type && i.hash === f.hash);
    if (!id) continue;
    const c = byCustomer.get(f.customerId) ?? { score: 0, types: [], proving: false };
    c.score = Math.max(c.score, id.strength);
    c.types.push(f.type);
    c.proving ||= PROVES_IDENTITY(f.type);
    byCustomer.set(f.customerId, c);
  }
  const evidence = (types: IdType[]) => [...new Set(types)].map((t) => `Same ${ID_LABEL[t].toLowerCase()}`);
  // A candidate whose CRM ID / account ID differs from the record's is a different person who shares a number.
  const conflicts = (customerId: string) => (["crm_id", "account_id"] as IdType[]).some((t) => {
    const mine = ids.filter((i) => i.type === t).map((i) => i.hash);
    const theirs = keysOf[customerId]?.[t] ?? [];
    return mine.length > 0 && theirs.length > 0 && !mine.some((h) => theirs.includes(h));
  });

  const ranked = [...byCustomer.entries()].sort((a, b) => b[1].score - a[1].score);
  if (!ranked.length) return { customerId: null, method: null, confidence: 0, attach: attachAll, merges: [], reviews: [] };

  const [topId, top] = ranked[0];
  const reviews: Decision["reviews"] = [];
  const merges: Decision["merges"] = [];

  // The best match is too weak (e.g. email only) or contradicts the record: new profile, flagged for review.
  if (top.score < AUTO_ATTACH || conflicts(topId)) {
    for (const [cid, c] of ranked) {
      if (!c.proving) continue;
      reviews.push({ other: cid, confidence: c.score, evidence: evidence(c.types), reason: conflicts(cid) ? "Shares contact details but has a different CRM / account ID" : "Only a weak identifier matches" });
    }
    // The record still is that browser or that lead (no proving identifier, no conflict), so it stays with it.
    const same = ranked.find(([cid, c]) => !c.proving && !conflicts(cid));
    if (same) return { customerId: same[0], method: same[1].types[0], confidence: same[1].score, attach: attachAll, merges: [], reviews };
    // Otherwise a new profile; identifiers that belong to others stay where they are.
    return { customerId: null, method: null, confidence: 0, attach: attachAll, merges: [], reviews };
  }

  for (const [cid, c] of ranked.slice(1)) {
    const auto = c.proving && c.score >= AUTO_MERGE && top.score >= AUTO_MERGE && !conflicts(cid);
    if (auto) merges.push({ from: cid, confidence: Math.min(c.score, top.score), evidence: evidence(c.types) });
    else if (c.proving) reviews.push({ other: cid, confidence: c.score, evidence: evidence(c.types), reason: conflicts(cid) ? "Shares contact details but has a different CRM / account ID" : "Match not strong enough to merge automatically" });
    // A browser or lead that belongs to someone else (shared device) is left where it is.
  }
  return { customerId: topId, method: top.types[0], confidence: top.score, attach: attachAll, merges, reviews };
}

/** Country calling code for local phone numbers, from the workspace time zone. */
export function countryCodeFor(timezone: string): string {
  const map: Record<string, string> = {
    "Africa/Cairo": "20", "Asia/Riyadh": "966", "Asia/Dubai": "971", "Asia/Kuwait": "965", "Asia/Qatar": "974", "Asia/Bahrain": "973",
    "Asia/Muscat": "968", "Asia/Amman": "962", "Asia/Beirut": "961", "Asia/Baghdad": "964", "Africa/Casablanca": "212", "Africa/Tunis": "216",
    "Africa/Algiers": "213", "Europe/London": "44", "Europe/Paris": "33", "Europe/Berlin": "49", "America/New_York": "1", "America/Los_Angeles": "1", "America/Chicago": "1",
  };
  return map[timezone] ?? "";
}

/** Identity scope: brand clients share one identity space; an agency's companies each have their own. */
export function scopeFor(client: { id: string; isAgency: boolean }, companyId: string | null | undefined): string {
  return client.isAgency ? `${client.id}:${companyId ?? "none"}` : client.id;
}
