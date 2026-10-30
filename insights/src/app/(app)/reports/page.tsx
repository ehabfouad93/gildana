import { createShareAction, revokeShareAction } from "@/app/actions/share";
import { CopyButton } from "@/components/copy";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { lastNDays } from "@/lib/dates";
import type { SP } from "@/lib/page";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import { shareRange } from "@/lib/share";
import { canEdit, requireWorkspace, scopedAccountIds } from "@/lib/tenancy";

export const metadata = { title: "Reports" };

export default async function Reports({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireWorkspace();
  const edit = canEdit(v);
  const accountIds = await scopedAccountIds(v.clientId);
  const [companies, campaigns, links] = await Promise.all([
    db.company.findMany({ where: { clientId: v.clientId }, orderBy: { name: "asc" } }),
    db.campaign.findMany({ where: { accountId: { in: accountIds } }, orderBy: { name: "asc" }, select: { id: true, name: true, platform: true } }),
    db.shareLink.findMany({ where: { clientId: v.clientId }, orderBy: { createdAt: "desc" } }),
  ]);
  const base = process.env.APP_URL ?? "";
  const def = lastNDays(30);
  const pre = { campaign: typeof sp.campaign === "string" ? sp.campaign : "" };

  return (
    <>
      <PageHeader title="Reports" sub="Download a report, or send a live link that always shows current numbers" />
      <div className="grid gap-4 lg:grid-cols-[1fr_1.4fr]">
        <form action={createShareAction} className="card space-y-3" method="post">
          <h2 className="font-semibold">Build a report</h2>
          <div><label className="label">Title</label><input className="input" name="title" defaultValue="Performance report" /></div>
          <div className="grid grid-cols-2 gap-2">
            <div><label className="label">Company</label>
              <select className="input" name="companyId"><option value="">All companies</option>{companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></div>
            <div><label className="label">Platform</label>
              <select className="input" name="platform"><option value="">All platforms</option>{PLATFORMS.map((p) => <option key={p} value={PLATFORM_META[p].slug}>{PLATFORM_META[p].label}</option>)}</select></div>
          </div>
          <div><label className="label">Single campaign (optional)</label>
            <select className="input" name="campaignId" defaultValue={pre.campaign}><option value="">—</option>{campaigns.map((c) => <option key={c.id} value={c.id}>{PLATFORM_META[c.platform].label} · {c.name}</option>)}</select></div>
          <fieldset className="grid grid-cols-2 gap-2">
            <label className="flex items-center gap-2 text-sm"><input type="radio" name="rangeMode" value="rolling" defaultChecked /> Rolling: last <input className="input !w-16 !py-1" name="days" type="number" defaultValue={30} min={1} /> days</label>
            <label className="flex items-center gap-2 text-sm"><input type="radio" name="rangeMode" value="fixed" /> Fixed:
              <input className="input !py-1" type="date" name="from" defaultValue={def.from} /><input className="input !py-1" type="date" name="to" defaultValue={def.to} /></label>
          </fieldset>
          <div className="grid grid-cols-2 gap-2">
            <div><label className="label">Password (optional)</label><input className="input" name="password" type="text" autoComplete="off" /></div>
            <div><label className="label">Expires after (days, 0 = never)</label><input className="input" name="expiresDays" type="number" defaultValue={0} min={0} /></div>
          </div>
          {edit ? <button className="btn">Create live link</button> : <p className="text-sm text-muted">Read-only users can view reports but not create links.</p>}
          <p className="text-xs text-muted">Every link opens a print-ready page; use “Download PDF” there to save it as a file.</p>
        </form>

        <div className="card">
          <h2 className="mb-2 font-semibold">Live links</h2>
          <div className="space-y-2">
            {links.map((l) => {
              const url = `${base}/s/${l.token}`;
              const r = shareRange(l);
              const expired = l.expiresAt && l.expiresAt < new Date();
              const scope = l.campaignId ? campaigns.find((c) => c.id === l.campaignId)?.name : l.platform ? PLATFORM_META[l.platform].label : l.companyId ? companies.find((c) => c.id === l.companyId)?.name : "Whole account";
              return (
                <div key={l.id} className={`rounded-lg border border-line p-3 text-sm ${expired ? "opacity-50" : ""}`}>
                  <div className="flex flex-wrap items-center gap-2">
                    <b>{l.title}</b>
                    <span className="pill bg-bg">{scope}</span>
                    <span className="pill bg-bg">{l.rangeDays ? `last ${l.rangeDays} days` : `${r.from} → ${r.to}`}</span>
                    {l.passwordHash && <span className="pill bg-amber-100 text-amber-800">password</span>}
                    {l.expiresAt && <span className="pill bg-bg">{expired ? "expired" : `expires ${l.expiresAt.toISOString().slice(0, 10)}`}</span>}
                    <span className="ms-auto text-xs text-muted">{l.views} views</span>
                  </div>
                  <div className="mt-2 flex items-center gap-2">
                    <input className="input !py-1 font-mono text-xs" readOnly value={url} />
                    <CopyButton text={url} />
                    <a className="btn-ghost !py-1" href={`/s/${l.token}`} target="_blank">Open</a>
                    {edit && <form action={revokeShareAction}><input type="hidden" name="id" value={l.id} /><button className="btn-ghost !py-1 text-bad">Revoke</button></form>}
                  </div>
                </div>
              );
            })}
            {!links.length && <p className="text-sm text-muted">No links yet.</p>}
          </div>
        </div>
      </div>
    </>
  );
}
