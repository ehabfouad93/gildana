import "server-only";
import type { AdConnection } from "@prisma/client";
import { decrypt } from "@/lib/crypto";
import { db } from "@/lib/db";
import { connectorFor } from "@/platforms";
import type { RemoteAccount } from "@/platforms/types";

/**
 * The ad accounts a login can reach, read live from the platform, and what
 * changes when the person ticks some of them. Lists are kept for a few
 * minutes so opening the page and saving don't ask the platform twice.
 */

const cache = new Map<string, { at: number; list: RemoteAccount[] }>();

/** The token a login uses: a shared Gildana login's for shared connections, a fixed one for demo logins. */
async function tokenOf(conn: AdConnection): Promise<string> {
  if (conn.isDemo) return `demo-token-${conn.platform}`;
  if (conn.agencySourceId) {
    const src = await db.adConnection.findUnique({ where: { id: conn.agencySourceId } });
    if (!src) throw new Error("Gildana's shared login is disconnected.");
    return src.isDemo ? `demo-token-${src.platform}` : decrypt(src.tokenEnc);
  }
  return decrypt(conn.tokenEnc);
}

export async function reachableAccounts(conn: AdConnection, fresh = false): Promise<RemoteAccount[]> {
  const hit = cache.get(conn.id);
  if (!fresh && hit && Date.now() - hit.at < 10 * 60_000) return hit.list;
  const list = await connectorFor(conn.platform, conn.isDemo).listAccounts(await tokenOf(conn));
  cache.set(conn.id, { at: Date.now(), list });
  if (cache.size > 500) cache.clear();
  return list;
}

export async function lookupAccount(conn: AdConnection, externalId: string): Promise<RemoteAccount> {
  const c = connectorFor(conn.platform, conn.isDemo);
  if (!c.getAccount) throw new Error("Adding by ID isn't available for this platform yet.");
  return c.getAccount(await tokenOf(conn), externalId);
}

/** What saving a choice does: accounts to add, to remove, and to keep. Pure; unit-tested. */
export function choiceDiff(existing: string[], reachable: string[], ticked: string[]) {
  const t = new Set(ticked), have = new Set(existing), can = new Set(reachable);
  return {
    add: [...t].filter((id) => !have.has(id) && can.has(id)),
    remove: existing.filter((id) => !t.has(id)),
    keep: existing.filter((id) => t.has(id)),
  };
}

/** Meta's account_status codes in words. */
export const ACCOUNT_STATUS: Record<string, string> = { "1": "active", "2": "disabled", "3": "unsettled (payment due)", "7": "pending review", "8": "pending settlement", "9": "in grace period", "100": "pending closure", "101": "closed", "201": "any active", "202": "any closed" };
