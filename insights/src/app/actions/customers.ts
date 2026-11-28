"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { db } from "@/lib/db";
import { canEdit, canFeature, canManageTeam, canSeeCompany, requireModule, requireWorkspace, type Viewer } from "@/lib/tenancy";
import { approveReview, rejectReview, unmerge } from "@/modules/identity/service";
import { forgetProjects, refreshCustomer, resolveLeads } from "@/modules/customers/service";
import { getCustomer } from "@/modules/customers/query";
import { SCOPES, newKey, type Scope } from "@/modules/api/http";

const access = (v: Viewer & { clientId: string }) => ({ clientId: v.clientId, companyIds: v.access ? v.companyIds : null, pii: false });

async function log(v: Viewer & { clientId: string }, action: string, target: string, detail: Record<string, unknown> = {}) {
  await db.auditLog.create({ data: { clientId: v.clientId, userId: v.id, action, target, detail: JSON.parse(JSON.stringify(detail)) } });
}

async function reviewer() {
  const v = await requireModule("customers");
  if (!canEdit(v) || !canFeature(v, "customers.review")) throw new Error("You can't review identities.");
  return v;
}

/** Both profiles of a suggestion must be visible to the reviewer. */
async function visibleLink(v: Viewer & { clientId: string }, id: string) {
  const l = await db.identityLink.findFirst({ where: { id, clientId: v.clientId } });
  if (!l) throw new Error("Not found.");
  for (const c of [l.customerId, l.otherCustomerId].filter(Boolean) as string[]) {
    const row = await db.customer.findUnique({ where: { id: c }, select: { companyId: true } });
    if (v.access && (!row?.companyId || !canSeeCompany(v, row.companyId))) throw new Error("Not found.");
  }
  return l;
}

export async function approveReviewAction(form: FormData) {
  const v = await reviewer();
  const l = await visibleLink(v, String(form.get("id")));
  const survivor = await approveReview(v.clientId, l.id, v.id);
  await refreshCustomer(survivor);
  await log(v, "identity.merge", survivor, { link: l.id, other: l.otherCustomerId === survivor ? l.customerId : l.otherCustomerId });
  revalidatePath("/customers");
}

export async function rejectReviewAction(form: FormData) {
  const v = await reviewer();
  const l = await visibleLink(v, String(form.get("id")));
  await rejectReview(v.clientId, l.id, v.id);
  await log(v, "identity.keep_apart", l.customerId, { link: l.id, other: l.otherCustomerId });
  revalidatePath("/customers/review");
}

export async function unmergeAction(form: FormData) {
  const v = await reviewer();
  const l = await visibleLink(v, String(form.get("id")));
  const r = await unmerge(v.clientId, l.id, v.id);
  await refreshCustomer(r.kept);
  await refreshCustomer(r.restored);
  await log(v, "identity.unmerge", r.kept, { link: l.id, restored: r.restored });
  revalidatePath(`/customers/${r.kept}`);
  redirect(`/customers/${r.restored}?restored=1`);
}

/**
 * Deletes a profile with its identifiers, timeline and consent. Its ad leads stay
 * (they belong to the ad account) but are not turned into a profile again.
 */
export async function deleteCustomerAction(form: FormData) {
  const v = await requireModule("customers");
  if (!canEdit(v) || !canFeature(v, "customers.delete")) throw new Error("You can't delete profiles.");
  const c = await getCustomer(access(v), String(form.get("id")));
  if (!c) throw new Error("Not found.");
  await db.$transaction([
    db.lead.updateMany({ where: { customerId: c.id }, data: { customerId: "deleted" } }),
    db.identityLink.deleteMany({ where: { clientId: v.clientId, OR: [{ customerId: c.id }, { otherCustomerId: c.id }] } }),
    db.customer.deleteMany({ where: { OR: [{ id: c.id }, { mergedIntoId: c.id }] } }),
  ]);
  await log(v, "customers.delete", c.id, { name: c.name ? "(set)" : "", identifiers: c.identifiers.length });
  revalidatePath("/customers");
  redirect("/customers?deleted=1");
}

/** Turns this workspace's synced ad leads into profiles now, instead of waiting for the worker. */
export async function resolveNowAction() {
  const v = await requireModule("customers");
  if (!canEdit(v)) throw new Error("Read-only.");
  await resolveLeads({ clientId: v.clientId, budgetMs: 25_000 });
  revalidatePath("/customers");
}

// ───────── projects ─────────

async function projectEditor() {
  const v = await requireModule("customers");
  if (!canEdit(v) || !canFeature(v, "customers.projects")) throw new Error("You can't manage projects.");
  return v;
}

const slugify = (s: string) => s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9؀-ۿ]+/g, "-").replace(/^-|-$/g, "").slice(0, 60);
const num = (v: FormDataEntryValue | null) => { const n = Number(String(v ?? "").replace(/[^\d.]/g, "")); return Number.isFinite(n) && n > 0 ? n : null; };

export async function saveProjectAction(form: FormData) {
  const v = await projectEditor();
  const name = String(form.get("name") ?? "").trim().slice(0, 80);
  if (!name) throw new Error("Name the project.");
  const id = String(form.get("id") ?? "");
  const companyId = String(form.get("companyId") || "") || null;
  if (companyId && !canSeeCompany(v, companyId)) throw new Error("Company not found.");
  const data = {
    name, slug: slugify(String(form.get("slug") || name)) || slugify(name) || name.slice(0, 60), companyId,
    aliases: String(form.get("aliases") ?? "").split(/[,\n]/).map((a) => a.trim().toLowerCase()).filter(Boolean).slice(0, 30),
    priceFrom: num(form.get("priceFrom")), priceTo: num(form.get("priceTo")), currency: String(form.get("currency") || "EGP").toUpperCase().slice(0, 3),
  };
  if (id) await db.project.updateMany({ where: { id, clientId: v.clientId }, data });
  else await db.project.create({ data: { ...data, clientId: v.clientId } });
  forgetProjects(v.clientId);
  revalidatePath("/customers/projects");
}

export async function deleteProjectAction(form: FormData) {
  const v = await projectEditor();
  await db.project.deleteMany({ where: { id: String(form.get("id")), clientId: v.clientId } });
  forgetProjects(v.clientId);
  revalidatePath("/customers/projects");
}

// ───────── API keys ─────────

export type KeyResult = { ok: boolean; message: string; key?: string };

export async function createApiKeyAction(_: KeyResult | null, form: FormData): Promise<KeyResult> {
  const v = await requireWorkspace();
  if (!canManageTeam(v)) return { ok: false, message: "Only owners can create API keys." };
  const name = String(form.get("name") ?? "").trim().slice(0, 60);
  if (!name) return { ok: false, message: "Name the key (e.g. “Revenect CRM”)." };
  const scopes = SCOPES.map((s) => s.key).filter((s) => form.get(`scope_${s}`)) as Scope[];
  if (!scopes.length) return { ok: false, message: "Pick at least one permission." };
  const k = newKey();
  await db.apiKey.create({ data: { clientId: v.clientId, name, prefix: k.prefix, hash: k.hash, scopes, createdById: v.id } });
  await log(v, "api_key.create", name, { scopes });
  revalidatePath("/settings/api");
  return { ok: true, message: `“${name}” created. Copy the key now — it isn't shown again.`, key: k.key };
}

export async function revokeApiKeyAction(form: FormData) {
  const v = await requireWorkspace();
  if (!canManageTeam(v)) throw new Error("Only owners can revoke API keys.");
  const r = await db.apiKey.updateMany({ where: { id: String(form.get("id")), clientId: v.clientId, revokedAt: null }, data: { revokedAt: new Date() } });
  if (r.count) await log(v, "api_key.revoke", String(form.get("id")));
  revalidatePath("/settings/api");
}
