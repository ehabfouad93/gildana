"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { db } from "@/lib/db";
import { reachableAccounts, choiceDiff, lookupAccount } from "@/lib/account-choice";
import { autoAssign, enqueueInitialSync, syncAll, syncBreakdownsNow, syncNow } from "@/lib/jobs";
import { accessWhere, canEdit, canFeature, canSeeCompany, requireModule, scopedAccountIds, type Viewer } from "@/lib/tenancy";
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
  if (op === "remove") {
    if (v.access) throw new Error("Only users with access to every ad account can do this — ask an owner.");
    await db.adAccount.deleteMany({ where: { id: { in: accounts.map((a) => a.id) } } });
    revalidatePath("/connections");
    return;
  }
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
  if (!canFeature(v, "general.sync_breakdowns")) return;
  const asked = form.getAll("accountId").map(String).slice(0, 300);
  const ids = asked.length ? await scopedAccountIds(v.clientId, { access: v.access, accountIds: asked }) : [];
  await syncBreakdownsNow(ids);
  revalidatePath(String(form.get("path") || "/audiences"));
}

// ───────── choosing which ad accounts a login adds ─────────

async function loginFor(v: Viewer & { clientId: string }, connectionId: string) {
  return db.adConnection.findFirst({ where: { id: connectionId, clientId: v.clientId }, include: { accounts: { select: { id: true, externalId: true } } } });
}

/**
 * Saves the ticked accounts of a login: new ones are added (and start
 * syncing), unticked ones are removed from the dashboard with their synced
 * data. Nothing changes on the ad platform.
 */
export async function saveChosenAccountsAction(connectionId: string, ticked: string[]): Promise<{ ok: boolean; message: string }> {
  const v = await organiser();
  const conn = await loginFor(v, connectionId);
  if (!conn) return { ok: false, message: "Login not found." };
  let reachable;
  try { reachable = await reachableAccounts(conn); } catch (e) { return { ok: false, message: `The platform refused to list the accounts: ${(e as Error).message}` }; }
  const d = choiceDiff(conn.accounts.map((a) => a.externalId), reachable.map((a) => a.externalId), ticked.map(String).slice(0, 2000));
  if (d.remove.length) await db.adAccount.deleteMany({ where: { connectionId: conn.id, externalId: { in: d.remove } } });
  const rows = reachable.filter((a) => d.add.includes(a.externalId));
  if (rows.length) {
    await db.adAccount.createMany({ skipDuplicates: true, data: rows.map((a) => ({ connectionId: conn.id, platform: conn.platform, externalId: a.externalId, parentId: a.parentId ?? "", name: a.name, currency: a.currency, timezone: a.timezone })) });
    const created = await db.adAccount.findMany({ where: { connectionId: conn.id, externalId: { in: d.add } }, select: { id: true } });
    await autoAssign(v.clientId, created.map((a) => a.id));
    for (const a of created) await enqueueInitialSync(a.id);
  }
  revalidatePath("/connections");
  revalidatePath("/welcome");
  return { ok: true, message: `Saved: ${d.keep.length + rows.length} account${d.keep.length + rows.length === 1 ? "" : "s"} in the dashboard${rows.length ? `, ${rows.length} added and syncing` : ""}${d.remove.length ? `, ${d.remove.length} removed` : ""}.` };
}

/** Adds one account by its ID, after checking with the platform that this login can read it. */
export async function addAccountByIdAction(connectionId: string, rawId: string): Promise<{ ok: boolean; message: string }> {
  const v = await organiser();
  const conn = await loginFor(v, connectionId);
  if (!conn) return { ok: false, message: "Login not found." };
  const id = String(rawId ?? "").trim().replace(/^act_/, "").replace(/[\s-]/g, "");
  if (!/^\d{5,20}$/.test(id)) return { ok: false, message: "Enter the ad account's number, e.g. 1234567890 (act_ in front is fine)." };
  if (conn.accounts.some((a) => a.externalId === id)) return { ok: false, message: "This account is already in the dashboard." };
  let acc;
  try { acc = await lookupAccount(conn, id); }
  catch (e) {
    return { ok: false, message: `This login can't read that account: ${(e as Error).message}. Add the Facebook user of this login to the ad account (Business Settings → Ad accounts → Add people), then try again — or reconnect and tick it in Meta's window.` };
  }
  const created = await db.adAccount.create({ data: { connectionId: conn.id, platform: conn.platform, externalId: acc.externalId, name: acc.name, currency: acc.currency, timezone: acc.timezone } });
  await autoAssign(v.clientId, [created.id]);
  await enqueueInitialSync(created.id);
  revalidatePath("/connections");
  return { ok: true, message: `“${acc.name}” added and syncing.` };
}

/** Removes accounts from the dashboard (with their synced data). Nothing changes on the ad platform. */
export async function removeAccountsAction(form: FormData) {
  const v = await organiser();
  const ids = form.getAll("ids").map(String).concat(form.getAll("accountId").map(String)).slice(0, 2000);
  if (ids.length) await db.adAccount.deleteMany({ where: { id: { in: ids }, connection: { clientId: v.clientId } } });
  revalidatePath("/connections");
}
