/**
 * Background worker: run with `npm run worker` next to the web process.
 *
 * SyncRun doubles as the queue — jobs are claimed with FOR UPDATE SKIP LOCKED,
 * so several workers can run side by side without taking the same job.
 */
import { db } from "@/lib/db";
import { lastNDays, ymd } from "@/lib/dates";
import { enqueueInitialSync, enqueueSync } from "@/lib/jobs";
import { syncAccount } from "./sync";

const POLL_MS = 3_000;
const SCHEDULE_EVERY_MS = 10 * 60_000;
const RESYNC_AFTER_MS = 3 * 60 * 60_000; // refresh each account every 3 hours
const MAX_ATTEMPTS = 5;

type Claimed = { id: string; accountId: string; fromDate: Date; toDate: Date; attempts: number };

async function claim(): Promise<Claimed | null> {
  const rows = await db.$queryRaw<Claimed[]>`
    UPDATE "SyncRun" SET status = 'RUNNING', "startedAt" = now(), attempts = attempts + 1
    WHERE id = (
      SELECT id FROM "SyncRun"
      WHERE status = 'QUEUED' AND "runAfter" <= now()
      ORDER BY "createdAt" ASC, "toDate" DESC
      FOR UPDATE SKIP LOCKED LIMIT 1
    )
    RETURNING id, "accountId", "fromDate", "toDate", attempts`;
  return rows[0] ?? null;
}

async function runOne(job: Claimed) {
  const lines: string[] = [];
  const from = ymd(job.fromDate), to = ymd(job.toDate);
  try {
    await syncAccount(job.accountId, from, to, (s) => lines.push(s));
    await db.syncRun.update({ where: { id: job.id }, data: { status: "DONE", finishedAt: new Date(), log: lines.join("\n"), error: "" } });
    console.log(`[sync] ${job.accountId} ${from}..${to} ok — ${lines.join("; ")}`);
  } catch (e) {
    const msg = (e as Error).message ?? String(e);
    const retry = job.attempts < MAX_ATTEMPTS;
    await db.syncRun.update({
      where: { id: job.id },
      data: {
        status: retry ? "QUEUED" : "FAILED", error: msg.slice(0, 2000), log: lines.join("\n"), finishedAt: new Date(),
        runAfter: new Date(Date.now() + 60_000 * 2 ** job.attempts),
      },
    });
    await db.adAccount.update({ where: { id: job.accountId }, data: { syncError: msg.slice(0, 500) } }).catch(() => {});
    console.error(`[sync] ${job.accountId} ${from}..${to} failed (attempt ${job.attempts}): ${msg}`);
  }
}

/** Queues refreshes for accounts that are due, and backfills for new ones. */
async function schedule() {
  // Jobs orphaned by a crashed worker go back in the queue.
  await db.syncRun.updateMany({ where: { status: "RUNNING", startedAt: { lt: new Date(Date.now() - 30 * 60_000) } }, data: { status: "QUEUED" } });

  const accounts = await db.adAccount.findMany({
    where: { enabled: true, companyId: { not: null }, connection: { status: "active" } },
    select: { id: true, lastSyncAt: true, syncRuns: { where: { status: { in: ["QUEUED", "RUNNING"] } }, select: { id: true }, take: 1 } },
  });
  const window = lastNDays(28);
  for (const a of accounts) {
    if (a.syncRuns.length) continue;
    if (!a.lastSyncAt) await enqueueInitialSync(a.id);
    else if (Date.now() - a.lastSyncAt.getTime() > RESYNC_AFTER_MS) await enqueueSync(a.id, window.from, ymd(new Date()));
  }

  // Tokens past their expiry stop syncing until someone reconnects.
  await db.adConnection.updateMany({ where: { isDemo: false, status: "active", expiresAt: { lt: new Date() } }, data: { status: "expired", lastError: "Access token expired — reconnect this login." } });
}

async function main() {
  console.log("[worker] started");
  let lastSchedule = 0;
  let stop = false;
  process.on("SIGTERM", () => (stop = true));
  process.on("SIGINT", () => (stop = true));
  while (!stop) {
    if (Date.now() - lastSchedule > SCHEDULE_EVERY_MS) {
      await schedule().catch((e) => console.error("[schedule]", e));
      lastSchedule = Date.now();
    }
    const job = await claim().catch((e) => (console.error("[claim]", e), null));
    if (job) await runOne(job);
    else await new Promise((r) => setTimeout(r, POLL_MS));
  }
  await db.$disconnect();
}

if (process.argv.includes("--once")) {
  // Drain the queue and exit — used by tests and the seed script.
  (async () => {
    await schedule();
    for (let j = await claim(); j; j = await claim()) await runOne(j);
    await db.$disconnect();
  })();
} else {
  main();
}
