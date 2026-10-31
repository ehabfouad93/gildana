import Link from "next/link";
import { assignAccountAction, createCompanyAction } from "@/app/actions/accounts";
import { finishOnboardingAction } from "@/app/actions/saas";
import { ShareAccess } from "@/components/share-access";
import { db } from "@/lib/db";
import type { SP } from "@/lib/page";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import { shareInfo } from "@/lib/share-info";
import { canEdit, requireWorkspace } from "@/lib/tenancy";
import { demoEnabled, liveConnector } from "@/platforms";

export const metadata = { title: "Get started" };
export const dynamic = "force-dynamic";

/** Three steps from a new workspace to live numbers: companies → connect → put accounts in companies. */
export default async function Welcome({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireWorkspace();
  const edit = canEdit(v);
  const [client, companies, accounts, claims] = await Promise.all([
    db.client.findUniqueOrThrow({ where: { id: v.clientId } }),
    db.company.findMany({ where: { clientId: v.clientId }, orderBy: { name: "asc" } }),
    db.adAccount.findMany({ where: { connection: { clientId: v.clientId } }, orderBy: { name: "asc" } }),
    db.accountClaim.findMany({ where: { clientId: v.clientId, status: "pending" } }),
  ]);
  const unassigned = accounts.filter((a) => !a.companyId);
  const assigned = accounts.filter((a) => a.companyId);
  const share = shareInfo();
  const step = !companies.length ? 1 : !accounts.length && !claims.length ? 2 : unassigned.length ? 3 : 4;

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div>
        <div className="text-xs font-semibold uppercase tracking-[0.2em] text-accent">Welcome{v.name ? `, ${v.name.split(" ")[0]}` : ""}</div>
        <h1 className="text-2xl font-semibold">Let&apos;s get {client.name} live</h1>
        <p className="text-sm text-muted">Three quick steps. You never need API keys — just log in to the ad platforms you use.</p>
      </div>
      {typeof sp.error === "string" && <div className="card border-red-200 bg-red-50 text-sm text-bad">Connection failed: {sp.error}</div>}

      <Step n={1} title={client.isAgency ? "Add the brands you manage" : "Your company"} done={companies.length > 0} active={step === 1}>
        {companies.length > 0 && <ul className="mb-3 flex flex-wrap gap-2">{companies.map((c) => <li key={c.id} className="pill bg-bg">{c.name}</li>)}</ul>}
        {edit && (client.isAgency || !companies.length) && (
          <form action={createCompanyAction} className="flex flex-wrap gap-2">
            <input className="input flex-1" name="name" placeholder={client.isAgency ? "Brand name" : "Company name"} required />
            <input className="input flex-1" name="website" placeholder="https://website (optional)" />
            <button className="btn">Add</button>
          </form>
        )}
      </Step>

      <Step n={2} title="Connect your ad accounts" done={accounts.length > 0} active={step === 2}>
        <p className="mb-3 text-sm text-muted">Click a platform, log in, and tick the ad accounts to include. You can connect several logins per platform.</p>
        <div className="flex flex-wrap gap-2">
          {PLATFORMS.map((p) => {
            const live = !!liveConnector(p);
            const n = accounts.filter((a) => a.platform === p).length;
            if (!live && !demoEnabled()) return <span key={p} className="pill bg-bg !px-3 !py-2 text-muted">{PLATFORM_META[p].label} — coming soon</span>;
            return (
              <a key={p} href={`/api/oauth/${PLATFORM_META[p].slug}/start?next=/welcome${live ? "" : "&demo=1"}`}
                className="inline-flex items-center gap-2 rounded-lg border border-line bg-panel px-3 py-2 text-sm font-medium hover:border-accent">
                <span className="h-2.5 w-2.5 rounded-full" style={{ background: PLATFORM_META[p].color }} />
                {n ? `${PLATFORM_META[p].label} · ${n} connected` : `Connect ${PLATFORM_META[p].label}${live ? "" : " (demo)"}`}
              </a>
            );
          })}
        </div>
        {share.length > 0 && companies.length > 0 && (
          <details className="mt-4 rounded-lg border border-line p-3">
            <summary className="cursor-pointer text-sm font-medium">Prefer not to log in? Share access with Gildana instead</summary>
            <div className="mt-3"><ShareAccess platforms={share} companies={companies.map((c) => ({ id: c.id, name: c.name }))} /></div>
          </details>
        )}
        {claims.length > 0 && <p className="mt-3 text-sm text-amber-700">{claims.length} shared account{claims.length > 1 ? "s" : ""} waiting for Gildana — they&apos;ll appear automatically.</p>}
      </Step>

      <Step n={3} title="Put each ad account in a company" done={accounts.length > 0 && !unassigned.length} active={step === 3}>
        {unassigned.length ? (
          <table className="tbl">
            <tbody>
              {unassigned.map((a) => (
                <tr key={a.id}>
                  <td><div className="font-medium">{a.name}</div><div className="text-xs text-muted">{PLATFORM_META[a.platform].label} · {a.externalId}</div></td>
                  <td>
                    <form key={`${a.id}:${a.companyId ?? ""}`} action={assignAccountAction} className="flex justify-end gap-1">
                      <input type="hidden" name="accountId" value={a.id} />
                      <select name="companyId" className="input !w-52 !py-1" defaultValue={companies.length === 1 ? companies[0].id : ""} required>
                        <option value="" disabled>Choose company…</option>
                        {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                      </select>
                      <button className="btn !py-1">Add</button>
                    </form>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : <p className="text-sm text-muted">{assigned.length ? `${assigned.length} ad accounts are loading their last 90 days.` : "Connect an account first."}</p>}
        <p className="mt-2 text-xs text-muted">Accounts you don&apos;t add stay out of reports — you can change this any time under Ad accounts.</p>
      </Step>

      <div className="flex items-center justify-between">
        <Link href="/connections" className="text-sm text-muted hover:text-ink">Manage ad accounts →</Link>
        <form action={finishOnboardingAction}><button className="btn" disabled={!assigned.length}>{assigned.length ? "Go to my dashboard" : "Add an account to continue"}</button></form>
      </div>
    </div>
  );
}

function Step({ n, title, done, active, children }: { n: number; title: string; done: boolean; active: boolean; children: React.ReactNode }) {
  return (
    <section className={`card ${active ? "ring-2 ring-accent/40" : ""}`}>
      <div className="mb-3 flex items-center gap-3">
        <span className={`flex h-7 w-7 items-center justify-center rounded-full text-sm font-semibold ${done ? "bg-good text-white" : active ? "bg-accent text-white" : "bg-bg text-muted"}`}>{done ? "✓" : n}</span>
        <h2 className="font-semibold">{title}</h2>
      </div>
      {children}
    </section>
  );
}
