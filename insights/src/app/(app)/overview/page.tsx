import { Chart } from "@/components/chart";
import { donutOption, lineOption } from "@/components/charts";
import { Filters } from "@/components/filters";
import { KpiGrid } from "@/components/kpi";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { METRIC_COLS } from "@/components/table-types";
import { pageContext, type SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { campaignTable, daily, splits, totals } from "@/lib/queries";
import { mrow } from "@/lib/rows";

export const metadata = { title: "Overview" };

export default async function Overview({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams);
  const { scope, range, prev, t } = c;
  const [cur, before, series, sp, camps] = await Promise.all([
    totals(scope, range), totals(scope, prev), daily(scope, range), splits(scope, range), campaignTable(scope, range),
  ]);

  return (
    <>
      <PageHeader title={t.overview} sub={`${c.client.name} · ${range.from} → ${range.to} · ${scope.currency}`}>
        <Filters t={t} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} />
      </PageHeader>

      {!scope.accountIds.length ? (
        <div className="card text-sm text-muted">No ad accounts are connected and assigned to a company yet. Go to <a className="text-accent underline" href="/connections">Ad accounts</a>.</div>
      ) : (
        <div className="space-y-4">
          <KpiGrid cur={cur} prev={before} currency={scope.currency} t={t} keys={["spend", "impressions", "clicks", "ctr", "cpc", "cpm", "conversions", "leads", "cpl", "cpa", "roas", "reach"]} />

          <div className="grid gap-4 lg:grid-cols-3">
            <div className="card lg:col-span-2">
              <h2 className="mb-2 text-sm font-semibold">{t.trend}</h2>
              <Chart option={lineOption(series.map((d) => d.date), [
                { name: t.spendLabel, data: series.map((d) => d.spend), type: "bar", color: "#c4973a" },
                { name: t.conversions, data: series.map((d) => d.conversions), axis: 1, color: "#2563eb" },
              ], [scope.currency, t.conversions])} />
            </div>
            <div className="card">
              <h2 className="mb-2 text-sm font-semibold">{t.spend} · {t.byPlatform}</h2>
              <Chart option={donutOption(sp.byPlatform.map((p) => ({ name: PLATFORM_META[p.platform].label, value: p.m.spend, color: PLATFORM_META[p.platform].color })))} />
            </div>
          </div>

          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">{t.byPlatform}</h2>
            <DataTable search={false} currency={scope.currency} initialSort="spend" cols={[{ key: "name", label: t.platform, href: "href" }, ...METRIC_COLS]}
              rows={sp.byPlatform.map((p) => ({ name: PLATFORM_META[p.platform].label, href: `/performance?platform=${PLATFORM_META[p.platform].slug}&days=${c.one("days")}`, ...mrow(p.m) }))} />
          </div>

          {c.companies.length > 1 && (
            <div className="card">
              <h2 className="mb-2 text-sm font-semibold">{t.byCompany}</h2>
              <DataTable search={false} currency={scope.currency} initialSort="spend" cols={[{ key: "name", label: t.company, href: "href" }, ...METRIC_COLS]}
                rows={sp.byCompany.map((x) => ({ name: x.name, href: `/overview?company=${x.id}&days=${c.one("days")}`, ...mrow(x.m) }))} />
            </div>
          )}

          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">{t.topCampaigns}</h2>
            <DataTable currency={scope.currency} initialSort="spend" limit={10} csvName="top-campaigns"
              cols={[{ key: "name", label: t.campaign, href: "href", sub: "sub", wrap: true }, ...METRIC_COLS]}
              rows={camps.filter((x) => x.m.impressions > 0).map((x) => ({ name: x.name, sub: `${PLATFORM_META[x.platform].label} · ${x.account.company?.name ?? ""}`, href: `/campaigns/${x.id}?days=${c.one("days")}`, ...mrow(x.m) }))} />
          </div>
        </div>
      )}
    </>
  );
}
