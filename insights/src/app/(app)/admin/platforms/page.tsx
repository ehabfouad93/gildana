import { refreshSharedAction, removeAgencyConnectionAction, savePlatformSettingsAction } from "@/app/actions/saas";
import { CopyButton } from "@/components/copy";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { redirectUri } from "@/lib/oauth";
import { GENERAL_FIELDS, PLATFORM_SETUP, type Field } from "@/lib/platform-setup";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import { flag, setting, settingSource } from "@/lib/settings";
import type { SP } from "@/lib/page";
import { requireAdmin } from "@/lib/tenancy";
import { demoEnabled, liveConnector } from "@/platforms";
import { SystemUserForm } from "./system-user-form";

export const metadata = { title: "Platforms" };
export const dynamic = "force-dynamic";

/**
 * Everything Gildana sets up once so clients never touch an API: each
 * platform's app keys, Gildana's own logins for accounts shared with it,
 * sign-up and email. Values are stored encrypted; .env still works as a fallback.
 */
export default async function Platforms({ searchParams }: { searchParams: Promise<SP> }) {
  await requireAdmin();
  const sp = await searchParams;
  const agency = await db.adConnection.findMany({ where: { isAgency: true }, include: { _count: { select: { accounts: true } } }, orderBy: { createdAt: "asc" } });
  const pending = await db.accountClaim.count({ where: { status: "pending" } });

  return (
    <>
      <PageHeader title="Platforms" sub="Set each platform up once — then every client connects with one click, without ever seeing an API key." />
      {typeof sp.error === "string" && <div className="card mb-4 border-red-200 bg-red-50 text-sm text-bad">{sp.error}</div>}
      {typeof sp.connected === "string" && <div className="card mb-4 border-green-200 bg-green-50 text-sm text-good">Gildana&apos;s login connected.</div>}

      <form action={savePlatformSettingsAction} className="card mb-4 space-y-3">
        <h2 className="font-semibold">Workspace</h2>
        <div className="flex flex-wrap gap-6 text-sm">
          <input type="hidden" name="SIGNUP_ENABLED__toggle" value="1" />
          <label className="flex items-center gap-2"><input type="checkbox" name="SIGNUP_ENABLED" defaultChecked={flag("SIGNUP_ENABLED", false)} /> Anyone can sign up at <code>/signup</code> and create a workspace</label>
          <input type="hidden" name="DEMO_MODE__toggle" value="1" />
          <label className="flex items-center gap-2"><input type="checkbox" name="DEMO_MODE" defaultChecked={demoEnabled()} /> Show demo logins (sample data) on the connect screens</label>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="hidden" name="CLAIMS_AUTO_APPROVE" value="false" />
          <input type="checkbox" name="CLAIMS_AUTO_APPROVE" value="true" defaultChecked={setting("CLAIMS_AUTO_APPROVE") === "true"} />
          Approve shared ad accounts automatically when Gildana can see them <span className="text-muted">(off = you approve each one in Access requests{pending ? ` — ${pending} waiting` : ""})</span>
        </label>
        <div className="grid gap-3 md:grid-cols-2">{GENERAL_FIELDS.map((f) => <FieldInput key={f.key} f={f} />)}</div>
        <button className="btn">Save</button>
      </form>

      <div className="space-y-4">
        {PLATFORMS.map((p) => {
          const s = PLATFORM_SETUP[p];
          const m = PLATFORM_META[p];
          const live = !!liveConnector(p);
          const mine = agency.filter((a) => a.platform === p);
          const uris = [redirectUri(m.slug), ...(p === "GOOGLE" ? [redirectUri("gsc")] : [])];
          return (
            <section key={p} className="card">
              <div className="mb-3 flex flex-wrap items-center gap-3">
                <span className="h-3 w-3 rounded-full" style={{ background: m.color }} />
                <h2 className="text-lg font-semibold">{m.label}</h2>
                <span className={`pill ${live ? "bg-green-100 text-good" : "bg-amber-100 text-amber-800"}`}>{live ? "Ready — clients can connect" : "Not set up yet"}</span>
                <a href={s.portal.url} target="_blank" rel="noreferrer" className="ms-auto text-sm text-accent hover:underline">{s.portal.label} ↗</a>
              </div>
              <div className="grid gap-5 lg:grid-cols-[1.1fr_1fr]">
                <div>
                  <h3 className="mb-1 text-sm font-semibold">One-time setup</h3>
                  <ol className="list-decimal space-y-1 ps-5 text-sm text-muted">{s.steps.map((x, i) => <li key={i}>{x}</li>)}</ol>
                  <div className="mt-3 space-y-1">
                    <div className="text-xs font-medium text-muted">Redirect URI{uris.length > 1 ? "s" : ""} to paste in the platform</div>
                    {uris.map((u) => <div key={u} className="flex items-center gap-2"><code className="flex-1 truncate rounded bg-bg px-2 py-1 text-xs">{u}</code><CopyButton text={u} /></div>)}
                  </div>
                </div>
                <form action={savePlatformSettingsAction} className="space-y-3">
                  {s.fields.map((f) => <FieldInput key={f.key} f={f} />)}
                  <button className="btn">Save {m.label}</button>
                </form>
              </div>

              {s.share && (
                <div className="mt-4 rounded-lg border border-line p-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-sm font-semibold">Gildana&apos;s own login — for accounts clients share with you</h3>
                    {mine.length > 0 && <form action={refreshSharedAction} className="ms-auto"><input type="hidden" name="platform" value={p} /><button className="btn-ghost !py-1 text-xs">Check for newly shared accounts</button></form>}
                  </div>
                  <p className="mb-2 text-xs text-muted">Clients who prefer not to log in can share their ad account with {s.share.idLabel.replace("Gildana ", "Gildana’s ")}; it is read through this login.</p>
                  <ul className="mb-2 space-y-1 text-sm">
                    {mine.map((a) => (
                      <li key={a.id} className="flex flex-wrap items-center gap-2">
                        <b>{a.label}</b>{a.isDemo && <span className="pill bg-amber-100 text-amber-800">demo</span>}
                        <span className="text-xs text-muted">{a._count.accounts} ad accounts visible{a.lastError ? ` · ${a.lastError}` : ""}</span>
                        <form action={removeAgencyConnectionAction} className="ms-auto"><input type="hidden" name="id" value={a.id} /><button className="text-xs text-bad hover:underline">Disconnect</button></form>
                      </li>
                    ))}
                    {!mine.length && <li className="text-xs text-muted">Not connected.</li>}
                  </ul>
                  <div className="flex flex-wrap gap-2">
                    {live && <a className="btn-ghost !py-1" href={`/api/oauth/${m.slug}/start?agency=1&next=/admin/platforms`}>Connect Gildana&apos;s {m.label} login</a>}
                    {demoEnabled() && <a className="btn-ghost !py-1" href={`/api/oauth/${m.slug}/start?agency=1&demo=1&next=/admin/platforms`}>Connect demo login</a>}
                  </div>
                  {p === "META" && live && <SystemUserForm />}
                </div>
              )}
            </section>
          );
        })}
      </div>
    </>
  );
}

function FieldInput({ f }: { f: Field }) {
  const src = settingSource(f.key);
  const hasValue = !!setting(f.key);
  return (
    <div>
      <label className="label" htmlFor={f.key}>
        {f.label}
        {src === "env" && <span className="ms-2 text-[10px] uppercase text-muted">from server .env</span>}
      </label>
      {f.secret ? (
        <>
          <input type="hidden" name={`${f.key}__secret`} value="1" />
          <input className="input" id={f.key} name={f.key} type="password" autoComplete="off" placeholder={hasValue ? "•••••••• saved — leave blank to keep, “-” to remove" : f.placeholder ?? ""} />
        </>
      ) : (
        <input className="input" id={f.key} name={f.key} defaultValue={src === "db" ? setting(f.key) : ""} placeholder={src === "env" ? setting(f.key) : f.placeholder ?? ""} />
      )}
      {f.help && <p className="mt-1 text-xs text-muted">{f.help}</p>}
    </div>
  );
}
