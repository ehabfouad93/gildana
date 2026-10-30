"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { z } from "zod";
import { db } from "@/lib/db";
import { randomToken } from "@/lib/crypto";
import { generateDemoTraffic } from "@/lib/demo-traffic";
import { canEdit, requireWorkspace } from "@/lib/tenancy";

async function editor() {
  const v = await requireWorkspace();
  if (!canEdit(v)) throw new Error("Read-only user");
  return v;
}

async function ownSite(clientId: string, siteId: string) {
  const s = await db.site.findFirst({ where: { id: siteId, company: { clientId } } });
  if (!s) throw new Error("Site not found");
  return s;
}

const domain = z.string().trim().toLowerCase().transform((d) => d.replace(/^https?:\/\//, "").replace(/^www\./, "").replace(/\/.*$/, "")).pipe(z.string().regex(/^[a-z0-9.-]+(:\d+)?$/, "Enter a domain like example.com"));

export async function createSiteAction(form: FormData) {
  const v = await editor();
  const companyId = String(form.get("companyId"));
  if (!(await db.company.findFirst({ where: { id: companyId, clientId: v.clientId } }))) throw new Error("Company not found");
  const d = domain.parse(form.get("domain"));
  const site = await db.site.create({ data: { companyId, domain: d, name: String(form.get("name") || d).slice(0, 120), trackingKey: randomToken(15) } });
  redirect(`/sites/${site.id}`);
}

export async function updateSiteAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("id")));
  await db.site.update({
    where: { id: s.id },
    data: {
      name: String(form.get("name") || s.name).slice(0, 120), domain: domain.parse(form.get("domain") || s.domain),
      replay: form.get("replay") === "on", replayRate: Math.min(100, Math.max(1, Number(form.get("replayRate")) || 20)),
    },
  });
  revalidatePath(`/sites/${s.id}`);
}

export async function deleteSiteAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("id")));
  await db.site.delete({ where: { id: s.id } });
  redirect("/sites");
}

export async function addGoalAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("siteId")));
  const g = z.object({ name: z.string().trim().min(1).max(100), kind: z.enum(["path", "event"]), match: z.string().trim().min(1).max(300) }).parse({ name: form.get("name"), kind: form.get("kind"), match: form.get("match") });
  await db.goal.create({ data: { siteId: s.id, ...g } });
  revalidatePath(`/sites/${s.id}`);
}

export async function deleteGoalAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("siteId")));
  await db.goal.deleteMany({ where: { id: String(form.get("id")), siteId: s.id } });
  revalidatePath(`/sites/${s.id}`);
}

export async function demoTrafficAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("id")));
  if (!s.isDemo) throw new Error("Only demo sites can be filled with generated traffic");
  await generateDemoTraffic(s.id, 30);
  revalidatePath(`/sites/${s.id}`);
}

export async function startCrawlAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("id")));
  if (!(await db.seoCrawl.count({ where: { siteId: s.id, status: { in: ["queued", "running"] } } }))) {
    await db.seoCrawl.create({ data: { siteId: s.id, maxPages: Math.min(2000, Math.max(10, Number(form.get("maxPages")) || 200)) } });
  }
  revalidatePath(`/sites/${s.id}/seo`);
}

export async function startGeoAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("id")));
  if (!(await db.geoAudit.count({ where: { siteId: s.id, status: { in: ["queued", "running"] } } }))) await db.geoAudit.create({ data: { siteId: s.id } });
  revalidatePath(`/sites/${s.id}/geo`);
}

export async function addPromptAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("siteId")));
  const d = z.object({ prompt: z.string().trim().min(5).max(500), brand: z.string().trim().min(1).max(100) }).parse({ prompt: form.get("prompt"), brand: form.get("brand") });
  await db.aiPrompt.create({ data: { siteId: s.id, ...d } });
  revalidatePath(`/sites/${s.id}/geo`);
}

export async function deletePromptAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("siteId")));
  await db.aiPrompt.deleteMany({ where: { id: String(form.get("id")), siteId: s.id } });
  revalidatePath(`/sites/${s.id}/geo`);
}

export async function checkPromptsAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("siteId")));
  const { claudeEnabled } = await import("@/lib/claude");
  if (!claudeEnabled()) throw new Error("Set ANTHROPIC_API_KEY to run AI visibility checks");
  const { runVisibility } = await import("@/worker/geo");
  for (const p of await db.aiPrompt.findMany({ where: { siteId: s.id } })) await runVisibility(p.id);
  revalidatePath(`/sites/${s.id}/geo`);
}

export async function disconnectGscAction(form: FormData) {
  const v = await editor();
  const s = await ownSite(v.clientId, String(form.get("id")));
  await db.site.update({ where: { id: s.id }, data: { gscProperty: "", gscRefreshEnc: null } });
  revalidatePath(`/sites/${s.id}/seo`);
}
