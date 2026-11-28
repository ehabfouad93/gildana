import { createHash } from "node:crypto";
import { db } from "@/lib/db";
import { identify, liveId } from "@/modules/identity/service";
import type { RawId } from "@/modules/identity/rules";
import { EVENT_LABEL, matchProjects } from "./profile";
import { projectsOf, recordConsent, refreshCustomer } from "./service";

/**
 * Server-side events (CRM stages, meetings, reservations, purchases, WhatsApp)
 * from /api/v1 and the CRM adapters. Worker-safe.
 */
export type EventInput = {
  eventId: string; type: string; occurredAt?: Date; source: string; customerId?: string; ids?: RawId[]; companyId?: string | null;
  project?: string; title?: string; value?: number; currency?: string; properties?: Record<string, unknown>;
};

export async function recordEvent(clientId: string, e: EventInput, opts: { refresh?: boolean } = {}): Promise<{ customerId: string; duplicate: boolean }> {
  const at = e.occurredAt ?? new Date();
  let customerId: string | null = null;
  if (e.customerId) {
    const c = await db.customer.findFirst({ where: { id: e.customerId, clientId }, select: { id: true } });
    if (!c) throw new Error("customer_id not found in this workspace");
    customerId = await liveId(c.id);
  }
  if (e.ids?.length) {
    const r = await identify({ clientId, companyId: e.companyId, ids: e.ids, source: e.source, at });
    if (r) customerId = customerId ?? r.customerId;
  }
  if (!customerId) throw new Error("The identifiers don't identify anyone (send a phone, email, CRM ID or account ID).");

  const projects = e.project ? await projectsOf(clientId) : [];
  const projectId = e.project ? (projects.find((p) => p.id === e.project)?.id ?? matchProjects(e.project, projects)[0] ?? null) : null;
  // Event ids are the caller's; namespacing by source keeps a CRM id and a WhatsApp id from colliding.
  const dedupKey = `${e.source}:${createHash("sha256").update(e.eventId).digest("hex").slice(0, 40)}`;
  const props = JSON.parse(JSON.stringify({ ...(e.properties ?? {}), ...(e.value != null ? { value: e.value } : {}), ...(e.currency ? { currency: e.currency } : {}), ...(e.project && !projectId ? { project: e.project } : {}) }));
  const existing = await db.customerEvent.findUnique({ where: { clientId_dedupKey: { clientId, dedupKey } }, select: { id: true } });
  if (existing) return { customerId, duplicate: true };
  await db.customerEvent.create({
    data: { clientId, customerId, source: e.source, type: e.type, occurredAt: at, projectId, title: e.title ?? EVENT_LABEL[e.type] ?? e.type, props, dedupKey },
  }).catch((err) => { if ((err as { code?: string }).code !== "P2002") throw err; });
  if (opts.refresh !== false) await refreshCustomer(customerId);
  return { customerId, duplicate: false };
}

export { recordConsent };
