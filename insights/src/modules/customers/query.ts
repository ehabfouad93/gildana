import type { Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { decrypt } from "@/lib/crypto";
import { ID_LABEL, ID_TYPES, idHash, normalise, type IdType } from "@/modules/identity/rules";
import { scopeOf } from "@/modules/identity/service";
import { consentSummary } from "./service";
import { STAGES, STAGE_LABEL, type Stage } from "./profile";

/**
 * Reading customers for the dashboard and /api/v1. Every read takes an access
 * context, so tenancy (workspace, and the companies a limited user may see) is
 * enforced here and nowhere else.
 */
export type Access = { clientId: string; companyIds: string[] | null; pii: boolean };

export function customerWhere(a: Access): Prisma.CustomerWhereInput {
  return { clientId: a.clientId, mergedIntoId: null, ...(a.companyIds ? { companyId: { in: a.companyIds } } : {}) };
}

export type ListFilters = { q?: string; stage?: string; project?: string; minIntent?: number; source?: string; limit?: number; cursor?: string; sort?: "recent" | "intent" | "value" };

/** Phone or email typed in the search box: found by its hash, exactly. Anything else searches names. */
async function searchIds(a: Access, q: string): Promise<string[] | null> {
  const t = q.trim();
  const type: IdType | null = t.includes("@") ? "email" : /^[+\d][\d\s().-]{6,}$/.test(t) ? "phone" : null;
  if (!type) return null;
  const companies = a.companyIds ?? (await db.company.findMany({ where: { clientId: a.clientId }, select: { id: true } })).map((c) => c.id);
  const scopes = [...new Set(await Promise.all([null, ...companies].map(async (co) => (await scopeOf(a.clientId, co)).scopeId)))];
  const { countryCode } = await scopeOf(a.clientId, null);
  const n = normalise(type, t, countryCode);
  if (!n) return [];
  const rows = await db.customerIdentifier.findMany({ where: { scopeId: { in: scopes }, type, hash: idHash(type, n) }, select: { customerId: true } });
  return rows.map((r) => r.customerId);
}

export async function listCustomers(a: Access, f: ListFilters = {}) {
  const limit = Math.min(200, Math.max(1, f.limit ?? 50));
  const and: Prisma.CustomerWhereInput[] = [customerWhere(a)];
  if (f.q?.trim()) {
    const ids = await searchIds(a, f.q);
    and.push(ids ? { id: { in: ids } } : { name: { contains: f.q.trim(), mode: "insensitive" } });
  }
  if (f.stage && (STAGES as readonly string[]).includes(f.stage)) and.push({ stage: f.stage });
  if (f.project) and.push({ projectIds: { has: f.project } });
  if (f.minIntent) and.push({ intentScore: { gte: f.minIntent } });
  if (f.source) and.push({ identifiers: { some: { source: { contains: f.source, mode: "insensitive" } } } });
  const orderBy: Prisma.CustomerOrderByWithRelationInput[] = f.sort === "intent" ? [{ intentScore: "desc" }, { lastSeenAt: "desc" }] : f.sort === "value" ? [{ valueScore: "desc" }, { lastSeenAt: "desc" }] : [{ lastSeenAt: "desc" }];
  const rows = await db.customer.findMany({
    where: { AND: and }, orderBy: [...orderBy, { id: "asc" }], take: limit + 1, ...(f.cursor ? { cursor: { id: f.cursor }, skip: 1 } : {}),
    include: { identifiers: { select: { type: true, source: true } } },
  });
  const more = rows.length > limit;
  const page = rows.slice(0, limit);
  return { items: page.map((c) => summary(c, a)), nextCursor: more ? page.at(-1)!.id : null };
}

export async function countCustomers(a: Access) {
  const [total, byStage, pending] = await Promise.all([
    db.customer.count({ where: customerWhere(a) }),
    db.customer.groupBy({ by: ["stage"], where: customerWhere(a), _count: true }),
    db.identityLink.count({ where: { clientId: a.clientId, kind: "merge", status: "pending" } }),
  ]);
  return { total, byStage: Object.fromEntries(byStage.map((s) => [s.stage, s._count])) as Record<Stage, number>, pendingReviews: pending };
}

type Row = Prisma.CustomerGetPayload<{ include: { identifiers: { select: { type: true; source: true } } } }>;

function summary(c: Row, a: Access) {
  return {
    id: c.id, name: c.name, stage: c.stage, stageLabel: STAGE_LABEL[c.stage as Stage] ?? c.stage, companyId: c.companyId,
    phone: a.pii && c.phoneEnc ? `+${decrypt(c.phoneEnc)}` : c.phoneHint || null, email: a.pii && c.emailEnc ? decrypt(c.emailEnc) : c.emailHint || null,
    scores: { identity: c.identityScore, intent: c.intentScore, value: c.valueScore }, projectIds: c.projectIds, city: c.city,
    sources: [...new Set(c.identifiers.map((i) => i.source).filter(Boolean))], identifierTypes: [...new Set(c.identifiers.map((i) => i.type))],
    firstSeenAt: c.firstSeenAt, lastSeenAt: c.lastSeenAt, isDemo: c.isDemo,
  };
}

/** One customer with identifiers, match history, consent and score explanations. Follows merges to the live profile. */
export async function getCustomer(a: Access, id: string) {
  let c = await db.customer.findFirst({ where: { id, clientId: a.clientId }, select: { id: true, mergedIntoId: true } });
  for (let i = 0; c?.mergedIntoId && i < 10; i++) c = await db.customer.findFirst({ where: { id: c.mergedIntoId, clientId: a.clientId }, select: { id: true, mergedIntoId: true } });
  if (!c) return null;
  const full = await db.customer.findFirst({
    where: { id: c.id, ...customerWhere(a) },
    include: { identifiers: { orderBy: { firstSeenAt: "asc" } }, consents: true },
  });
  if (!full) return null;
  const links = await db.identityLink.findMany({ where: { clientId: a.clientId, OR: [{ customerId: full.id }, { otherCustomerId: full.id }] }, orderBy: { createdAt: "desc" }, take: 200 });
  const byIdentifier = new Map(links.filter((l) => l.kind === "attach" && l.identifierId).map((l) => [l.identifierId!, l]));
  const projects = full.projectIds.length ? await db.project.findMany({ where: { id: { in: full.projectIds }, clientId: a.clientId }, select: { id: true, name: true } }) : [];
  return {
    ...summary({ ...full, identifiers: full.identifiers }, a),
    projects, budget: full.budget, budgetText: full.budgetText, why: (full.scoreWhy as Record<string, { label: string; points: number }[]>) ?? {},
    firstTouch: full.firstTouch as Record<string, unknown> | null, lastTouch: full.lastTouch as Record<string, unknown> | null,
    identifiers: full.identifiers.map((i) => {
      const l = byIdentifier.get(i.id);
      return {
        id: i.id, type: i.type as IdType, label: ID_LABEL[i.type as IdType] ?? i.type,
        value: a.pii && i.valueEnc && (i.type === "phone" || i.type === "email" || i.type === "crm_id" || i.type === "account_id" || i.type === "whatsapp_id")
          ? `${i.type === "phone" || i.type === "whatsapp_id" ? "+" : ""}${decrypt(i.valueEnc)}` : i.display,
        verified: i.verified, source: i.source, firstSeenAt: i.firstSeenAt, lastSeenAt: i.lastSeenAt,
        match: l ? { method: l.method, confidence: l.confidence, at: l.createdAt, evidence: l.evidence } : null,
      };
    }),
    // An approved suggestion is recorded twice: the question and the merge it caused (which can be undone). Show the merge.
    merges: links.filter((l) => l.kind === "merge" && !(l.status === "approved" && !l.moved)).map((l) => ({
      id: l.id, status: l.status, method: l.method, confidence: l.confidence, evidence: l.evidence as Record<string, unknown>, source: l.source, at: l.createdAt, reviewedAt: l.reviewedAt,
      otherId: l.customerId === full.id ? l.otherCustomerId : l.customerId, survivor: l.customerId === full.id,
    })),
    consent: consentSummary(full.consents),
  };
}

export type CustomerDetail = NonNullable<Awaited<ReturnType<typeof getCustomer>>>;

/** Pending "same person?" questions, with both profiles side by side. */
export async function pendingReviews(a: Access, limit = 50) {
  const links = await db.identityLink.findMany({ where: { clientId: a.clientId, kind: "merge", status: "pending" }, orderBy: [{ confidence: "desc" }, { createdAt: "desc" }], take: limit });
  const ids = [...new Set(links.flatMap((l) => [l.customerId, l.otherCustomerId!]))];
  const people = await db.customer.findMany({ where: { id: { in: ids }, ...customerWhere(a) }, include: { identifiers: { select: { type: true, source: true } } } });
  const map = new Map(people.map((p) => [p.id, summary(p, a)]));
  return links.flatMap((l) => {
    const x = map.get(l.customerId), y = map.get(l.otherCustomerId!);
    return x && y ? [{ id: l.id, confidence: l.confidence, evidence: l.evidence as { matched?: string[]; reason?: string }, source: l.source, at: l.createdAt, a: x, b: y }] : [];
  });
}

export const isIdType = (t: string): t is IdType => (ID_TYPES as readonly string[]).includes(t);
