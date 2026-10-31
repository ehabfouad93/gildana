import { db } from "@/lib/db";
import { addDays, lastNDays, toDate, ymd } from "@/lib/dates";

/** Queues a sync window for an account. Long windows are split so one job stays short. */
export async function enqueueSync(accountId: string, from: string, to: string, kind = "stats", chunkDays = 31, priority = 0) {
  const jobs = [];
  for (let start = from; start <= to; start = addDays(start, chunkDays)) {
    const end = addDays(start, chunkDays - 1) < to ? addDays(start, chunkDays - 1) : to;
    jobs.push({ accountId, kind, fromDate: toDate(start), toDate: toDate(end), priority });
  }
  // newest window first, so recent numbers appear before the backfill finishes
  await db.syncRun.createMany({ data: jobs.reverse() });
  return jobs.length;
}

/** First sync for a newly assigned account: a backfill of BACKFILL_DAYS (default 90). */
/**
 * First sync for a new account: the latest 31 days first (priority 6, so every
 * new account shows recent numbers within minutes), then older history at
 * priority 1, back to BACKFILL_DAYS (default 90).
 */
export async function enqueueInitialSync(accountId: string) {
  const days = Math.min(1100, Number(process.env.BACKFILL_DAYS) || 90);
  const today = ymd(new Date());
  const recentFrom = addDays(today, -30);
  await enqueueSync(accountId, recentFrom, today, "backfill", 31, 6);
  const r = lastNDays(days);
  if (r.from < recentFrom) await enqueueSync(accountId, r.from, addDays(recentFrom, -1), "backfill", 31, 1);
  return 1;
}

/** Puts new accounts in a company when it's obvious: the client has one company, or the account name contains a company's name. */
export async function autoAssign(clientId: string, accountIds: string[]) {
  const companies = await db.company.findMany({ where: { clientId }, select: { id: true, name: true } });
  if (!companies.length) return;
  const accounts = await db.adAccount.findMany({ where: { id: { in: accountIds }, companyId: null }, select: { id: true, name: true } });
  const norm = (s: string) => s.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, " ").trim();
  for (const a of accounts) {
    const hit = companies.length === 1 ? companies[0] : companies.find((c) => norm(c.name).length >= 3 && norm(a.name).includes(norm(c.name)));
    if (hit) await db.adAccount.update({ where: { id: a.id }, data: { companyId: hit.id } });
  }
}

/** "Sync all": every enabled account of a client, to the front of the queue. */
export async function syncAll(clientId: string): Promise<number> {
  const accounts = await db.adAccount.findMany({ where: { enabled: true, connection: { clientId, status: "active" } }, select: { id: true, lastSyncAt: true } });
  for (const a of accounts) {
    if (!a.lastSyncAt && !(await db.syncRun.count({ where: { accountId: a.id, status: { in: ["QUEUED", "RUNNING"] } } }))) await enqueueInitialSync(a.id);
    else await syncNow(a.id);
  }
  return accounts.length;
}

/**
 * "Sync now": refresh the last 28 days ahead of everything else. If the
 * account already has jobs waiting, they are moved to the front instead of
 * adding duplicates. Returns how many jobs are now waiting for the account.
 */
export async function syncNow(accountId: string): Promise<number> {
  const waiting = await db.syncRun.updateMany({ where: { accountId, status: "QUEUED" }, data: { priority: 10, runAfter: new Date() } });
  const running = await db.syncRun.count({ where: { accountId, status: "RUNNING" } });
  if (!waiting.count && !running) {
    const r = lastNDays(28);
    return enqueueSync(accountId, r.from, ymd(new Date()), "stats", 31, 10);
  }
  return waiting.count + running;
}

/** Minutes between automatic "today + yesterday" refreshes (LIVE_SYNC_MINUTES, default 15). */
export const liveMinutes = () => Math.max(5, Number(process.env.LIVE_SYNC_MINUTES) || 15);

/**
 * Live refresh: re-pull today and yesterday. Skipped when a waiting or running
 * job already covers today, so page views and the scheduler never pile up jobs.
 */
export async function enqueueLive(accountId: string, priority = 5): Promise<boolean> {
  const yesterday = toDate(addDays(ymd(new Date()), -1));
  // Several renders can ask at the same instant; the per-account lock makes check-then-insert atomic.
  return db.$transaction(async (tx) => {
    await tx.$executeRaw`SELECT pg_advisory_xact_lock(hashtext(${"live:" + accountId}))`;
    const busy = await tx.syncRun.count({ where: { accountId, status: { in: ["QUEUED", "RUNNING"] }, toDate: { gte: yesterday } } });
    if (busy) return false;
    await tx.syncRun.create({ data: { accountId, kind: "live", fromDate: yesterday, toDate: toDate(ymd(new Date())), priority } });
    return true;
  });
}

// Accounts checked recently by a page view, so every render doesn't hit the database again.
const checkedAt = new Map<string, number>();

/** When someone opens a report, refresh any of its accounts whose numbers are older than five minutes. */
export async function refreshIfStale(accountIds: string[], olderThanMs = 5 * 60_000) {
  const now = Date.now();
  const due = accountIds.filter((id) => now - (checkedAt.get(id) ?? 0) > 60_000);
  if (!due.length) return;
  for (const id of due) checkedAt.set(id, now);
  if (checkedAt.size > 20_000) checkedAt.clear();
  const stale = await db.adAccount.findMany({
    where: { id: { in: due }, enabled: true, lastSyncAt: { lt: new Date(Date.now() - olderThanMs) }, connection: { status: "active" } },
    select: { id: true },
  });
  for (const a of stale) await enqueueLive(a.id, 8);
}

/** "Updated 3 min ago" for a set of accounts, and whether a refresh is in progress. */
export async function freshness(accountIds: string[]): Promise<{ updatedAt: string | null; syncing: boolean }> {
  if (!accountIds.length) return { updatedAt: null, syncing: false };
  const [oldest, busy] = await Promise.all([
    db.adAccount.aggregate({ where: { id: { in: accountIds }, enabled: true }, _min: { lastSyncAt: true } }),
    db.syncRun.count({ where: { accountId: { in: accountIds }, status: { in: ["QUEUED", "RUNNING"] } } }),
  ]);
  return { updatedAt: oldest._min.lastSyncAt?.toISOString() ?? null, syncing: busy > 0 };
}
