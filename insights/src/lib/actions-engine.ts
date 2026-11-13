import "server-only";
import type { Action, Prisma } from "@prisma/client";
import { decrypt } from "@/lib/crypto";
import { db } from "@/lib/db";
import { syncNow } from "@/lib/jobs";
import { accessWhere, canEdit, canFeature, type Viewer } from "@/lib/tenancy";
import { MAX_BUDGET_STEP } from "@/lib/proposals";
import { WRITE_SCOPE } from "@/platforms/meta";
import { setDailyBudget, setStatus } from "@/platforms/meta/write";

/**
 * Proposals to change a live ad account, and the only code that carries them
 * out. Audits and rules call `propose`; nothing is applied until a person
 * with the permission presses Apply. Every applied change keeps what was
 * there before, so pauses, starts and budget changes can be undone.
 */

export { propose, MAX_BUDGET_STEP, type Proposal } from "@/lib/proposals";

type Entity = { externalId: string; status: string; dailyBudget: number | null; name: string };

async function entityOf(a: Pick<Action, "level" | "entityId" | "accountId">): Promise<Entity | null> {
  const where = { id: a.entityId, accountId: a.accountId };
  if (a.level === "AD") {
    const x = await db.ad.findFirst({ where, select: { externalId: true, status: true, name: true } });
    return x ? { ...x, dailyBudget: null } : null;
  }
  const x = a.level === "ADSET"
    ? await db.adSet.findFirst({ where, select: { externalId: true, status: true, name: true, dailyBudget: true } })
    : await db.campaign.findFirst({ where, select: { externalId: true, status: true, name: true, dailyBudget: true } });
  return x ? { externalId: x.externalId, status: x.status, name: x.name, dailyBudget: x.dailyBudget != null ? Number(x.dailyBudget) : null } : null;
}

async function saveLocal(a: Pick<Action, "level" | "entityId">, data: { status?: string; dailyBudget?: number }) {
  if (a.level === "AD") { if (data.status) await db.ad.update({ where: { id: a.entityId }, data: { status: data.status } }); return; }
  if (a.level === "ADSET") await db.adSet.update({ where: { id: a.entityId }, data });
  else await db.campaign.update({ where: { id: a.entityId }, data });
}

export type ActionResult = { ok: boolean; message: string };

/** Who may apply: an editor with the feature on, for an account they can see. */
async function loadFor(v: Viewer & { clientId: string }, id: string) {
  if (!canEdit(v)) return { error: "Read-only users cannot change ad accounts." } as const;
  if (!canFeature(v, "actions.apply")) return { error: "Applying changes is switched off for your account. Ask a workspace owner to turn it on in Team." } as const;
  const action = await db.action.findFirst({ where: { id, clientId: v.clientId } });
  if (!action) return { error: "This suggestion no longer exists." } as const;
  const account = await db.adAccount.findFirst({ where: { id: action.accountId, connection: { clientId: v.clientId }, ...accessWhere(v.access) }, include: { connection: true } });
  if (!account) return { error: "You don't have access to this ad account." } as const;
  return { action, account } as const;
}

/** Carries out one change on the platform, then records it. */
async function carryOut(account: { platform: string; currency: string; connection: { isDemo: boolean; scopes: string; tokenEnc: string | null } }, e: Entity, change: { status?: "ACTIVE" | "PAUSED"; dailyBudget?: number }) {
  const conn = account.connection;
  if (conn.isDemo) return; // demo logins: recorded only
  if (account.platform !== "META") throw new Error("Changes can be applied on Meta accounts only for now — make this one in the platform's own ads manager.");
  if (!conn.scopes.split(",").includes(WRITE_SCOPE)) throw new Error("This Meta login can only read. Reconnect it with “Connect with split tests” on the Ad accounts page.");
  const token = decrypt(conn.tokenEnc);
  if (change.status) await setStatus(token, e.externalId, change.status);
  if (change.dailyBudget != null) await setDailyBudget(token, e.externalId, change.dailyBudget, account.currency);
}

export async function applyAction(v: Viewer & { clientId: string }, id: string): Promise<ActionResult> {
  const r = await loadFor(v, id);
  if ("error" in r) return { ok: false, message: r.error! };
  const { action, account } = r;
  if (action.status !== "proposed") return { ok: false, message: "This suggestion was already handled." };
  const e = await entityOf(action);
  if (!e) return { ok: false, message: "The campaign, ad set or ad no longer exists." };
  const p = (action.payload ?? {}) as { to?: number; percent?: number };
  const change: { status?: "ACTIVE" | "PAUSED"; dailyBudget?: number } = {};
  if (action.kind === "pause") change.status = "PAUSED";
  else if (action.kind === "start") change.status = "ACTIVE";
  else if (action.kind === "budget") {
    if (e.dailyBudget == null || !p.to) return { ok: false, message: "This item has no daily budget of its own (the budget is probably set on the campaign)." };
    // Recomputed against today's budget, and never more than the allowed step.
    const step = Math.max(-MAX_BUDGET_STEP, Math.min(MAX_BUDGET_STEP, p.percent != null ? p.percent / 100 : p.to / e.dailyBudget - 1));
    change.dailyBudget = Math.round(e.dailyBudget * (1 + step) * 100) / 100;
  }
  const before = { status: e.status, dailyBudget: e.dailyBudget };
  try {
    await carryOut(account, e, change);
  } catch (err) {
    await db.action.update({ where: { id }, data: { status: "failed", error: (err as Error).message.slice(0, 500), decidedById: v.id, decidedAt: new Date() } });
    return { ok: false, message: (err as Error).message };
  }
  await saveLocal(action, change);
  await db.action.update({ where: { id }, data: { status: "applied", before, after: { ...before, ...change }, decidedById: v.id, decidedAt: new Date(), error: "" } });
  if (!account.connection.isDemo) await syncNow(account.id);
  return { ok: true, message: `${action.kind === "pause" ? "Paused" : action.kind === "start" ? "Switched on" : `Daily budget set to ${change.dailyBudget}`}: ${e.name}` };
}

/** Puts back what an applied action changed. */
export async function undoAction(v: Viewer & { clientId: string }, id: string): Promise<ActionResult> {
  const r = await loadFor(v, id);
  if ("error" in r) return { ok: false, message: r.error! };
  const { action, account } = r;
  if (action.status !== "applied" || !action.before) return { ok: false, message: "Only an applied change can be undone." };
  const e = await entityOf(action);
  if (!e) return { ok: false, message: "The campaign, ad set or ad no longer exists." };
  const b = action.before as { status?: string; dailyBudget?: number | null };
  const change: { status?: "ACTIVE" | "PAUSED"; dailyBudget?: number } = {};
  if (action.kind === "budget") { if (b.dailyBudget != null) change.dailyBudget = b.dailyBudget; }
  else change.status = b.status === "PAUSED" ? "PAUSED" : "ACTIVE";
  try {
    await carryOut(account, e, change);
  } catch (err) {
    return { ok: false, message: (err as Error).message };
  }
  await saveLocal(action, change);
  await db.action.update({ where: { id }, data: { status: "undone", decidedById: v.id, decidedAt: new Date() } });
  if (!account.connection.isDemo) await syncNow(account.id);
  return { ok: true, message: `Undone: ${e.name}` };
}

export async function dismissAction(v: Viewer & { clientId: string }, id: string): Promise<ActionResult> {
  if (!canEdit(v)) return { ok: false, message: "Read-only users cannot dismiss suggestions." };
  const n = await db.action.updateMany({ where: { id, clientId: v.clientId, status: "proposed" }, data: { status: "dismissed", decidedById: v.id, decidedAt: new Date() } });
  return n.count ? { ok: true, message: "Dismissed — it won't be suggested again for a week." } : { ok: false, message: "This suggestion was already handled." };
}
