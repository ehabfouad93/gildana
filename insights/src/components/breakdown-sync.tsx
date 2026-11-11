import { syncBreakdownsAction } from "@/app/actions/accounts";
import { AutoRefresh } from "@/components/auto-refresh";
import { SubmitButton } from "@/components/submit-button";
import { breakdownStatus } from "@/lib/jobs";

function ago(d: Date): string {
  const m = Math.round((Date.now() - d.getTime()) / 60_000);
  if (m < 1) return "just now";
  if (m < 60) return `${m} min ago`;
  const h = Math.round(m / 60);
  return h < 48 ? `${h} h ago` : `${Math.round(h / 24)} days ago`;
}

/**
 * Breakdowns (age, gender, country, placement…) refresh with the full sync
 * every few hours; this shows how fresh they are and fetches them now on request.
 */
export async function BreakdownSync({ accountIds, module, path }: { accountIds: string[]; module: "campaigns" | "audiences" | "performance"; path: string }) {
  if (!accountIds.length) return null;
  const s = await breakdownStatus(accountIds);
  const busy = s.running || s.queued;
  return (
    <form action={syncBreakdownsAction} className="no-print mb-3 flex flex-wrap items-center gap-3 text-xs">
      <AutoRefresh active={s.running || (s.queued && !s.pausedUntil)} />
      <input type="hidden" name="module" value={module} />
      <input type="hidden" name="path" value={path} />
      {accountIds.map((id) => <input key={id} type="hidden" name="accountId" value={id} />)}
      <span className="text-muted">
        Breakdowns updated {s.updatedAt ? ago(s.updatedAt) : "—"} · refreshed automatically every 6 h
      </span>
      {s.pausedUntil ? (
        <span className="text-amber-700">⏸ Meta rate limit — resumes at {s.pausedUntil.toISOString().slice(11, 16)} UTC</span>
      ) : s.running ? (
        <span className="text-blue-700">● syncing breakdowns now…</span>
      ) : s.queued ? (
        <span className="text-amber-700">queued — starts shortly</span>
      ) : null}
      {!busy && <SubmitButton className="btn-ghost !py-1" pending="Queuing…">↻ Sync breakdowns now</SubmitButton>}
    </form>
  );
}
