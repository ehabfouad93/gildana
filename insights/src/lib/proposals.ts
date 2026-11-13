import type { Prisma } from "@prisma/client";
import { db } from "@/lib/db";

/**
 * Filing proposals in the to-do list. Kept free of web-only imports because
 * the background worker (daily audit and rules) uses it too; applying a
 * proposal lives in src/lib/actions-engine.ts.
 */

export type Proposal = {
  clientId: string; accountId: string;
  kind: "pause" | "start" | "budget";
  level: "CAMPAIGN" | "ADSET" | "AD";
  entityId: string; entityName: string;
  payload?: { from?: number; to?: number; percent?: number };
  reason: string; source: string; priority: number;
};

/** Largest budget change one action may make, either way. */
export const MAX_BUDGET_STEP = 0.2;

const keyOf = (p: Pick<Proposal, "kind" | "level" | "entityId">) => `${p.kind}:${p.level}:${p.entityId}`;

/**
 * Adds proposals to the to-do list. One already waiting, or decided in the
 * last 7 days, is not proposed again — a dismissed idea stays dismissed for a week.
 */
export async function propose(list: Proposal[]): Promise<number> {
  if (!list.length) return 0;
  const recent = await db.action.findMany({
    where: { accountId: { in: [...new Set(list.map((p) => p.accountId))] }, dedupeKey: { in: list.map(keyOf) }, OR: [{ status: "proposed" }, { decidedAt: { gt: new Date(Date.now() - 7 * 86_400_000) } }] },
    select: { accountId: true, dedupeKey: true },
  });
  const seen = new Set(recent.map((r) => `${r.accountId}|${r.dedupeKey}`));
  const fresh = list.filter((p) => { const k = `${p.accountId}|${keyOf(p)}`; if (seen.has(k)) return false; seen.add(k); return true; });
  if (fresh.length) {
    await db.action.createMany({
      data: fresh.map((p) => ({
        clientId: p.clientId, accountId: p.accountId, kind: p.kind, level: p.level, entityId: p.entityId, entityName: p.entityName.slice(0, 250),
        payload: (p.payload ?? undefined) as Prisma.InputJsonValue | undefined, reason: p.reason.slice(0, 1000), source: p.source, priority: p.priority, dedupeKey: keyOf(p),
      })),
    });
  }
  return fresh.length;
}
