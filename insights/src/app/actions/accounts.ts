"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { db } from "@/lib/db";
import { enqueueInitialSync, syncAll, syncBreakdownsNow, syncNow } from "@/lib/jobs";
import { accessWhere, canEdit, canSeeCompany, requireModule, scopedAccountIds, type Viewer } from "@/lib/tenancy";
import type { ModuleKey } from "@/lib/modules";

/** The signed-in editor's client — every mutation below is checked against it. */
async function editor(m: ModuleKey = "connections") {
  const v = await requireModule(m);
  if (!canEdit(v)) throw new Error("Read-only user");
  return v;
}

/** Changes that affect the whole workspace: not for users limited to some companies. */
async function organiser(m: ModuleKey = "connections") {
  const v = await editor(m);
  if (v.access) throw new Error("Only users with access to every ad account can do this — ask an owner.");
  return v;
}

/** Limits a query to the companies a user may touch. */
async function ownAccount(v: Viewer & { clientId: string }, accountId: string) {
  const a = await db.adAccount.findFirst({ where: { id: accountId, connection: { clientId: v.clientId }, ...accessWhere(v.access) } });
  if (!a) throw new Error("Account not found");
  return a;
}

async function ownCompany(v: Viewer & { clientId: string }, companyId: string) {
  if (!canSeeCompany(v, companyId)) throw new Error("Company not found");
  if (!(await db.company.findFirst({ where: { id: companyId, clientId: v.clientId } }))) throw new Error("Company not found");
}

export async function assignAccountAction(form: FormData) {
  const v = await organiser();
  const accountId = String(form.get("accountId"));
  const companyId = String(form.get("companyId") || "");
  const acc = await ownAccount(v, accountId);
  if (companyId) await ownCompany(v, companyId);
  await db.adAccount.update({ where: { id: accountId }, data: { companyId: companyId || null } });
  // First assignment starts the backfill straight away instead of waiting for the scheduler.
  if (companyId && !acc.lastSyncAt && !(await db.syncRun.count({ where: { accountId, status: { in: ["QUEUED", "RUNNING"] } } }))) {
    await enqueueInitialSync(accountId);
  }
  revalidatePath("/connections");
  revalidatePath("/welcome");
}

export async function syncAllAction() {
  const v = await editor();
  if (v.access) {
    const accounts = await db.adAccount.findMany({ where: { enabled: true, connection: { clientId: v.clientId, status: "active" }, ...accessWhere(v.access) }, select: { id: true } });
    for (const a of accounts) await syncNow(a.id);
  } else await syncAll(v.clientId);
  revalidatePath("/connections");
}

export async function toggleAccountAction(form: FormData) {
  const v = await organiser();
  const acc = await ownAccount(v, String(form.get("accountId")));
  await db.adAccount.update({ where: { id: acc.id }, data: { enabled: !acc.enabled } });
  revalidatePath("/connections");
  revalidatePath("/welcome");
}

export async function syncNowAction(form: FormData) {
  const v = await editor();
  const acc = await ownAccount(v, String(form.get("accountId")));
  await syncNow(acc.id);
  revalidatePath("/connections");
  revalidatePath("/welcome");
}

export async function removeConnectionAction(form: FormData) {
  const v = await organiser();
  await db.adConnection.deleteMany({ where: { id: String(form.get("connectionId")), clientId: v.clientId } });
  revalidatePath("/connections");
  revalidatePath("/welcome");
}

const companySchema = z.object({ name: z.string().trim().min(1).max(160), website: z.string().trim().max(255).optional().default(""), industry: z.string().trim().max(120).optional().default("") });

export async function createCompanyAction(form: FormData) {
  const v = await organiser("companies");
  const d = companySchema.parse({ name: form.get("name"), website: form.get("website") ?? "", industry: form.get("industry") ?? "" });
  await db.company.create({ data: { clientId: v.clientId, ...d } });
  revalidatePath("/companies");
  revalidatePath("/welcome");
}

export async function updateCompanyAction(form: FormData) {
  const v = await editor("companies");
  const d = companySchema.parse({ name: form.get("name"), website: form.get("website") ?? "", industry: form.get("industry") ?? "" });
  const id = String(form.get("id"));
  if (!canSeeCompany(v, id)) throw new Error("Company not found");
  await db.company.updateMany({ where: { id, clientId: v.clientId }, data: d });
  revalidatePath("/companies");
  revalidatePath("/welcome");
}

export async function deleteCompanyAction(form: FormData) {
  const v = await organiser("companies");
  await db.company.deleteMany({ where: { id: String(form.get("id")), clientId: v.clientId } });
  revalidatePath("/companies");
  revalidatePath("/welcome");
}

export async function setBudgetAction(form: FormData) {
  const v = await editor("companies");
  const companyId = String(form.get("companyId"));
  await ownCompany(v, companyId);
  const amount = Number(form.get("amount"));
  const now = new Date();
  const month = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1));
  const existing = await db.budget.findFirst({ where: { companyId, platform: null, month } });
  if (!Number.isFinite(amount) || amount <= 0) {
    if (existing) await db.budget.delete({ where: { id: existing.id } });
  } else if (existing) await db.budget.update({ where: { id: existing.id }, data: { amount } });
  else await db.budget.create({ data: { companyId, month, amount, currency: (await db.client.findUniqueOrThrow({ where: { id: v.clientId } })).reportingCurrency } });
  revalidatePath("/companies");
  revalidatePath("/welcome");
}

/** Ticked accounts on the Ad accounts page: assign to a company, sync now, enable or disable. */
export async function bulkAccountsAction(form: FormData) {
  const v = await editor();
  const ids = form.getAll("ids").map(String);
  if (!ids.length) return;
  const accounts = await db.adAccount.findMany({ where: { id: { in: ids }, connection: { clientId: v.clientId }, ...accessWhere(v.access) } });
  const op = String(form.get("op"));
  if (op !== "sync" && v.access) throw new Error("Only users with access to every ad account can do this — ask an owner.");
  if (op === "assign") {
    const companyId = String(form.get("companyId") || "");
    if (companyId) await ownCompany(v, companyId);
    await db.adAccount.updateMany({ where: { id: { in: accounts.map((a) => a.id) } }, data: { companyId: companyId || null } });
    if (companyId) for (const a of accounts) if (!a.lastSyncAt && !(await db.syncRun.count({ where: { accountId: a.id, status: { in: ["QUEUED", "RUNNING"] } } }))) await enqueueInitialSync(a.id);
  } else if (op === "sync") {
    for (const a of accounts) if (a.enabled) await syncNow(a.id);
  } else if (op === "enable" || op === "disable") {
    await db.adAccount.updateMany({ where: { id: { in: accounts.map((a) => a.id) } }, data: { enabled: op === "enable" } });
  }
  revalidatePath("/connections");
  revalidatePath("/welcome");
}

/**
 * "Sync breakdowns now" on a campaign's Breakdowns tab, Performance or Audiences.
 * Anyone who can open the page may ask; only accounts they can see are queued.
 */
export async function syncBreakdownsAction(form: FormData) {
  const m = (["audiences", "performance"] as const).find((x) => x === form.get("module")) ?? "campaigns";
  const v = await requireModule(m);
  const asked = form.getAll("accountId").map(String).slice(0, 300);
  const ids = asked.length ? await scopedAccountIds(v.clientId, { access: v.access, accountIds: asked }) : [];
  await syncBreakdownsNow(ids);
  revalidatePath(String(form.get("path") || "/audiences"));
}
