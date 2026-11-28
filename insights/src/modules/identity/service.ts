import type { Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { decrypt, encrypt } from "@/lib/crypto";
import { ID_LABEL, PROVES_IDENTITY, cleanIds, countryCodeFor, decide, scopeFor, type CleanId, type IdType, type RawId } from "./rules";

/**
 * Identity resolution against the database. Worker-safe (no web-only imports):
 * used by the lead sync, the website tracker, the CRM / WhatsApp adapters and /api/v1.
 */

type Tx = Prisma.TransactionClient;

export type Profile = { name?: string; city?: string; budgetText?: string };
export type IdentifyInput = { clientId: string; companyId?: string | null; ids: RawId[]; source: string; profile?: Profile; at?: Date; userId?: string | null };
export type IdentifyResult = { customerId: string; created: boolean; merged: string[]; reviews: number } | null;

const clientCache = new Map<string, { at: number; c: { id: string; isAgency: boolean; timezone: string } }>();
async function clientInfo(id: string) {
  const hit = clientCache.get(id);
  if (hit && Date.now() - hit.at < 60_000) return hit.c;
  const c = await db.client.findUniqueOrThrow({ where: { id }, select: { id: true, isAgency: true, timezone: true } });
  clientCache.set(id, { at: Date.now(), c });
  return c;
}

export async function scopeOf(clientId: string, companyId: string | null | undefined) {
  const c = await clientInfo(clientId);
  return { scopeId: scopeFor(c, companyId), countryCode: countryCodeFor(c.timezone) };
}

const MAX_OPEN_REVIEWS = 3;
const pairKey = (a: string, b: string) => [a, b].sort().join("|");

/** Pairs someone already said are different people (rejected review, undone merge) — never merged or asked again. */
async function blockedPairs(tx: Tx, clientId: string, ids: string[]): Promise<Set<string>> {
  if (ids.length < 2) return new Set();
  const rows = await tx.identityLink.findMany({
    where: { clientId, kind: "merge", status: { in: ["rejected", "reversed", "pending"] }, customerId: { in: ids }, otherCustomerId: { in: ids } },
    select: { customerId: true, otherCustomerId: true, status: true },
  });
  return new Set(rows.map((r) => `${r.status === "pending" ? "p:" : ""}${pairKey(r.customerId, r.otherCustomerId!)}`));
}

/**
 * Finds or creates the customer a record belongs to, attaches its new
 * identifiers, merges profiles it proves are one person and queues the
 * doubtful ones for review. Returns null when the record carries nothing
 * that identifies anyone (an anonymous visitor not yet known).
 */
export async function identify(input: IdentifyInput): Promise<IdentifyResult> {
  const { scopeId, countryCode } = await scopeOf(input.clientId, input.companyId);
  const ids = cleanIds(input.ids, countryCode);
  if (!ids.length) return null;
  const at = input.at ?? new Date();

  for (let attempt = 0; ; attempt++) {
    try {
      return await db.$transaction(async (tx) => {
        const found = await tx.customerIdentifier.findMany({
          where: { scopeId, OR: ids.map((i) => ({ type: i.type, hash: i.hash })) },
          select: { type: true, hash: true, customerId: true },
        });
        // Only anonymous ids and none known yet: nobody to resolve.
        if (!found.length && !ids.some((i) => i.type !== "anon_id")) return null;
        const candidateIds = [...new Set(found.map((f) => f.customerId))];
        const keys = candidateIds.length > 1 || ids.some((i) => i.type === "crm_id" || i.type === "account_id")
          ? await tx.customerIdentifier.findMany({ where: { customerId: { in: candidateIds }, type: { in: ["crm_id", "account_id"] } }, select: { customerId: true, type: true, hash: true } })
          : [];
        const keysOf: Record<string, Partial<Record<IdType, string[]>>> = {};
        for (const k of keys) ((keysOf[k.customerId] ??= {})[k.type as IdType] ??= []).push(k.hash);

        const d = decide(ids, found.map((f) => ({ ...f, type: f.type as IdType })), keysOf);
        let customerId = d.customerId;
        const created = !customerId;
        if (!customerId) {
          const c = await tx.customer.create({ data: { clientId: input.clientId, scopeId, companyId: input.companyId ?? null, firstSeenAt: at, lastSeenAt: at } });
          customerId = c.id;
        }
        if (d.attach.length) await attachIds(tx, input.clientId, scopeId, customerId, d.attach, input.source, at, created ? "new profile" : d.method ?? "");

        const blocked = await blockedPairs(tx, input.clientId, [customerId, ...d.merges.map((m) => m.from), ...d.reviews.map((r) => r.other)]);
        const merged: string[] = [];
        for (const m of d.merges) {
          if (blocked.has(pairKey(customerId, m.from))) continue;
          await mergeInto(tx, input.clientId, m.from, customerId, { method: "deterministic", confidence: m.confidence, evidence: m.evidence, source: input.source, status: "auto", userId: input.userId ?? null });
          merged.push(m.from);
        }
        let reviews = 0;
        for (const r of d.reviews) {
          const k = pairKey(customerId, r.other);
          if (r.other === customerId || blocked.has(k) || blocked.has(`p:${k}`)) continue;
          // An identifier shared by many people (an office email, a family number) would flood the queue: three open questions per profile is enough.
          if ((await tx.identityLink.count({ where: { clientId: input.clientId, kind: "merge", status: "pending", OR: [{ customerId: r.other }, { otherCustomerId: r.other }] } })) >= MAX_OPEN_REVIEWS) continue;
          await tx.identityLink.create({ data: { clientId: input.clientId, customerId, otherCustomerId: r.other, kind: "merge", method: "suggested", confidence: r.confidence, evidence: { matched: r.evidence, reason: r.reason }, source: input.source, status: "pending", createdById: input.userId ?? null } });
          reviews++;
        }
        await applyProfile(tx, customerId, ids, input.profile, at);
        return { customerId, created, merged, reviews };
      });
    } catch (e) {
      // Two records for one new person at the same moment: the second retries and finds the first.
      if (attempt < 2 && (e as { code?: string }).code === "P2002") continue;
      throw e;
    }
  }
}

async function attachIds(tx: Tx, clientId: string, scopeId: string, customerId: string, ids: CleanId[], source: string, at: Date, method: string) {
  await tx.customerIdentifier.createMany({
    skipDuplicates: true,
    data: ids.map((i) => ({ clientId, scopeId, customerId, type: i.type, hash: i.hash, valueEnc: encrypt(i.value), display: i.display, verified: i.verified, source, firstSeenAt: at, lastSeenAt: at })),
  });
  const rows = await tx.customerIdentifier.findMany({ where: { customerId, OR: ids.map((i) => ({ type: i.type, hash: i.hash })) }, select: { id: true, type: true, hash: true } });
  await tx.identityLink.createMany({
    data: rows.map((r) => {
      const i = ids.find((x) => x.type === r.type && x.hash === r.hash)!;
      return { clientId, customerId, identifierId: r.id, kind: "attach", method: method || i.type, confidence: i.strength, evidence: { identifier: ID_LABEL[i.type], verified: i.verified }, source, status: "auto" };
    }),
  });
}

/** Fills empty profile fields (never overwrites what sales typed) and moves last-seen forward. */
async function applyProfile(tx: Tx, customerId: string, ids: CleanId[], p: Profile | undefined, at: Date) {
  const c = await tx.customer.findUniqueOrThrow({ where: { id: customerId }, select: { name: true, phoneEnc: true, emailEnc: true, city: true, budgetText: true, lastSeenAt: true, firstSeenAt: true } });
  const phone = ids.find((i) => i.type === "phone"), email = ids.find((i) => i.type === "email");
  const data: Prisma.CustomerUpdateInput = {};
  if (!c.name && p?.name) data.name = p.name.slice(0, 120);
  if (!c.phoneEnc && phone) { data.phoneEnc = encrypt(phone.value); data.phoneHint = phone.display; }
  if (!c.emailEnc && email) { data.emailEnc = encrypt(email.value); data.emailHint = email.display; }
  if (!c.city && p?.city) data.city = p.city.slice(0, 80);
  if (!c.budgetText && p?.budgetText) data.budgetText = p.budgetText.slice(0, 60);
  if (at > c.lastSeenAt) data.lastSeenAt = at;
  if (at < c.firstSeenAt) data.firstSeenAt = at;
  if (Object.keys(data).length) await tx.customer.update({ where: { id: customerId }, data });
}

type MergeMeta = { method: string; confidence: number; evidence: string[] | Record<string, unknown>; source: string; status: "auto" | "approved"; userId: string | null };

/** Moves everything of `from` onto `into` and remembers what moved, so the merge can be undone. */
async function mergeInto(tx: Tx, clientId: string, from: string, into: string, m: MergeMeta) {
  const [idents, events, consents, leads] = await Promise.all([
    tx.customerIdentifier.findMany({ where: { customerId: from }, select: { id: true } }),
    tx.customerEvent.findMany({ where: { customerId: from }, select: { id: true } }),
    tx.consentRecord.findMany({ where: { customerId: from }, select: { id: true } }),
    tx.lead.findMany({ where: { customerId: from }, select: { id: true } }),
  ]);
  const moved = { identifiers: idents.map((x) => x.id), events: events.map((x) => x.id), consents: consents.map((x) => x.id), leads: leads.map((x) => x.id) };
  await tx.customerIdentifier.updateMany({ where: { customerId: from }, data: { customerId: into } });
  await tx.customerEvent.updateMany({ where: { customerId: from }, data: { customerId: into } });
  await tx.consentRecord.updateMany({ where: { customerId: from }, data: { customerId: into } });
  await tx.lead.updateMany({ where: { customerId: from }, data: { customerId: into } });
  const f = await tx.customer.update({ where: { id: from }, data: { mergedIntoId: into }, select: { name: true, phoneEnc: true, emailEnc: true, phoneHint: true, emailHint: true, firstSeenAt: true, city: true, budgetText: true } });
  const t = await tx.customer.findUniqueOrThrow({ where: { id: into }, select: { name: true, phoneEnc: true, emailEnc: true, firstSeenAt: true, city: true, budgetText: true } });
  await tx.customer.update({
    where: { id: into },
    data: {
      ...(!t.name && f.name ? { name: f.name } : {}), ...(!t.phoneEnc && f.phoneEnc ? { phoneEnc: f.phoneEnc, phoneHint: f.phoneHint } : {}),
      ...(!t.emailEnc && f.emailEnc ? { emailEnc: f.emailEnc, emailHint: f.emailHint } : {}), ...(!t.city && f.city ? { city: f.city } : {}),
      ...(!t.budgetText && f.budgetText ? { budgetText: f.budgetText } : {}), ...(f.firstSeenAt < t.firstSeenAt ? { firstSeenAt: f.firstSeenAt } : {}),
    },
  });
  // Merges that pointed at `from` now point at the survivor.
  await tx.customer.updateMany({ where: { mergedIntoId: from }, data: { mergedIntoId: into } });
  await tx.identityLink.create({ data: { clientId, customerId: into, otherCustomerId: from, kind: "merge", method: m.method, confidence: m.confidence, evidence: Array.isArray(m.evidence) ? { matched: m.evidence } : (m.evidence as Prisma.InputJsonValue), source: m.source, status: m.status, moved, createdById: m.userId, reviewedById: m.status === "approved" ? m.userId : null, reviewedAt: m.status === "approved" ? new Date() : null } });
}

/** The live profile an id now lives in (follows merges). */
export async function liveId(id: string): Promise<string> {
  let cur = id;
  for (let i = 0; i < 10; i++) {
    const c = await db.customer.findUnique({ where: { id: cur }, select: { mergedIntoId: true } });
    if (!c?.mergedIntoId) return cur;
    cur = c.mergedIntoId;
  }
  return cur;
}

/** Accepts a suggested match: merges the two profiles (the older one survives). */
export async function approveReview(clientId: string, linkId: string, userId: string): Promise<string> {
  const l = await db.identityLink.findFirst({ where: { id: linkId, clientId, kind: "merge", status: "pending" } });
  if (!l?.otherCustomerId) throw new Error("This suggestion was already handled.");
  const a = await liveId(l.customerId), b = await liveId(l.otherCustomerId);
  if (a === b) { await db.identityLink.update({ where: { id: l.id }, data: { status: "approved", reviewedById: userId, reviewedAt: new Date() } }); return a; }
  const [ca, cb] = await db.customer.findMany({ where: { id: { in: [a, b] } }, orderBy: { firstSeenAt: "asc" }, select: { id: true } });
  await db.$transaction(async (tx) => {
    await mergeInto(tx, clientId, cb.id, ca.id, { method: "manual review", confidence: l.confidence, evidence: (l.evidence as Record<string, unknown>) ?? {}, source: "review", status: "approved", userId });
    await tx.identityLink.update({ where: { id: l.id }, data: { status: "approved", reviewedById: userId, reviewedAt: new Date() } });
  });
  return ca.id;
}

/** Keeps them apart; the pair is never suggested or merged automatically again. */
export async function rejectReview(clientId: string, linkId: string, userId: string) {
  const r = await db.identityLink.updateMany({ where: { id: linkId, clientId, kind: "merge", status: "pending" }, data: { status: "rejected", reviewedById: userId, reviewedAt: new Date() } });
  if (!r.count) throw new Error("This suggestion was already handled.");
}

/**
 * Undoes a merge: what came from the other profile goes back to it (identifiers,
 * events, consent, leads), and the pair is blocked from merging again.
 * Events that arrived after the merge stay with the survivor.
 */
export async function unmerge(clientId: string, linkId: string, userId: string): Promise<{ restored: string; kept: string }> {
  const l = await db.identityLink.findFirst({ where: { id: linkId, clientId, kind: "merge", status: { in: ["auto", "approved"] } } });
  if (!l?.otherCustomerId || !l.moved) throw new Error("This merge can't be undone (already undone, or not a merge).");
  const m = l.moved as { identifiers: string[]; events: string[]; consents: string[]; leads: string[] };
  const into = l.customerId, from = l.otherCustomerId;
  await db.$transaction(async (tx) => {
    await tx.customerIdentifier.updateMany({ where: { id: { in: m.identifiers } }, data: { customerId: from } });
    await tx.customerEvent.updateMany({ where: { id: { in: m.events } }, data: { customerId: from } });
    await tx.consentRecord.updateMany({ where: { id: { in: m.consents } }, data: { customerId: from } });
    await tx.lead.updateMany({ where: { id: { in: m.leads } }, data: { customerId: from } });
    await tx.customer.update({ where: { id: from }, data: { mergedIntoId: null } });
    // The survivor keeps contact details only if they are still its own.
    const own = await tx.customerIdentifier.findMany({ where: { customerId: into, type: { in: ["phone", "email"] } }, select: { type: true, valueEnc: true, display: true } });
    const survivor = await tx.customer.findUniqueOrThrow({ where: { id: into }, select: { phoneEnc: true, emailEnc: true } });
    const keep = (type: string, enc: string | null) => !!enc && own.some((o) => o.type === type && o.valueEnc && decrypt(o.valueEnc) === decrypt(enc));
    const phone = own.find((o) => o.type === "phone"), email = own.find((o) => o.type === "email");
    await tx.customer.update({
      where: { id: into },
      data: {
        ...(keep("phone", survivor.phoneEnc) ? {} : { phoneEnc: phone?.valueEnc ?? null, phoneHint: phone?.display ?? "" }),
        ...(keep("email", survivor.emailEnc) ? {} : { emailEnc: email?.valueEnc ?? null, emailHint: email?.display ?? "" }),
      },
    });
    await tx.identityLink.update({ where: { id: l.id }, data: { status: "reversed", reviewedById: userId, reviewedAt: new Date() } });
  });
  return { restored: from, kept: into };
}

/** Whether any identifier on this record proves who someone is (not only a browser or a lead id). */
export const provesIdentity = (ids: RawId[]) => ids.some((i) => PROVES_IDENTITY(i.type) && String(i.value ?? "").trim());
