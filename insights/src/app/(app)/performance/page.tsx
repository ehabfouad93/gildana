import Link from "next/link";
import { Chart } from "@/components/chart";
import { lineOption } from "@/components/charts";
import { Filters } from "@/components/filters";
import { KpiGrid, METRIC_LABEL, type MetricKey } from "@/components/kpi";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { METRIC_COLS } from "@/components/table-types";
import { DIM_LABEL } from "@/lib/labels";
import { pageContext, qs, type SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { breakdown, campaignTable, daily, splits, totals } from "@/lib/queries";
import { mrow } from "@/lib/rows";

export const metadata = { title: "Performance" };

const CHART_METRICS: MetricKey[] = ["spend", "impressions", "clicks", "ctr", "cpc", "cpm", "conversions", "leads", "cpl", "cpa", "roas"];
const DIMS = ["age", "gender", "country", "device", "placement", "hour"];

export default async function Performance({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams);
  const { scope, range, prev, t } = c;
  const metric = (CHART_METRICS.includes(c.one("metric") as MetricKey) ? c.one("metric") : "cpl") as MetricKey;
  const dim = DIMS.includes(c.one("dim")) ? c.one("dim") : "age";
  const labels = METRIC_LABEL(t);

  const [cur, before, series, prevSeries, sp, camps, bd] = await Promise.all([
    totals(scope, range), totals(scope, prev), daily(scope, range), daily(scope, prev), splits(scope, range),
    campaignTable(scope, range, { platform: c.platform }), breakdown(scope, range, dim),
  ]);

  return (
    <>
      <PageHeader title={t.performance} sub={`${range.from} → ${range.to}`}>
        <Filters t={t} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} extra={{ metric, dim }} />
      </PageHeader>
      <div className="space-y-4">
        <KpiGrid cur={cur} prev={before} currency={scope.currency} t={t} keys={["spend", "impressions", "reach", "frequency", "clicks", "ctr", "cpc", "cpm", "conversions", "convRate", "cpa", "roas"]} />

        <div className="card">
          <div className="mb-2 flex flex-wrap gap-1">
            {CHART_METRICS.map((m) => (
              <Link key={m} href={qs(c.sp, { metric: m })} className={`pill ${m === metric ? "bg-accent text-white" : "bg-bg text-muted hover:text-ink"}`}>{labels[m]}</Link>
            ))}
          </div>
          <Chart option={lineOption(series.map((d) => d.date), [
            { name: `${labels[metric]} (current)`, data: series.map((d) => d[metric] as number | null), color: "#c4973a" },
            { name: `${labels[metric]} (previous period)`, data: prevSeries.map((d) => d[metric] as number | null), color: "#94a3b8" },
          ])} />
        </div>

        <div className="card">
          <div className="mb-2 flex flex-wrap items-center gap-1">
            <h2 className="me-2 text-sm font-semibold">Audience breakdown</h2>
            {DIMS.map((d) => (
              <Link key={d} href={qs(c.sp, { dim: d })} className={`pill ${d === dim ? "bg-accent text-white" : "bg-bg text-muted hover:text-ink"}`}>{DIM_LABEL[d]}</Link>
            ))}
          </div>
          <DataTable search={false} currency={scope.currency} initialSort={dim === "hour" ? "bucket" : "spend"}
            cols={[{ key: "bucket", label: DIM_LABEL[dim] }, ...METRIC_COLS]} rows={bd.map((r) => ({ ...mrow(r.m), bucket: r.value }))} />
        </div>

        <div className="card">
          <h2 className="mb-2 text-sm font-semibold">{t.byAccount}</h2>
          <DataTable currency={scope.currency} initialSort="spend" csvName="performance-by-account"
            cols={[{ key: "name", label: t.account, sub: "sub" }, { key: "company", label: t.company }, ...METRIC_COLS]}
            rows={sp.byAccount.map((a) => ({ name: a.name, sub: PLATFORM_META[a.platform].label, company: a.company, ...mrow(a.m) }))} />
        </div>

        <div className="card">
          <h2 className="mb-2 text-sm font-semibold">{t.campaigns}</h2>
          <DataTable currency={scope.currency} initialSort="spend" csvName="performance-by-campaign"
            cols={[{ key: "name", label: t.campaign, href: "href", sub: "sub", wrap: true }, { key: "status", label: t.status }, ...METRIC_COLS]}
            rows={camps.map((x) => ({ name: x.name, href: `/campaigns/${x.id}${qs({ days: c.one("days") })}`, sub: `${PLATFORM_META[x.platform].label} · ${x.account.name}`, status: x.status, ...mrow(x.m) }))} />
        </div>
      </div>
    </>
  );
}
