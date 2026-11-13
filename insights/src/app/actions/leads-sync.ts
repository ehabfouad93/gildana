"use server";

import { revalidatePath } from "next/cache";
import { decrypt, encrypt, randomToken } from "@/lib/crypto";
import { db } from "@/lib/db";
import { postLead, webhookProblem } from "@/lib/lead-sync";
import { canEdit, canFeature, canSeeCompany, requireModule } from "@/lib/tenancy";

export type SyncResult = { ok: boolean; message: string; secret?: string };

async function editor() {
  const v = await requireModule("companies");
  if (!canEdit(v) || !canFeature(v, "companies.leads_sync")) return null;
  return v;
}

/** Adds a destination. The signing secret is shown once, here. */
export async function addDestinationAction(_: SyncResult | null, form: FormData): Promise<SyncResult> {
  const v = await editor();
  if (!v) return { ok: false, message: "You can't change leads sync." };
  const name = String(form.get("name") ?? "").trim().slice(0, 80);
  const url = String(form.get("url") ?? "").trim();
  if (!name) return { ok: false, message: "Give the destination a name." };
  const problem = webhookProblem(url);
  if (problem) return { ok: false, message: problem };
  const companyId = String(form.get("companyId") || "") || null;
  if (companyId && (!canSeeCompany(v, companyId) || !(await db.company.findFirst({ where: { id: companyId, clientId: v.clientId } })))) return { ok: false, message: "Company not found." };
  // A user limited to some companies can't send every company's leads.
  if (!companyId && v.access) return { ok: false, message: "Choose one of your companies." };
  const secret = randomToken(24);
  await db.leadDestination.create({ data: { clientId: v.clientId, companyId, name, urlEnc: encrypt(url), secretEnc: encrypt(secret), createdById: v.id } });
  revalidatePath("/leads-sync");
  return { ok: true, message: `“${name}” added. New leads are sent to it within about a minute of arriving.`, secret };
}

export async function testDestinationAction(_: SyncResult | null, form: FormData): Promise<SyncResult> {
  const v = await editor();
  if (!v) return { ok: false, message: "You can't change leads sync." };
  const d = await db.leadDestination.findFirst({ where: { id: String(form.get("id")), clientId: v.clientId } });
  if (!d) return { ok: false, message: "Destination not found." };
  try {
    await postLead(decrypt(d.urlEnc), decrypt(d.secretEnc), {
      id: "test-lead", received_at: new Date().toISOString(), platform: "META", account: "Test account", company: "Test company", campaign: "Test campaign", ad_set: "Test ad set", ad: "Test ad", form: "Test form",
      fields: { full_name: "Test Lead", phone_number: "+201000000000", email: "test@example.com" },
    });
    await db.leadDestination.update({ where: { id: d.id }, data: { lastError: "" } });
    revalidatePath("/leads-sync");
    return { ok: true, message: "Test lead sent — check that it arrived." };
  } catch (e) {
    return { ok: false, message: (e as Error).message };
  }
}

export async function toggleDestinationAction(form: FormData) {
  const v = await editor();
  if (!v) throw new Error("You can't change leads sync.");
  const d = await db.leadDestination.findFirst({ where: { id: String(form.get("id")), clientId: v.clientId } });
  if (d) await db.leadDestination.update({ where: { id: d.id }, data: { enabled: !d.enabled } });
  revalidatePath("/leads-sync");
}

export async function deleteDestinationAction(form: FormData) {
  const v = await editor();
  if (!v) throw new Error("You can't change leads sync.");
  await db.leadDestination.deleteMany({ where: { id: String(form.get("id")), clientId: v.clientId } });
  revalidatePath("/leads-sync");
}
