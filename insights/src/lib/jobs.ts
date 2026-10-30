import { db } from "@/lib/db";
import { addDays, lastNDays, toDate, ymd } from "@/lib/dates";

/** Queues a sync window for an account. Long windows are split so one job stays short. */
export async function enqueueSync(accountId: string, from: string, to: string, kind = "stats", chunkDays = 31) {
  const jobs = [];
  for (let start = from; start <= to; start = addDays(start, chunkDays)) {
    const end = addDays(start, chunkDays - 1) < to ? addDays(start, chunkDays - 1) : to;
    jobs.push({ accountId, kind, fromDate: toDate(start), toDate: toDate(end) });
  }
  // newest window first, so recent numbers appear before the backfill finishes
  await db.syncRun.createMany({ data: jobs.reverse() });
  return jobs.length;
}

/** First sync for a newly assigned account: a backfill of BACKFILL_DAYS (default 90). */
export async function enqueueInitialSync(accountId: string) {
  const days = Math.min(1100, Number(process.env.BACKFILL_DAYS) || 90);
  const r = lastNDays(days);
  return enqueueSync(accountId, r.from, ymd(new Date()), "backfill");
}
