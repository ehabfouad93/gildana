import Link from "next/link";
import { BreakdownSync } from "@/components/breakdown-sync";
import { Filters } from "@/components/filters";
import { NotMeasuredList } from "@/components/not-measured";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { pageContext, qs, type SP } from "@/lib/page";
import { SOURCE_LABEL } from "@/components/table-types";
import { audienceInsights } from "@/lib/queries";
import { mrow } from "@/lib/rows";
import { canEdit } from "@/lib/tenancy";

export const metadata = { title: "Audiences" };

const GROUPS: Record<string, { label: string; types: string[] }> = {
  interests: { label: "Interests & behaviours", types: ["interest", "behavior", "demographic"] },
  audiences: { label: "Custom & lookalike audiences", types: ["audience", "lookalike"] },
  b2b: { label: "Job titles & industries (LinkedIn)", types: ["job_title", "seniority", "industry", "company_size"] },
  keywords: { label: "Keywords (Google)", types: ["keyword"] },
  demo: { label: "Age, gender & location", types: ["age", "gender", "geo", "language"] },
  placement: { label: "Placements & devices", types: ["placement", "device"] },
};

/** Every targeting option the client uses, ranked across all campaigns and platforms. */
export default async function Audiences({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams, "audiences");
  const { scope, range, t } = c;
  const g = GROUPS[c.one("g")] ? c.one("g") : "interests";
  const { rows, notMeasured } = await audienceInsights(scope, range, { types: GROUPS[g].types });

  return (
    <>
      <PageHeader title={t.audiences} sub="Which targeting choices deliver the cheapest results, across every campaign">
        <Filters t={t} freshness={c.freshness} accounts={c.accounts} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} extra={{ g }} />
      </PageHeader>
      <BreakdownSync accountIds={c.scope.accountIds} module="audiences" path="/audiences" />
      <div className="mb-4 flex flex-wrap gap-1">
        {Object.entries(GROUPS).map(([k, v]) => (
          <Link key={k} href={qs(c.sp, { g: k })} className={`pill !px-3 !py-1.5 ${k === g ? "bg-accent text-white" : "bg-panel text-muted hover:text-ink"}`}>{v.label}</Link>
        ))}
      </div>
      <div className="card mb-4 text-sm text-muted">
        Only <b>exact</b> results are shown: <b>Reported</b> by the platform per segment, <b>Tested</b> in a split test, or <b>Measured</b> from an ad set that targets that one option alone. Nothing is estimated.
      </div>
      <div className="card">
        <DataTable currency={scope.currency} initialSort="spend" csvName={`audiences-${g}`}
          cols={[
            { key: "option", label: "Option", wrap: true, sub: "note" }, { key: "source", label: "Source", fmt: "badge" }, { key: "type", label: "Type" }, { key: "adSets", label: "Ad sets", fmt: "num" },
            { key: "spend", label: "Spend", fmt: "money" }, { key: "impressions", label: "Impr.", fmt: "compact" }, { key: "ctr", label: "CTR", fmt: "pct" },
            { key: "cpc", label: "CPC", fmt: "money2" }, { key: "leads", label: "Leads", fmt: "num" }, { key: "cpl", label: "CPL", fmt: "money2" },
            { key: "conversions", label: "Conv.", fmt: "num" }, { key: "cpa", label: "CPA", fmt: "money2" },
          ]}
          rows={rows.map((r) => ({ ...mrow(r.m), option: r.value, source: SOURCE_LABEL[r.source], note: r.note, type: r.type.replace(/_/g, " "), adSets: r.adSets }))} />
      </div>
      <div className="mt-4"><NotMeasuredList items={notMeasured} canTest={canEdit(c.viewer)} /></div>
    </>
  );
}
