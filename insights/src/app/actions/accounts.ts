"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { db } from "@/lib/db";
import { lastNDays, ymd } from "@/lib/dates";
import { enqueueInitialSync, enqueueSync } from "@/lib/jobs";
import { canEdit, requireWorkspace } from "@/lib/tenancy";

/** The signed-in editor's client — every mutation below is checked against it. */
async function editor() {
  const v = await requireWorkspace();
  if (!canEdit(v)) throw new Error("Read-only user");
  return v;
}

async function ownAccount(clientId: string, accountId: string) {
  const a = await db.adAccount.findFirst({ where: { id: accountId, connection: { clientId } } });
  if (!a) throw new Error("Account not found");
  return a;
}

export async function assignAccountAction(form: FormData) {
  const v = await editor();
  const accountId = String(form.get("accountId"));
  const companyId = String(form.get("companyId") || "");
  const acc = await ownAccount(v.clientId, accountId);
  if (companyId && !(await db.company.findFirst({ where: { id: companyId, clientId: v.clientId } }))) throw new Error("Company not found");
  await db.adAccount.update({ where: { id: accountId }, data: { companyId: companyId || null } });
  // First assignment starts the backfill straight away instead of waiting for the scheduler.
  if (companyId && !acc.lastSyncAt && !(await db.syncRun.count({ where: { accountId, status: { in: ["QUEUED", "RUNNING"] } } }))) {
    await enqueueInitialSync(accountId);
  }
  revalidatePath("/connections");
}

export async function toggleAccountAction(form: FormData) {
  const v = await editor();
  const acc = await ownAccount(v.clientId, String(form.get("accountId")));
  await db.adAccount.update({ where: { id: acc.id }, data: { enabled: !acc.enabled } });
  revalidatePath("/connections");
}

export async function syncNowAction(form: FormData) {
  const v = await editor();
  const acc = await ownAccount(v.clientId, String(form.get("accountId")));
  const r = lastNDays(28);
  await enqueueSync(acc.id, r.from, ymd(new Date()));
  revalidatePath("/connections");
}

export async function removeConnectionAction(form: FormData) {
  const v = await editor();
  await db.adConnection.deleteMany({ where: { id: String(form.get("connectionId")), clientId: v.clientId } });
  revalidatePath("/connections");
}

const companySchema = z.object({ name: z.string().trim().min(1).max(160), website: z.string().trim().max(255).optional().default(""), industry: z.string().trim().max(120).optional().default("") });

export async function createCompanyAction(form: FormData) {
  const v = await editor();
  const d = companySchema.parse({ name: form.get("name"), website: form.get("website") ?? "", industry: form.get("industry") ?? "" });
  await db.company.create({ data: { clientId: v.clientId, ...d } });
  revalidatePath("/companies");
}

export async function updateCompanyAction(form: FormData) {
  const v = await editor();
  const d = companySchema.parse({ name: form.get("name"), website: form.get("website") ?? "", industry: form.get("industry") ?? "" });
  await db.company.updateMany({ where: { id: String(form.get("id")), clientId: v.clientId }, data: d });
  revalidatePath("/companies");
}

export async function deleteCompanyAction(form: FormData) {
  const v = await editor();
  await db.company.deleteMany({ where: { id: String(form.get("id")), clientId: v.clientId } });
  revalidatePath("/companies");
}

export async function setBudgetAction(form: FormData) {
  const v = await editor();
  const companyId = String(form.get("companyId"));
  if (!(await db.company.findFirst({ where: { id: companyId, clientId: v.clientId } }))) throw new Error("Company not found");
  const amount = Number(form.get("amount"));
  const now = new Date();
  const month = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1));
  const existing = await db.budget.findFirst({ where: { companyId, platform: null, month } });
  if (!Number.isFinite(amount) || amount <= 0) {
    if (existing) await db.budget.delete({ where: { id: existing.id } });
  } else if (existing) await db.budget.update({ where: { id: existing.id }, data: { amount } });
  else await db.budget.create({ data: { companyId, month, amount, currency: (await db.client.findUniqueOrThrow({ where: { id: v.clientId } })).reportingCurrency } });
  revalidatePath("/companies");
}
