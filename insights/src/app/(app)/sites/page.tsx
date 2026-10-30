import Link from "next/link";
import { createSiteAction } from "@/app/actions/sites";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { canEdit, requireWorkspace } from "@/lib/tenancy";

export const metadata = { title: "Websites" };

export default async function Sites() {
  const v = await requireWorkspace();
  const since = new Date(Date.now() - 30 * 86_400_000);
  const [companies, sites] = await Promise.all([
    db.company.findMany({ where: { clientId: v.clientId }, orderBy: { name: "asc" } }),
    db.site.findMany({ where: { company: { clientId: v.clientId } }, include: { company: true, _count: { select: { sessions: { where: { startedAt: { gte: since } } } } } }, orderBy: { name: "asc" } }),
  ]);
  return (
    <>
      <PageHeader title="Websites" sub="Analytics, heatmaps, session recordings, SEO and AI-search (GEO) audits for each company's site" />
      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {sites.map((s) => (
          <Link key={s.id} href={`/sites/${s.id}/analytics`} className="card transition hover:shadow-md">
            <div className="flex items-center gap-2"><b>{s.name}</b>{s.isDemo && <span className="pill bg-amber-100 text-amber-800">demo</span>}</div>
            <div className="text-sm text-muted">{s.domain} · {s.company.name}</div>
            <div className="mt-3 text-2xl font-semibold tabular-nums">{s._count.sessions.toLocaleString()}</div>
            <div className="text-xs text-muted">sessions, last 30 days</div>
          </Link>
        ))}
      </div>
      {canEdit(v) && (
        <form action={createSiteAction} className="card mt-4 grid items-end gap-3 md:grid-cols-4">
          <div><label className="label">Company</label><select className="input" name="companyId" required>{companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></div>
          <div><label className="label">Domain</label><input className="input" name="domain" placeholder="example.com" required /></div>
          <div><label className="label">Name</label><input className="input" name="name" placeholder="Main website" /></div>
          <button className="btn">Add website</button>
        </form>
      )}
    </>
  );
}
