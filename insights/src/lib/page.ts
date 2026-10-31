import "server-only";
import type { Platform } from "@prisma/client";
import { db } from "@/lib/db";
import { freshness, refreshIfStale } from "@/lib/jobs";
import { previousRange, rangeFromParams } from "@/lib/dates";
import { getDict } from "@/lib/i18n";
import { platformFromSlug } from "@/lib/platforms";
import type { Scope } from "@/lib/queries";
import { requireWorkspace, scopedAccountIds } from "@/lib/tenancy";

export type SP = Record<string, string | string[] | undefined>;

const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

/** Everything a client dashboard page needs from the URL and the session. */
export async function pageContext(spP: Promise<SP>) {
  const sp = await spP;
  const viewer = await requireWorkspace();
  const client = await db.client.findUniqueOrThrow({ where: { id: viewer.clientId } });
  const companies = await db.company.findMany({ where: { clientId: client.id }, orderBy: { name: "asc" }, select: { id: true, name: true } });

  const companyId = companies.some((c) => c.id === one(sp.company)) ? one(sp.company) : undefined;
  const platform: Platform | undefined = platformFromSlug(one(sp.platform)) ?? undefined;
  const range = rangeFromParams({ from: one(sp.from), to: one(sp.to), days: one(sp.days), period: one(sp.period) });
  // ?acc=id1,id2 — any subset of the client's ad accounts; ids that aren't theirs are dropped by the scope.
  const acc = (Array.isArray(sp.acc) ? sp.acc.join(",") : sp.acc ?? "").split(",").filter(Boolean).slice(0, 500);
  const accountIds = await scopedAccountIds(client.id, { companyId, platform, accountIds: acc.length ? acc : undefined });
  // Opening a report refreshes any of its accounts whose numbers are more than five minutes old.
  await refreshIfStale(accountIds);
  const fresh = await freshness(accountIds);
  const accounts = await db.adAccount.findMany({
    where: { connection: { clientId: client.id }, enabled: true, companyId: { not: null } },
    select: { id: true, name: true, platform: true, externalId: true, company: { select: { name: true } } }, orderBy: [{ platform: "asc" }, { name: "asc" }],
  });
  const scope: Scope = { accountIds, currency: client.reportingCurrency };

  return {
    sp, one: (k: string) => one(sp[k]), viewer, client, companies, companyId, platform, range, acc, freshness: fresh,
    accounts: accounts.map((a) => ({ id: a.id, name: a.name, platform: a.platform, externalId: a.externalId, company: a.company?.name ?? "" })),
    prev: previousRange(range), scope, t: await getDict(),
  };
}

/** Rebuilds the current query string with some keys replaced, for links that keep filters. */
export function qs(sp: SP, patch: Record<string, string | undefined> = {}): string {
  const u = new URLSearchParams();
  for (const [k, v] of Object.entries(sp)) if (typeof v === "string" && v) u.set(k, v);
  for (const [k, v] of Object.entries(patch)) (v ? u.set(k, v) : u.delete(k));
  const s = u.toString();
  return s ? `?${s}` : "";
}
