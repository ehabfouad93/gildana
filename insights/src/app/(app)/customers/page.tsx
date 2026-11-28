import Link from "next/link";
import { resolveNowAction } from "@/app/actions/customers";
import { ScoreBar, StagePill, when } from "@/components/customer-bits";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { canEdit, canFeature, requireModule } from "@/lib/tenancy";
import { STAGES, STAGE_LABEL } from "@/modules/customers/profile";
import { countCustomers, listCustomers } from "@/modules/customers/query";

export const metadata = { title: "Customers" };

type SP = Record<string, string | undefined>;

/** Everyone the workspace knows: ad leads, website visitors who identified themselves, CRM contacts — one profile per person. */
export default async function Customers({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const v = await requireModule("customers");
  const a = { clientId: v.clientId, companyIds: v.access ? v.companyIds : null, pii: canFeature(v, "customers.pii") };
  const [counts, page, projects, unresolved] = await Promise.all([
    countCustomers(a),
    listCustomers(a, { q: sp.q, stage: sp.stage, project: sp.project, minIntent: Number(sp.intent) || undefined, sort: (sp.sort as "intent" | "value") || "recent", cursor: sp.cursor, limit: 50 }),
    db.project.findMany({ where: { clientId: v.clientId }, orderBy: { name: "asc" }, select: { id: true, name: true } }),
    db.lead.count({ where: { customerId: null, account: { connection: { clientId: v.clientId } } } }),
  ]);
  const pname = new Map(projects.map((p) => [p.id, p.name]));
  const link = (patch: SP) => {
    const u = new URLSearchParams();
    for (const [k, x] of Object.entries({ ...sp, cursor: undefined, deleted: undefined, ...patch })) if (x) u.set(k, x);
    const s = u.toString();
    return s ? `/customers?${s}` : "/customers";
  };

  return (
    <>
      <PageHeader title="Customers" sub={`${counts.total.toLocaleString()} people across ads, website and CRM — joined only on CRM ID, phone, email or account, never on IP or behaviour`}>
        {canFeature(v, "customers.review") && <Link className="btn-ghost" href="/customers/review">Identity review{counts.pendingReviews ? <span className="pill bg-amber-100 text-amber-800">{counts.pendingReviews}</span> : null}</Link>}
        {canFeature(v, "customers.projects") && <Link className="btn-ghost" href="/customers/projects">Projects</Link>}
        <a className="btn-ghost" href="/api/v1/docs" target="_blank" rel="noreferrer">API</a>
      </PageHeader>

      {sp.deleted && <div className="card mb-4 border-emerald-200 text-sm">The profile was deleted.</div>}
      {unresolved > 0 && (
        <div className="card mb-4 flex flex-wrap items-center justify-between gap-3 text-sm">
          <span>{unresolved.toLocaleString()} synced ad leads are still being turned into profiles in the background.</span>
          {canEdit(v) && <form action={resolveNowAction}><button className="btn-ghost">Process now</button></form>}
        </div>
      )}

      <div className="mb-4 flex flex-wrap gap-2 text-sm">
        <Link href={link({ stage: undefined })} className={`pill border ${!sp.stage ? "border-accent text-accent" : "border-line"}`}>All · {counts.total.toLocaleString()}</Link>
        {STAGES.filter((s) => counts.byStage[s]).map((s) => (
          <Link key={s} href={link({ stage: s })} className={`pill border ${sp.stage === s ? "border-accent text-accent" : "border-line"}`}>{STAGE_LABEL[s]} · {counts.byStage[s].toLocaleString()}</Link>
        ))}
      </div>

      <form className="card mb-4 flex flex-wrap items-end gap-3 text-sm" action="/customers">
        {sp.stage && <input type="hidden" name="stage" value={sp.stage} />}
        <label className="min-w-56 flex-1"><span className="mb-1 block text-xs text-muted">Search</span><input className="input" name="q" defaultValue={sp.q} placeholder="Name, or an exact phone / email" /></label>
        <label><span className="mb-1 block text-xs text-muted">Project</span>
          <select className="input" name="project" defaultValue={sp.project ?? ""}><option value="">Any</option>{projects.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
        </label>
        <label><span className="mb-1 block text-xs text-muted">Intent at least</span><input className="input w-24" name="intent" type="number" min={0} max={100} defaultValue={sp.intent} /></label>
        <label><span className="mb-1 block text-xs text-muted">Sort</span>
          <select className="input" name="sort" defaultValue={sp.sort ?? "recent"}><option value="recent">Last seen</option><option value="intent">Purchase intent</option><option value="value">Value</option></select>
        </label>
        <button className="btn">Filter</button>
      </form>

      <div className="card overflow-x-auto">
        <table className="tbl">
          <thead><tr><th>Customer</th><th>Contact</th><th>Stage</th><th>Projects</th><th>Intent</th><th>Value</th><th>Identity</th><th>Sources</th><th>Last seen</th></tr></thead>
          <tbody>
            {page.items.map((c) => (
              <tr key={c.id}>
                <td><Link className="font-medium hover:underline" href={`/customers/${c.id}`}>{c.name || "Unnamed"}</Link>{c.city && <div className="text-xs text-muted">{c.city}</div>}</td>
                <td className="text-xs">{c.phone}<div className="text-muted">{c.email}</div></td>
                <td><StagePill stage={c.stage} /></td>
                <td className="!whitespace-normal text-xs">{c.projectIds.map((p) => pname.get(p)).filter(Boolean).join(", ") || <span className="text-muted">—</span>}</td>
                <td><ScoreBar value={c.scores.intent} /></td>
                <td><ScoreBar value={c.scores.value} /></td>
                <td><ScoreBar value={c.scores.identity} /></td>
                <td className="text-xs text-muted">{c.sources.join(", ")}</td>
                <td className="text-xs text-muted">{when(c.lastSeenAt)}</td>
              </tr>
            ))}
            {!page.items.length && <tr><td colSpan={9} className="!whitespace-normal text-muted">No customers match. Profiles are created from synced ad leads, website visitors who send a form (tracking tag with <code>data-identify=&quot;1&quot;</code> or <code>gildana(&quot;identify&quot;, …)</code>) and CRM / API records.</td></tr>}
          </tbody>
        </table>
        {page.nextCursor && <div className="mt-3 text-end"><Link className="btn-ghost" href={link({ cursor: page.nextCursor })}>Next 50 →</Link></div>}
      </div>
    </>
  );
}
