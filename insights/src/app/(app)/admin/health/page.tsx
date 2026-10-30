import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { PLATFORM_META } from "@/lib/platforms";
import { requireAdmin } from "@/lib/tenancy";

export const metadata = { title: "Sync health" };

export default async function Health() {
  await requireAdmin();
  const [counts, failed, stale, expired, last] = await Promise.all([
    db.syncRun.groupBy({ by: ["status"], _count: true }),
    db.syncRun.findMany({ where: { status: "FAILED" }, orderBy: { finishedAt: "desc" }, take: 30, include: { account: { include: { connection: { include: { client: true } } } } } }),
    db.adAccount.findMany({ where: { enabled: true, companyId: { not: null }, OR: [{ lastSyncAt: null }, { lastSyncAt: { lt: new Date(Date.now() - 12 * 3600_000) } }] }, include: { connection: { include: { client: true } } }, take: 50 }),
    db.adConnection.findMany({ where: { status: { not: "active" } }, include: { client: true } }),
    db.syncRun.findFirst({ where: { status: "DONE" }, orderBy: { finishedAt: "desc" } }),
  ]);
  const n = (s: string) => counts.find((c) => c.status === s)?._count ?? 0;
  const workerAge = last?.finishedAt ? Math.round((Date.now() - last.finishedAt.getTime()) / 60000) : null;

  return (
    <>
      <PageHeader title="Sync health" sub="Every client's platform logins and sync jobs" />
      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
        {[["Queued", n("QUEUED")], ["Running", n("RUNNING")], ["Done", n("DONE")], ["Failed", n("FAILED")], ["Last job", workerAge == null ? "never" : `${workerAge} min ago`]].map(([k, v]) => (
          <div key={k} className="card !p-3"><div className="text-xs text-muted">{k}</div><div className="text-lg font-semibold">{v}</div></div>
        ))}
      </div>
      {workerAge != null && workerAge > 60 && n("QUEUED") > 0 && <div className="card mb-4 border-red-200 bg-red-50 text-sm text-bad">Jobs are queued but none finished in the last hour — is the worker process running? (<code>npm run worker</code>)</div>}

      <Section title="Logins needing attention" empty="All logins are active.">
        {expired.map((c) => <tr key={c.id}><td>{c.client.name}</td><td>{PLATFORM_META[c.platform].label}</td><td>{c.label}</td><td className="text-bad">{c.status}: {c.lastError}</td></tr>)}
      </Section>
      <Section title="Accounts not synced in 12 hours" empty="Every assigned account is fresh.">
        {stale.map((a) => <tr key={a.id}><td>{a.connection.client.name}</td><td>{PLATFORM_META[a.platform].label}</td><td>{a.name}</td><td className="text-xs">{a.lastSyncAt?.toISOString().slice(0, 16) ?? "never"} {a.syncError && <span className="text-bad">— {a.syncError}</span>}</td></tr>)}
      </Section>
      <Section title="Failed jobs" empty="No failed jobs.">
        {failed.map((j) => <tr key={j.id}><td>{j.account.connection.client.name}</td><td>{PLATFORM_META[j.account.platform].label}</td><td>{j.account.name}</td><td className="text-xs text-bad">{j.error}</td></tr>)}
      </Section>
    </>
  );
}

function Section({ title, empty, children }: { title: string; empty: string; children: React.ReactNode[] }) {
  return (
    <div className="card mb-4">
      <h2 className="mb-2 font-semibold">{title}</h2>
      {children.length ? <table className="tbl"><tbody>{children}</tbody></table> : <p className="text-sm text-muted">{empty}</p>}
    </div>
  );
}
