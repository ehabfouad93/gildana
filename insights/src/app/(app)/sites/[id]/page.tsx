import { notFound } from "next/navigation";
import { addGoalAction, deleteGoalAction, deleteSiteAction, demoTrafficAction, updateSiteAction } from "@/app/actions/sites";
import { CopyButton } from "@/components/copy";
import { SiteNav } from "@/components/site-nav";
import { db } from "@/lib/db";
import { canEdit, requireWorkspace } from "@/lib/tenancy";
import { siteForClient } from "@/lib/web";

export const metadata = { title: "Website setup" };

export default async function SiteSetup({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const v = await requireWorkspace();
  const site = await siteForClient(v.clientId, id);
  if (!site) notFound();
  const edit = canEdit(v);
  const base = process.env.APP_URL ?? "";
  const snippet = `<script async src="${base}/t.js" data-key="${site.trackingKey}"${site.replay ? ` data-replay="${site.replayRate}"` : ""}></script>`;
  const last = await db.trackSession.findFirst({ where: { siteId: id }, orderBy: { lastSeenAt: "desc" }, select: { lastSeenAt: true } });

  return (
    <>
      <SiteNav id={id} active="" name={site.name} domain={site.domain} />
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="card space-y-3 lg:col-span-2">
          <h2 className="font-semibold">1 · Install the tracking code</h2>
          <p className="text-sm text-muted">Paste this before <code>&lt;/head&gt;</code> on every page (or add it as a Custom HTML tag in Google Tag Manager). It only accepts data from <b>{site.domain}</b> and its subdomains.</p>
          <div className="flex items-start gap-2"><pre className="flex-1 overflow-x-auto rounded-lg bg-side p-3 text-xs text-white">{snippet}</pre><CopyButton text={snippet} /></div>
          <p className="text-sm">{last ? <span className="text-good">● Receiving data — last hit {last.lastSeenAt.toISOString().replace("T", " ").slice(0, 16)} UTC</span> : <span className="text-muted">○ No data received yet.</span>}</p>
          <details className="text-sm text-muted">
            <summary className="cursor-pointer">Track custom events and conversions</summary>
            <pre className="mt-2 overflow-x-auto rounded-lg bg-bg p-3 text-xs">{`gildana("event", "video_play");\ngildana("conversion", "purchase", 149.0);`}</pre>
          </details>
        </div>

        <form action={updateSiteAction} className="card space-y-3">
          <h2 className="font-semibold">Settings</h2>
          <input type="hidden" name="id" value={id} />
          <div className="grid grid-cols-2 gap-2">
            <div><label className="label">Name</label><input className="input" name="name" defaultValue={site.name} disabled={!edit} /></div>
            <div><label className="label">Domain</label><input className="input" name="domain" defaultValue={site.domain} disabled={!edit} /></div>
          </div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="replay" defaultChecked={site.replay} disabled={!edit} /> Record sessions for replay — <input className="input !w-16 !py-1" name="replayRate" type="number" min={1} max={100} defaultValue={site.replayRate} disabled={!edit} />% of sessions</label>
          <p className="text-xs text-muted">Recordings mask every form field. Changing this updates the tracking code above — re-copy it.</p>
          {edit && <button className="btn">Save</button>}
        </form>

        <div className="card space-y-3">
          <h2 className="font-semibold">2 · Goals (conversions)</h2>
          <table className="tbl"><tbody>
            {site.goals.map((g) => (
              <tr key={g.id}><td>{g.name}</td><td className="text-xs text-muted">{g.kind === "path" ? `visits a page starting with ${g.match}` : `fires event “${g.match}”`}</td>
                <td>{edit && <form action={deleteGoalAction}><input type="hidden" name="siteId" value={id} /><input type="hidden" name="id" value={g.id} /><button className="text-xs text-bad">Remove</button></form>}</td></tr>
            ))}
            {!site.goals.length && <tr><td className="text-muted">No goals yet. Calls to gildana(&quot;conversion&quot;) always count.</td></tr>}
          </tbody></table>
          {edit && (
            <form action={addGoalAction} className="grid grid-cols-[1fr_auto_1fr_auto] gap-2">
              <input type="hidden" name="siteId" value={id} />
              <input className="input" name="name" placeholder="Lead form sent" required />
              <select className="input !w-auto" name="kind"><option value="path">Page</option><option value="event">Event</option></select>
              <input className="input" name="match" placeholder="/thank-you" required />
              <button className="btn-ghost">Add</button>
            </form>
          )}
        </div>

        {edit && site.isDemo && (
          <form action={demoTrafficAction} className="card">
            <input type="hidden" name="id" value={id} />
            <h2 className="font-semibold">Demo data</h2>
            <p className="mb-2 text-sm text-muted">Fill this demo site with 30 days of generated visits. Real visits to <a className="text-accent underline" href="/demo-site" target="_blank">/demo-site</a> are tracked too.</p>
            <button className="btn-ghost">Regenerate demo traffic</button>
          </form>
        )}
        {edit && (
          <form action={deleteSiteAction} className="card border-red-200">
            <input type="hidden" name="id" value={id} />
            <h2 className="font-semibold text-bad">Remove website</h2>
            <p className="mb-2 text-sm text-muted">Deletes all its tracked visits, recordings and audits.</p>
            <button className="btn !bg-bad">Remove</button>
          </form>
        )}
      </div>
    </>
  );
}
