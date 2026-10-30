import { assignAccountAction, removeConnectionAction, syncNowAction, toggleAccountAction } from "@/app/actions/accounts";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import type { SP } from "@/lib/page";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import { canEdit, requireWorkspace } from "@/lib/tenancy";
import { demoEnabled, liveConnector } from "@/platforms";

export const metadata = { title: "Ad accounts" };

export default async function Connections({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireWorkspace();
  const edit = canEdit(v);
  const [companies, conns] = await Promise.all([
    db.company.findMany({ where: { clientId: v.clientId }, orderBy: { name: "asc" } }),
    db.adConnection.findMany({
      where: { clientId: v.clientId }, orderBy: { createdAt: "asc" },
      include: { accounts: { orderBy: { name: "asc" }, include: { syncRuns: { orderBy: { createdAt: "desc" }, take: 1 } } } },
    }),
  ]);
  const queued = await db.syncRun.groupBy({ by: ["accountId"], where: { status: { in: ["QUEUED", "RUNNING"] }, account: { connection: { clientId: v.clientId } } }, _count: true });
  const pending = new Map(queued.map((q) => [q.accountId, q._count]));

  return (
    <>
      <PageHeader title="Ad accounts" sub="Connect any number of logins per platform, then put each ad account in a company." />
      {typeof sp.error === "string" && <div className="card mb-4 border-red-200 bg-red-50 text-sm text-bad">Connection failed: {sp.error}</div>}
      {typeof sp.connected === "string" && <div className="card mb-4 border-green-200 bg-green-50 text-sm text-good">Connected. Assign the new accounts to a company below to start syncing.</div>}
      {!companies.length && <div className="card mb-4 text-sm">Create a company first on the <a className="text-accent underline" href="/companies">Companies</a> page.</div>}

      <div className="space-y-4">
        {PLATFORMS.map((p) => {
          const meta = PLATFORM_META[p];
          const live = !!liveConnector(p);
          const list = conns.filter((c) => c.platform === p);
          return (
            <section key={p} className="card">
              <div className="mb-3 flex flex-wrap items-center gap-3">
                <span className="h-3 w-3 rounded-full" style={{ background: meta.color }} />
                <h2 className="font-semibold">{meta.label}</h2>
                <span className="text-xs text-muted">{list.reduce((n, c) => n + c.accounts.length, 0)} accounts · {list.length} logins</span>
                {edit && (
                  <div className="ms-auto flex gap-2">
                    {live && <a className="btn" href={`/api/oauth/${meta.slug}/start`}>Connect {meta.label}</a>}
                    {(!live || demoEnabled()) && <a className={live ? "btn-ghost" : "btn"} href={`/api/oauth/${meta.slug}/start?demo=1`}>{live ? "Add demo login" : `Connect ${meta.label} (demo)`}</a>}
                  </div>
                )}
              </div>
              {!live && <p className="mb-3 text-xs text-muted">{meta.label} API credentials are not configured yet, so only demo logins are available. Add them in the server environment to connect real accounts.</p>}

              {list.map((c) => (
                <div key={c.id} className="mb-3 rounded-lg border border-line">
                  <div className="flex flex-wrap items-center gap-2 border-b border-line bg-bg px-3 py-2 text-sm">
                    <b>{c.label || c.externalUser || "Login"}</b>
                    {c.isDemo && <span className="pill bg-amber-100 text-amber-800">demo</span>}
                    <span className={`pill ${c.status === "active" ? "bg-green-100 text-good" : "bg-red-100 text-bad"}`}>{c.status}</span>
                    {c.expiresAt && <span className="text-xs text-muted">token expires {c.expiresAt.toISOString().slice(0, 10)}</span>}
                    {c.lastError && <span className="text-xs text-bad">{c.lastError}</span>}
                    {edit && (
                      <form action={removeConnectionAction} className="ms-auto">
                        <input type="hidden" name="connectionId" value={c.id} />
                        <button className="text-xs text-bad hover:underline">Remove login</button>
                      </form>
                    )}
                  </div>
                  <div className="overflow-x-auto">
                    <table className="tbl">
                      <thead><tr><th>Ad account</th><th>Currency</th><th>Company</th><th>Last sync</th><th>Status</th><th /></tr></thead>
                      <tbody>
                        {c.accounts.map((a) => (
                          <tr key={a.id} className={a.enabled ? "" : "opacity-50"}>
                            <td><div className="font-medium">{a.name}</div><div className="text-xs text-muted">{a.externalId}</div></td>
                            <td>{a.currency}</td>
                            <td>
                              <form key={`${a.id}:${a.companyId ?? ""}`} action={assignAccountAction} className="flex gap-1">
                                <input type="hidden" name="accountId" value={a.id} />
                                <select name="companyId" defaultValue={a.companyId ?? ""} className="input !w-48 !py-1" disabled={!edit}>
                                  <option value="">— not assigned —</option>
                                  {companies.map((co) => <option key={co.id} value={co.id}>{co.name}</option>)}
                                </select>
                                {edit && <button className="btn-ghost !py-1">Save</button>}
                              </form>
                            </td>
                            <td className="text-xs">{a.lastSyncAt ? a.lastSyncAt.toISOString().replace("T", " ").slice(0, 16) : "never"}</td>
                            <td className="text-xs">
                              {pending.get(a.id) ? <span className="text-amber-700">syncing ({pending.get(a.id)} jobs)</span>
                                : a.syncError ? <span className="text-bad" title={a.syncError}>error</span>
                                : a.companyId ? <span className="text-good">ok</span> : <span className="text-muted">idle</span>}
                            </td>
                            <td>
                              {edit && (
                                <div className="flex gap-2 text-xs">
                                  {a.companyId && a.enabled && (
                                    <form action={syncNowAction}><input type="hidden" name="accountId" value={a.id} /><button className="text-accent hover:underline">Sync now</button></form>
                                  )}
                                  <form action={toggleAccountAction}><input type="hidden" name="accountId" value={a.id} /><button className="text-muted hover:underline">{a.enabled ? "Disable" : "Enable"}</button></form>
                                </div>
                              )}
                            </td>
                          </tr>
                        ))}
                        {!c.accounts.length && <tr><td colSpan={6} className="text-muted">This login has no ad accounts.</td></tr>}
                      </tbody>
                    </table>
                  </div>
                </div>
              ))}
            </section>
          );
        })}
      </div>
    </>
  );
}
