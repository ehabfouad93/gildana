import type { Platform } from "@prisma/client";
import { db } from "@/lib/db";
import { decrypt } from "@/lib/crypto";
import { enqueueInitialSync } from "@/lib/jobs";
import { connectorFor } from "@/platforms";

/**
 * "Share access with Gildana" instead of logging in: the client gives
 * Gildana's Business Manager / MCC / Business Center access to an ad account,
 * then requests it here. Gildana's own (agency) login can then see it; an
 * admin approves the request, which creates the account in the client's
 * workspace, synced through the agency login.
 */

/** Ad account ids as people paste them: "act_123", "123-456-7890", spaces… */
export function normalizeAccountId(platform: Platform, raw: string): string {
  const s = raw.trim();
  switch (platform) {
    case "META": return s.replace(/^act_/i, "").replace(/\D/g, "");
    case "GOOGLE": return s.replace(/\D/g, "");
    case "LINKEDIN": return s.replace(/^urn:li:sponsoredAccount:/i, "").replace(/\D/g, "");
    default: return s.replace(/\s/g, "");
  }
}

export async function agencyConnections(platform: Platform) {
  return db.adConnection.findMany({ where: { isAgency: true, platform, status: "active" }, include: { accounts: true } });
}

/** Re-lists what Gildana's logins can see now, then marks matching requests visible. */
export async function refreshClaimVisibility(platform: Platform) {
  for (const conn of await agencyConnections(platform)) {
    if (conn.isDemo) continue;
    try {
      const c = connectorFor(platform, false);
      const accounts = await c.listAccounts(decrypt(conn.tokenEnc));
      await db.adAccount.createMany({
        skipDuplicates: true,
        data: accounts.map((a) => ({ connectionId: conn.id, platform, externalId: a.externalId, parentId: a.parentId ?? "", name: a.name, currency: a.currency, timezone: a.timezone })),
      });
    } catch (e) {
      await db.adConnection.update({ where: { id: conn.id }, data: { lastError: (e as Error).message.slice(0, 500) } });
    }
  }
  const visible = await db.adAccount.findMany({ where: { platform, connection: { isAgency: true, status: "active" } }, select: { externalId: true, name: true } });
  const names = new Map(visible.map((a) => [a.externalId, a.name]));
  for (const c of await db.accountClaim.findMany({ where: { platform, status: "pending" } })) {
    const name = names.get(c.externalId);
    await db.accountClaim.update({ where: { id: c.id }, data: { visible: name !== undefined, ...(name ? { name } : {}) } });
  }
}

export async function requestClaim(clientId: string, platform: Platform, rawId: string, companyId: string | null) {
  const externalId = normalizeAccountId(platform, rawId);
  if (!externalId) throw new Error("Enter the ad account ID.");
  const claim = await db.accountClaim.upsert({
    where: { clientId_platform_externalId: { clientId, platform, externalId } },
    create: { clientId, platform, externalId, companyId },
    update: { companyId, status: "pending", note: "", decidedAt: null },
  });
  await refreshClaimVisibility(platform);
  return claim;
}

export async function approveClaim(claimId: string) {
  const claim = await db.accountClaim.findUniqueOrThrow({ where: { id: claimId } });
  const src = await db.adAccount.findFirst({
    where: { platform: claim.platform, externalId: claim.externalId, connection: { isAgency: true, status: "active" } },
    include: { connection: true },
  });
  if (!src) throw new Error("Gildana can't see this ad account yet — the client still needs to share it.");

  // One "Shared with Gildana" connection per client and agency login.
  let conn = await db.adConnection.findFirst({ where: { clientId: claim.clientId, agencySourceId: src.connectionId } });
  conn ??= await db.adConnection.create({
    data: { clientId: claim.clientId, platform: claim.platform, agencySourceId: src.connectionId, isDemo: src.connection.isDemo, label: "Shared with Gildana", externalUser: src.connection.externalUser },
  });
  const account = await db.adAccount.upsert({
    where: { connectionId_externalId: { connectionId: conn.id, externalId: claim.externalId } },
    create: { connectionId: conn.id, platform: claim.platform, externalId: claim.externalId, parentId: src.parentId, name: src.name, currency: src.currency, timezone: src.timezone, companyId: claim.companyId },
    update: { companyId: claim.companyId ?? undefined, enabled: true },
  });
  await db.accountClaim.update({ where: { id: claim.id }, data: { status: "approved", decidedAt: new Date(), accountId: account.id, name: src.name, visible: true } });
  if (account.companyId && !account.lastSyncAt) await enqueueInitialSync(account.id);
  return account;
}

export async function rejectClaim(claimId: string, note: string) {
  await db.accountClaim.update({ where: { id: claimId }, data: { status: "rejected", note: note.slice(0, 300), decidedAt: new Date() } });
}
