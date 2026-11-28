import Link from "next/link";
import { redirect } from "next/navigation";
import { deleteProjectAction, saveProjectAction } from "@/app/actions/customers";
import { ConfirmButton } from "@/components/confirm-button";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { canEdit, canFeature, companyWhere, requireModule } from "@/lib/tenancy";

export const metadata = { title: "Projects" };

/** Projects customers can be interested in — found in page URLs, event properties, campaign and form names, and CRM fields. */
export default async function Projects() {
  const v = await requireModule("customers");
  if (!canFeature(v, "customers.projects")) redirect("/customers");
  const edit = canEdit(v);
  const [projects, companies, counts] = await Promise.all([
    db.project.findMany({ where: { clientId: v.clientId }, orderBy: { name: "asc" } }),
    db.company.findMany({ where: { clientId: v.clientId, ...companyWhere(v) }, orderBy: { name: "asc" }, select: { id: true, name: true } }),
    db.$queryRaw<{ id: string; n: bigint }[]>`SELECT unnest("projectIds") AS id, count(*) AS n FROM "Customer" WHERE "clientId" = ${v.clientId} AND "mergedIntoId" IS NULL GROUP BY 1`,
  ]);
  const n = new Map(counts.map((c) => [c.id, Number(c.n)]));
  const Fields = ({ p }: { p?: (typeof projects)[number] }) => (
    <>
      {p && <input type="hidden" name="id" value={p.id} />}
      <label className="min-w-40 flex-1"><span className="mb-1 block text-xs text-muted">Name</span><input className="input" name="name" defaultValue={p?.name} required placeholder="Porto Sokhna" /></label>
      <label className="min-w-56 flex-[2]"><span className="mb-1 block text-xs text-muted">Other spellings (comma separated)</span><input className="input" name="aliases" defaultValue={p?.aliases.join(", ")} placeholder="sokhna, بورتو السخنة, PS" /></label>
      <label><span className="mb-1 block text-xs text-muted">Price from</span><input className="input w-32" name="priceFrom" defaultValue={p?.priceFrom ?? ""} placeholder="3000000" /></label>
      <label><span className="mb-1 block text-xs text-muted">to</span><input className="input w-32" name="priceTo" defaultValue={p?.priceTo ?? ""} placeholder="9000000" /></label>
      <label><span className="mb-1 block text-xs text-muted">Currency</span><input className="input w-20" name="currency" defaultValue={p?.currency ?? "EGP"} /></label>
      <label><span className="mb-1 block text-xs text-muted">Company</span>
        <select className="input" name="companyId" defaultValue={p?.companyId ?? ""}><option value="">All</option>{companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
      </label>
    </>
  );
  return (
    <>
      <PageHeader title="Projects" sub="Customers are linked to the projects they look at, ask about and buy">
        <Link className="btn-ghost" href="/customers">← Customers</Link>
      </PageHeader>
      <div className="space-y-3">
        {projects.map((p) => (
          <div key={p.id} className="card">
            <form action={saveProjectAction} className="flex flex-wrap items-end gap-3 text-sm">
              <Fields p={p} />
              <span className="text-xs text-muted">slug: {p.slug} · {n.get(p.id) ?? 0} customers</span>
              {edit && <button className="btn-ghost">Save</button>}
            </form>
            {edit && <form action={deleteProjectAction} className="mt-2 text-end"><input type="hidden" name="id" value={p.id} /><ConfirmButton className="text-xs text-bad hover:underline" message={`Delete ${p.name}? Customers keep their history; the project just stops being detected.`}>Delete</ConfirmButton></form>}
          </div>
        ))}
        {edit && (
          <div className="card">
            <h2 className="mb-3 font-semibold">Add a project</h2>
            <form action={saveProjectAction} className="flex flex-wrap items-end gap-3 text-sm"><Fields /><button className="btn">Add</button></form>
          </div>
        )}
        <div className="card text-sm text-muted">
          How projects are detected: the name, slug or any other spelling appearing as whole words in a page URL (<code>/projects/porto-sokhna</code>), a tracker event (<code>gildana(&quot;event&quot;, &quot;payment_plan_view&quot;, {"{ project: \"porto-sokhna\" }"})</code>), a campaign, ad set or lead-form name, a lead-form answer, or the <code>project</code> field of an API event. Prices feed the value score.
        </div>
      </div>
    </>
  );
}
