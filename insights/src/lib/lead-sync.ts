import { createHmac } from "node:crypto";
import { decrypt } from "@/lib/crypto";
import { db } from "@/lib/db";

/**
 * Sends each new lead once to the workspace's destinations (a CRM, Zapier,
 * Make, a Google Sheet script…) as signed JSON. Used by the background worker
 * and by the "send a test" button, so it must not import web-only modules.
 */

export const MAX_ATTEMPTS = 5;

/**
 * A destination must be a public https address — never this server or a
 * private network, which a webhook must not be able to reach. Returns the
 * problem, or null when the address is fine.
 */
export function webhookProblem(raw: string, allowLocal = process.env.LEAD_WEBHOOK_ALLOW_LOCAL === "1"): string | null {
  let u: URL;
  try { u = new URL(raw); } catch { return "Enter a full address starting with https://"; }
  if (allowLocal) return u.protocol === "http:" || u.protocol === "https:" ? null : "Enter an http(s) address.";
  if (u.protocol !== "https:") return "The address must start with https://";
  const h = u.hostname.toLowerCase().replace(/^\[|\]$/g, "");
  const privateHost = h === "localhost" || h.endsWith(".localhost") || h.endsWith(".local") || h.endsWith(".internal") || !h.includes(".") && !h.includes(":")
    || /^(127\.|10\.|192\.168\.|169\.254\.|0\.)/.test(h) || /^172\.(1[6-9]|2\d|3[01])\./.test(h) || h === "::1" || /^f[cd][0-9a-f]{2}:/.test(h) || /^fe80:/.test(h);
  return privateHost ? "That address is on a private network. Use a public https address." : null;
}

export type LeadPayload = {
  id: string; received_at: string; platform: string; account: string; company: string; campaign: string; ad_set: string; ad: string; form: string;
  fields: Record<string, string>;
};

/** `sha256=<hex>` over the exact body, with the destination's secret — the receiver recomputes it to trust the request. */
export const sign = (body: string, secret: string) => `sha256=${createHmac("sha256", secret).update(body).digest("hex")}`;

/** Minutes to wait before retry number `attempts` (1-based): 2, 10, 30, 120. */
export const retryDelayMinutes = (attempts: number) => [2, 10, 30, 120][Math.min(3, Math.max(0, attempts - 1))];

export async function postLead(url: string, secret: string, payload: LeadPayload): Promise<void> {
  const problem = webhookProblem(url);
  if (problem) throw new Error(problem);
  const body = JSON.stringify(payload);
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 15_000);
  try {
    const res = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json", "X-Gildana-Signature": sign(body, secret), "User-Agent": "Gildana-Insights-Leads/1" }, body, signal: ctrl.signal, redirect: "error" });
    if (!res.ok) throw new Error(`The destination answered HTTP ${res.status}`);
  } catch (e) {
    throw new Error((e as Error).name === "AbortError" ? "The destination did not answer within 15 seconds" : (e as Error).message);
  } finally {
    clearTimeout(timer);
  }
}

/** One pass: every enabled destination gets its undelivered leads (those that arrived after it was added). */
export async function deliverLeads(limitPerDestination = 100): Promise<{ sent: number; failed: number }> {
  const dests = await db.leadDestination.findMany({ where: { enabled: true } });
  let sent = 0, failed = 0;
  const now = new Date();
  for (const d of dests) {
    // A destination that just failed is left alone until its retry time, instead of trying the next lead against it.
    if (await db.leadDelivery.count({ where: { destinationId: d.id, status: "failed", attempts: { lt: MAX_ATTEMPTS }, nextTryAt: { gt: now } } })) continue;
    const leads = await db.lead.findMany({
      where: {
        createdAt: { gte: d.createdAt },
        account: { connection: { clientId: d.clientId }, ...(d.companyId ? { companyId: d.companyId } : {}) },
        // not sent yet, not given up, and not waiting for its retry time
        deliveries: { none: { destinationId: d.id, OR: [{ status: "sent" }, { attempts: { gte: MAX_ATTEMPTS } }, { nextTryAt: { gt: now } }] } },
      },
      orderBy: { createdAt: "asc" }, take: limitPerDestination,
      include: { account: { select: { name: true, company: { select: { name: true } } } } },
    });
    if (!leads.length) continue;
    const ids = (k: "campaignId" | "adSetId" | "adId") => [...new Set(leads.flatMap((l) => (l[k] ? [l[k]!] : [])))];
    const [camps, sets, ads] = await Promise.all([
      db.campaign.findMany({ where: { id: { in: ids("campaignId") } }, select: { id: true, name: true } }),
      db.adSet.findMany({ where: { id: { in: ids("adSetId") } }, select: { id: true, name: true } }),
      db.ad.findMany({ where: { id: { in: ids("adId") } }, select: { id: true, name: true } }),
    ]);
    const name = (list: { id: string; name: string }[], id: string | null) => list.find((x) => x.id === id)?.name ?? "";
    const url = decrypt(d.urlEnc), secret = decrypt(d.secretEnc);
    let lastError = "";
    for (const l of leads) {
      const payload: LeadPayload = {
        id: l.externalId, received_at: l.createdAt.toISOString(), platform: l.platform, account: l.account.name, company: l.account.company?.name ?? "",
        campaign: name(camps, l.campaignId), ad_set: name(sets, l.adSetId), ad: name(ads, l.adId), form: l.formName, fields: (l.fields ?? {}) as Record<string, string>,
      };
      const key = { leadId_destinationId: { leadId: l.id, destinationId: d.id } };
      try {
        await postLead(url, secret, payload);
        await db.leadDelivery.upsert({ where: key, create: { leadId: l.id, destinationId: d.id, status: "sent", attempts: 1, sentAt: new Date() }, update: { status: "sent", attempts: { increment: 1 }, sentAt: new Date(), error: "", nextTryAt: null } });
        sent++;
      } catch (e) {
        lastError = (e as Error).message.slice(0, 300);
        const prev = await db.leadDelivery.findUnique({ where: key, select: { attempts: true } });
        const attempts = (prev?.attempts ?? 0) + 1;
        const nextTryAt = new Date(Date.now() + retryDelayMinutes(attempts) * 60_000);
        await db.leadDelivery.upsert({ where: key, create: { leadId: l.id, destinationId: d.id, status: "failed", attempts, error: lastError, nextTryAt }, update: { status: "failed", attempts, error: lastError, nextTryAt } });
        failed++;
        break; // the destination is down: stop this pass, retry later, keep the order
      }
    }
    await db.leadDestination.update({ where: { id: d.id }, data: { lastError, ...(lastError ? {} : { lastSentAt: new Date() }) } });
  }
  return { sent, failed };
}
