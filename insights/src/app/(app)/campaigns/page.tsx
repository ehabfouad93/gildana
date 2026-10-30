import { Filters } from "@/components/filters";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { METRIC_COLS } from "@/components/table-types";
import { pageContext, qs, type SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { campaignTable } from "@/lib/queries";
import { mrow } from "@/lib/rows";

export const metadata = { title: "Campaigns" };

export default async function Campaigns({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams);
  const { scope, range, t } = c;
  const rows = await campaignTable(scope, range, { platform: c.platform });
  const keep = qs({ days: c.one("days"), from: c.one("from"), to: c.one("to") });

  return (
    <>
      <PageHeader title={t.campaigns} sub={`${rows.length} campaigns · ${range.from} → ${range.to}`}>
        <Filters t={t} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} />
      </PageHeader>
      <div className="card">
        <DataTable currency={scope.currency} initialSort="spend" csvName="campaigns"
          cols={[{ key: "name", label: t.campaign, href: "href", sub: "sub", wrap: true }, { key: "status", label: t.status }, { key: "objective", label: t.objective }, ...METRIC_COLS]}
          rows={rows.map((x) => ({
            name: x.name, href: `/campaigns/${x.id}${keep}`, sub: `${PLATFORM_META[x.platform].label} · ${x.account.name} · ${x.account.company?.name ?? ""}`,
            status: x.status, objective: x.objective.replace(/^OUTCOME_/, ""), ...mrow(x.m),
          }))} />
      </div>
    </>
  );
}
