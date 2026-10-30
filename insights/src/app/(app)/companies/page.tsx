import { createCompanyAction, deleteCompanyAction, setBudgetAction, updateCompanyAction } from "@/app/actions/accounts";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { PLATFORM_META } from "@/lib/platforms";
import { canEdit, requireWorkspace } from "@/lib/tenancy";

export const metadata = { title: "Companies" };

export default async function Companies() {
  const v = await requireWorkspace();
  const edit = canEdit(v);
  const now = new Date();
  const month = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1));
  const [client, companies] = await Promise.all([
    db.client.findUniqueOrThrow({ where: { id: v.clientId } }),
    db.company.findMany({
      where: { clientId: v.clientId }, orderBy: { name: "asc" },
      include: { accounts: { select: { platform: true } }, budgets: { where: { month, platform: null } } },
    }),
  ]);

  return (
    <>
      <PageHeader title="Companies" sub={client.isAgency ? "Each brand you manage. Ad accounts and websites belong to a company." : "Your brands. Ad accounts and websites belong to a company."} />
      <div className="space-y-3">
        {companies.map((c) => {
          const counts = c.accounts.reduce<Record<string, number>>((m, a) => ((m[a.platform] = (m[a.platform] ?? 0) + 1), m), {});
          return (
            <div key={c.id} className="card">
              <form action={updateCompanyAction} className="grid gap-2 md:grid-cols-[2fr_2fr_1fr_auto]">
                <input type="hidden" name="id" value={c.id} />
                <input className="input" name="name" defaultValue={c.name} disabled={!edit} required />
                <input className="input" name="website" defaultValue={c.website} placeholder="https://" disabled={!edit} />
                <input className="input" name="industry" defaultValue={c.industry} placeholder="Industry" disabled={!edit} />
                {edit && <button className="btn-ghost">Save</button>}
              </form>
              <div className="mt-3 flex flex-wrap items-center gap-2 text-xs">
                {Object.entries(counts).map(([p, n]) => <span key={p} className="pill bg-bg">{PLATFORM_META[p as keyof typeof PLATFORM_META].label}: {n}</span>)}
                {!c.accounts.length && <span className="text-muted">No ad accounts assigned yet.</span>}
                <a href={`/overview?company=${c.id}`} className="text-accent hover:underline">View dashboard →</a>
                {edit && (
                  <>
                    <form action={setBudgetAction} className="ms-auto flex items-center gap-1">
                      <input type="hidden" name="companyId" value={c.id} />
                      <span className="text-muted">Budget this month ({client.reportingCurrency})</span>
                      <input className="input !w-28 !py-1" name="amount" type="number" min="0" step="1" defaultValue={c.budgets[0] ? Number(c.budgets[0].amount) : ""} />
                      <button className="btn-ghost !py-1">Set</button>
                    </form>
                    <form action={deleteCompanyAction}>
                      <input type="hidden" name="id" value={c.id} />
                      <button className="text-bad hover:underline">Delete</button>
                    </form>
                  </>
                )}
              </div>
            </div>
          );
        })}
        {edit && (
          <form action={createCompanyAction} className="card grid gap-2 md:grid-cols-[2fr_2fr_1fr_auto]">
            <input className="input" name="name" placeholder="New company name" required />
            <input className="input" name="website" placeholder="https://" />
            <input className="input" name="industry" placeholder="Industry" />
            <button className="btn">Add company</button>
          </form>
        )}
      </div>
    </>
  );
}
