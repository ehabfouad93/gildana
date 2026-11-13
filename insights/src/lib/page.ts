import "server-only";
import type { Platform } from "@prisma/client";
import { db } from "@/lib/db";
import { freshness, refreshIfStale } from "@/lib/jobs";
import { previousRange, rangeFromParams } from "@/lib/dates";
import { getDict } from "@/lib/i18n";
import { platformFromSlug } from "@/lib/platforms";
import type { Scope } from "@/lib/queries";
import { accessWhere, companyWhere, requireModule, scopedAccountIds } from "@/lib/tenancy";
import type { ModuleKey } from "@/lib/modules";
import type { EntityFilter } from "@/lib/entity-filter";

export type SP = Record<string, string | string[] | undefined>;

const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v) ?? "";

/** Everything a client dashboard page needs from the URL and the session. */
export async function pageContext(spP: Promise<SP>, module: ModuleKey) {
  const sp = await spP;
  const viewer = await requireModule(module);
  const client = await db.client.findUniqueOrThrow({ where: { id: viewer.clientId } });
  // A user limited to some companies sees only those (and never unassigned accounts).
  const limited = !!viewer.access;
  const companies = await db.company.findMany({ where: { clientId: client.id, ...companyWhere(viewer) }, orderBy: { name: "asc" }, select: { id: true, name: true } });

  const companyId = (one(sp.company) === "none" && !limited) || companies.some((c) => c.id === one(sp.company)) ? one(sp.company) : undefined;
  const platform: Platform | undefined = platformFromSlug(one(sp.platform)) ?? undefined;
  const range = rangeFromParams({ from: one(sp.from), to: one(sp.to), days: one(sp.days), period: one(sp.period) }, client.defaultDays);
  // ?acc=id1,id2 — any subset of the client's ad accounts; ids that aren't theirs are dropped by the scope.
  const acc = (Array.isArray(sp.acc) ? sp.acc.join(",") : sp.acc ?? "").split(",").filter(Boolean).slice(0, 500);
  let accountIds = await scopedAccountIds(client.id, { companyId, platform, accountIds: acc.length ? acc : undefined, access: viewer.access });
  // ?camp= / ?set= / ?ad= — a campaign, ad set or ad filter (the most specific one given wins).
  const picked = await entityFromParams(sp, accountIds);
  if (picked) accountIds = picked.accountIds;
  // Opening a report refreshes any of its accounts whose numbers are more than five minutes old.
  await refreshIfStale(accountIds);
  const fresh = await freshness(accountIds);
  const accounts = await db.adAccount.findMany({
    where: { connection: { clientId: client.id }, enabled: true, ...accessWhere(viewer.access) },
    select: { id: true, name: true, platform: true, externalId: true, company: { select: { name: true } } }, orderBy: [{ platform: "asc" }, { name: "asc" }],
  });
  const scope: Scope = { accountIds, currency: client.reportingCurrency, ...(picked ? { entity: picked.filter } : {}) };

  return {
    sp, one: (k: string) => one(sp[k]), viewer, client, companies, limited, entityPick: picked ? { level: picked.filter.level, items: picked.items } : null, companyId, platform, range, acc, freshness: fresh,
    accounts: accounts.map((a) => ({ id: a.id, name: a.name, platform: a.platform, externalId: a.externalId, company: a.company?.name ?? "Unassigned" })),
    prev: previousRange(range), scope, t: await getDict(),
  };
}

const ids = (v: string | string[] | undefined) => (Array.isArray(v) ? v.join(",") : v ?? "").split(",").filter(Boolean).slice(0, 200);

/** Reads the campaign / ad set / ad filter from the URL, keeping only items inside the given accounts. */
async function entityFromParams(sp: SP, accountIds: string[]): Promise<{ filter: EntityFilter; items: { id: string; name: string }[]; accountIds: string[] } | null> {
  const ad = ids(sp.ad), set = ids(sp.set), camp = ids(sp.camp);
  if (!accountIds.length || (!ad.length && !set.length && !camp.length)) return null;
  const uniq = (a: string[]) => [...new Set(a)];
  if (ad.length) {
    const rows = await db.ad.findMany({ where: { id: { in: ad }, accountId: { in: accountIds } }, select: { id: true, name: true, adSetId: true, campaignId: true, accountId: true } });
    if (!rows.length) return null;
    return { filter: { level: "AD", ids: rows.map((x) => x.id), adSetIds: uniq(rows.map((x) => x.adSetId)), campaignIds: uniq(rows.map((x) => x.campaignId)) }, items: rows.map((x) => ({ id: x.id, name: x.name })), accountIds: uniq(rows.map((x) => x.accountId)) };
  }
  if (set.length) {
    const rows = await db.adSet.findMany({ where: { id: { in: set }, accountId: { in: accountIds } }, select: { id: true, name: true, campaignId: true, accountId: true } });
    if (!rows.length) return null;
    return { filter: { level: "ADSET", ids: rows.map((x) => x.id), adSetIds: rows.map((x) => x.id), campaignIds: uniq(rows.map((x) => x.campaignId)) }, items: rows.map((x) => ({ id: x.id, name: x.name })), accountIds: uniq(rows.map((x) => x.accountId)) };
  }
  const rows = await db.campaign.findMany({ where: { id: { in: camp }, accountId: { in: accountIds } }, select: { id: true, name: true, accountId: true } });
  if (!rows.length) return null;
  return { filter: { level: "CAMPAIGN", ids: rows.map((x) => x.id), adSetIds: [], campaignIds: rows.map((x) => x.id) }, items: rows.map((x) => ({ id: x.id, name: x.name })), accountIds: uniq(rows.map((x) => x.accountId)) };
}

/** Rebuilds the current query string with some keys replaced, for links that keep filters. */
export function qs(sp: SP, patch: Record<string, string | undefined> = {}): string {
  const u = new URLSearchParams();
  for (const [k, v] of Object.entries(sp)) if (typeof v === "string" && v) u.set(k, v);
  for (const [k, v] of Object.entries(patch)) (v ? u.set(k, v) : u.delete(k));
  const s = u.toString();
  return s ? `?${s}` : "";
}
