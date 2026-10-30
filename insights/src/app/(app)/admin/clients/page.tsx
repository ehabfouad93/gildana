import Link from "next/link";
import { createClientAction, openWorkspaceAction } from "@/app/actions/admin";
import { PageHeader } from "@/components/shell";
import { db } from "@/lib/db";
import { lastNDays, toDate } from "@/lib/dates";
import { convert, fxTable } from "@/lib/fx";
import { money } from "@/lib/format";
import { requireAdmin } from "@/lib/tenancy";

export const metadata = { title: "Clients" };

export default async function ClientsPage() {
  await requireAdmin();
  const r = lastNDays(30);
  const [clients, spendRows, fx] = await Promise.all([
    db.client.findMany({ orderBy: { name: "asc" }, include: { _count: { select: { companies: true, users: true } }, connections: { select: { platform: true, _count: { select: { accounts: true } } } } } }),
    db.$queryRaw<{ clientId: string; currency: string; spend: number }[]>`
      SELECT c."clientId", a.currency, SUM(s.spend)::float AS spend
      FROM "StatDaily" s JOIN "AdAccount" a ON a.id = s."accountId" JOIN "AdConnection" c ON c.id = a."connectionId"
      WHERE s.level = 'ACCOUNT' AND s.date BETWEEN ${toDate(r.from)} AND ${toDate(r.to)} AND a."companyId" IS NOT NULL AND a.enabled
      GROUP BY 1, 2`,
    fxTable(),
  ]);
  const spend = new Map<string, number>();
  for (const s of spendRows) {
    const target = clients.find((c) => c.id === s.clientId)?.reportingCurrency ?? "USD";
    spend.set(s.clientId, (spend.get(s.clientId) ?? 0) + convert(s.spend, s.currency, target, fx));
  }

  return (
    <>
      <PageHeader title="Clients" sub={`${clients.length} clients · spend over the last 30 days`} />
      <div className="card mb-4 overflow-x-auto">
        <table className="tbl">
          <thead><tr><th>Client</th><th>Type</th><th className="num">Companies</th><th className="num">Ad accounts</th><th className="num">Users</th><th className="num">Spend (30d)</th><th /></tr></thead>
          <tbody>
            {clients.map((c) => (
              <tr key={c.id} className={c.active ? "" : "opacity-50"}>
                <td><Link href={`/admin/clients/${c.id}`} className="font-medium text-accent hover:underline">{c.name}</Link></td>
                <td>{c.isAgency ? "Agency" : "Brand"}</td>
                <td className="num">{c._count.companies}</td>
                <td className="num">{c.connections.reduce((n, x) => n + x._count.accounts, 0)}</td>
                <td className="num">{c._count.users}</td>
                <td className="num">{money(spend.get(c.id) ?? 0, c.reportingCurrency, 0)}</td>
                <td>
                  <form action={openWorkspaceAction}><input type="hidden" name="clientId" value={c.id} /><button className="btn-ghost !py-1">Open dashboard</button></form>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <form action={createClientAction} className="card grid items-end gap-3 md:grid-cols-5">
        <div className="md:col-span-2"><label className="label">New client name</label><input className="input" name="name" required /></div>
        <div><label className="label">Reporting currency</label><input className="input" name="currency" defaultValue="USD" maxLength={3} /></div>
        <div><label className="label">Timezone</label><input className="input" name="timezone" defaultValue="Africa/Cairo" /></div>
        <div className="flex items-center gap-3">
          <label className="flex items-center gap-1 text-sm"><input type="checkbox" name="isAgency" /> Agency</label>
          <button className="btn">Add client</button>
        </div>
      </form>
    </>
  );
}
