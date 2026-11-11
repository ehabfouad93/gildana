import { AutoRefresh, SelectAll } from "@/components/auto-refresh";
import { assignAccountAction, bulkAccountsAction, syncAllAction, removeConnectionAction, syncNowAction, toggleAccountAction } from "@/app/actions/accounts";
import { PageHeader } from "@/components/shell";
import { ShareAccess } from "@/components/share-access";
import { shareInfo } from "@/lib/share-info";
import { db } from "@/lib/db";
import type { SP } from "@/lib/page";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import { accessWhere, canEdit, companyWhere, requireModule } from "@/lib/tenancy";
import { demoEnabled, liveConnector } from "@/platforms";

export const metadata = { title: "Ad accounts" };

export default async function Connections({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("connections");
  const edit = canEdit(v);
  // Users limited to some companies see only those companies' accounts and can't reorganise them.
  const limited = !!v.access;
  const organise = edit && !limited;
  const [companies, conns] = await Promise.all([
    db.company.findMany({ where: { clientId: v.clientId, ...companyWhere(v) }, orderBy: { name: "asc" } }),
    db.adConnection.findMany({
      where: { clientId: v.clientId }, orderBy: { createdAt: "asc" },
      include: { accounts: { where: accessWhere(v.access), orderBy: { name: "asc" }, include: { syncRuns: { orderBy: { createdAt: "desc" }, take: 1 } } } },
    }),
  ]);
  const share = shareInfo();
  const claims = await db.accountClaim.findMany({ where: { clientId: v.clientId, status: { not: "approved" } }, orderBy: { createdAt: "desc" }, take: 20 });
  // Sync status per account: running now, or its place in the (global) queue.
  const jobs = await db.syncRun.findMany({ where: { status: { in: ["QUEUED", "RUNNING"] } }, orderBy: [{ priority: "desc" }, { createdAt: "asc" }], select: { accountId: true, status: true }, take: 2000 });
  const running = new Set(jobs.filter((j) => j.status === "RUNNING").map((j) => j.accountId));
  const parts = new Map<string, number>();
  const place = new Map<string, number>();
  for (const j of jobs) {
    parts.set(j.accountId, (parts.get(j.accountId) ?? 0) + 1);
    if (j.status === "QUEUED" && !running.has(j.accountId) && !place.has(j.accountId)) place.set(j.accountId, place.size + 1);
  }

  return (
    <>
      <AutoRefresh active={parts.size > 0 && conns.some((c) => c.accounts.some((a) => parts.has(a.id)))} />
      <PageHeader title="Ad accounts" sub="Every connected ad account syncs automatically and stays live (refreshed every 15 minutes). Put accounts in companies to organise reports.">
        {edit && conns.some((c) => c.accounts.length) && <form action={syncAllAction}><button className="btn">↻ Sync all accounts</button></form>}
      </PageHeader>
      {typeof sp.error === "string" && <div className="card mb-4 border-red-200 bg-red-50 text-sm text-bad">Connection failed: {sp.error}</div>}
      {typeof sp.connected === "string" && <div className="card mb-4 border-green-200 bg-green-50 text-sm text-good">Connected. All ad accounts are syncing now — the latest 30 days first, then older history. You can put them in companies any time.</div>}
      {!companies.length && <div className="card mb-4 text-sm">Create a company first on the <a className="text-accent underline" href="/companies">Companies</a> page.</div>}

      {share.length > 0 && companies.length > 0 && edit && (
        <details className="card mb-4" open={claims.length > 0}>
          <summary className="cursor-pointer font-semibold">Share access with Gildana instead of logging in</summary>
          <div className="mt-3"><ShareAccess platforms={share} companies={companies.map((c) => ({ id: c.id, name: c.name }))} /></div>
          {claims.length > 0 && (
            <ul className="mt-3 space-y-1 text-sm">
              {claims.map((c) => (
                <li key={c.id}>
                  {PLATFORM_META[c.platform].label} · {c.name || c.externalId} — {c.status === "pending" ? (c.visible ? "Gildana can see it; waiting for approval" : "waiting for you to share it with Gildana") : c.status === "rejected" ? `declined${c.note ? `: ${c.note}` : ""}` : "added"}
                </li>
              ))}
            </ul>
          )}
        </details>
      )}

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
                    {live && p === "META" && <a className="btn-ghost" href={`/api/oauth/${meta.slug}/start?write=1`} title="Also grants ads_management so the dashboard can create paused interest split-test ad sets">Connect with split tests</a>}
                    {demoEnabled() && <a className={live ? "btn-ghost" : "btn"} href={`/api/oauth/${meta.slug}/start?demo=1`}>{live ? "Add demo login" : `Connect ${meta.label} (demo)`}</a>}
                  </div>
                )}
              </div>
              {!live && <p className="mb-3 text-xs text-muted">{v.isAdmin ? <>Not set up yet — add {meta.label}&apos;s app keys in <a className="text-accent underline" href="/admin/platforms">Admin → Platforms</a>.</> : `${meta.label} connections are coming soon.`}</p>}

              {list.filter((c) => !limited || c.accounts.length).map((c) => (
                <div key={c.id} className="mb-3 rounded-lg border border-line">
                  <div className="flex flex-wrap items-center gap-2 border-b border-line bg-bg px-3 py-2 text-sm">
                    <b>{c.label || c.externalUser || "Login"}</b>
                    {c.agencySourceId && <span className="pill bg-blue-100 text-blue-800">via Gildana</span>}
                    {c.isDemo && <span className="pill bg-amber-100 text-amber-800">demo</span>}
                    <span className={`pill ${c.status === "active" ? "bg-green-100 text-good" : "bg-red-100 text-bad"}`}>{c.status}</span>
                    {c.expiresAt && <span className="text-xs text-muted">token expires {c.expiresAt.toISOString().slice(0, 10)}</span>}
                    {p === "META" && !c.isDemo && <span className="text-xs text-muted">{c.scopes.split(",").includes("ads_management") ? "split tests allowed" : "read-only"}</span>}
                    {c.lastError && <span className="text-xs text-bad">{c.lastError}</span>}
                    {organise && (
                      <form action={removeConnectionAction} className="ms-auto">
                        <input type="hidden" name="connectionId" value={c.id} />
                        <button className="text-xs text-bad hover:underline">Remove login</button>
                      </form>
                    )}
                  </div>
                  {organise && c.accounts.length > 1 && (
                    <form id={`bulk-${c.id}`} action={bulkAccountsAction} className="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2 text-sm">
                      <span className="text-muted">With ticked accounts:</span>
                      <select name="companyId" className="input !w-48 !py-1" defaultValue="">
                        <option value="">Unassigned</option>
                        {companies.map((co) => <option key={co.id} value={co.id}>{co.name}</option>)}
                      </select>
                      <button name="op" value="assign" className="btn-ghost !py-1">Assign to company</button>
                      <button name="op" value="sync" className="btn-ghost !py-1">Sync now</button>
                      <button name="op" value="disable" className="btn-ghost !py-1">Disable</button>
                      <button name="op" value="enable" className="btn-ghost !py-1">Enable</button>
                    </form>
                  )}
                  <div className="overflow-x-auto">
                    <table className="tbl">
                      <thead><tr>{organise && c.accounts.length > 1 && <th className="!w-8"><SelectAll form={`bulk-${c.id}`} /></th>}<th>Ad account</th><th>Currency</th><th>Company</th><th>Last sync</th><th>Status</th><th /></tr></thead>
                      <tbody>
                        {c.accounts.map((a) => (
                          <tr key={a.id} className={a.enabled ? "" : "opacity-50"}>
                            {organise && c.accounts.length > 1 && <td><input type="checkbox" name="ids" value={a.id} form={`bulk-${c.id}`} aria-label={`Select ${a.name}`} /></td>}
                            <td><div className="font-medium">{a.name}</div><div className="text-xs text-muted">{a.externalId}</div></td>
                            <td>{a.currency}</td>
                            <td>
                              <form key={`${a.id}:${a.companyId ?? ""}`} action={assignAccountAction} className="flex gap-1">
                                <input type="hidden" name="accountId" value={a.id} />
                                <select name="companyId" defaultValue={a.companyId ?? ""} className="input !w-48 !py-1" disabled={!organise}>
                                  <option value="">Unassigned</option>
                                  {companies.map((co) => <option key={co.id} value={co.id}>{co.name}</option>)}
                                </select>
                                {organise && <button className="btn-ghost !py-1">Save</button>}
                              </form>
                            </td>
                            <td className="text-xs">{a.lastSyncAt ? a.lastSyncAt.toISOString().replace("T", " ").slice(0, 16) : "never"}</td>
                            <td className="text-xs">
                              {running.has(a.id) ? <span className="text-blue-700">● syncing now{(parts.get(a.id) ?? 0) > 1 ? ` · ${(parts.get(a.id) ?? 1) - 1} more part${(parts.get(a.id) ?? 1) > 2 ? "s" : ""} after` : ""}</span>
                                : place.has(a.id) ? <span className="text-amber-700">queued #{place.get(a.id)} · {parts.get(a.id)} part{(parts.get(a.id) ?? 0) > 1 ? "s" : ""}</span>
                                : a.syncError ? <span className="text-bad" title={a.syncError}>error</span>
                                : !a.enabled ? <span className="text-muted">disabled</span>
                                : a.lastSyncAt ? <span className="text-good">● live</span> : <span className="text-muted">waiting</span>}
                            </td>
                            <td>
                              {edit && (
                                <div className="flex gap-2 text-xs">
                                  {a.enabled && (
                                    <form action={syncNowAction}><input type="hidden" name="accountId" value={a.id} /><button className="text-accent hover:underline">Sync now</button></form>
                                  )}
                                  {organise && <form action={toggleAccountAction}><input type="hidden" name="accountId" value={a.id} /><button className="text-muted hover:underline">{a.enabled ? "Disable" : "Enable"}</button></form>}
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
