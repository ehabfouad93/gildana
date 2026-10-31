import Link from "next/link";
import { Filters } from "@/components/filters";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { pageContext, qs, type SP } from "@/lib/page";
import { SOURCE_LABEL } from "@/components/table-types";
import { audienceInsights } from "@/lib/queries";
import { mrow } from "@/lib/rows";

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
  const c = await pageContext(searchParams);
  const { scope, range, t } = c;
  const g = GROUPS[c.one("g")] ? c.one("g") : "interests";
  const only = c.one("only") === "1";
  const all = await audienceInsights(scope, range, { types: GROUPS[g].types });
  const rows = only ? all.filter((r) => r.source === "reported" || r.source === "measured" || r.source === "tested") : all;

  return (
    <>
      <PageHeader title={t.audiences} sub="Which targeting choices deliver the cheapest results, across every campaign">
        <Filters t={t} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} extra={{ g }} />
      </PageHeader>
      <div className="mb-4 flex flex-wrap gap-1">
        {Object.entries(GROUPS).map(([k, v]) => (
          <Link key={k} href={qs(c.sp, { g: k })} className={`pill !px-3 !py-1.5 ${k === g ? "bg-accent text-white" : "bg-panel text-muted hover:text-ink"}`}>{v.label}</Link>
        ))}
      </div>
      <div className="card mb-4 flex flex-wrap items-center gap-3 text-sm">
        <span className="text-muted">Each number is labelled: <b>Reported</b> by the platform per segment, <b>Tested</b> / <b>Measured</b> from single-segment ad sets, <b>Estimated</b> by the model for mixed Meta ad sets, or an <b>Ad-set ranking</b> when there is too little variation.</span>
        <a href={qs(c.sp, { only: only ? undefined : "1" })} className={`pill !px-3 !py-1.5 ms-auto ${only ? "bg-ink text-white" : "bg-bg text-muted"}`}>{only ? "✓ " : ""}Only measured per segment</a>
      </div>
      <div className="card">
        <DataTable currency={scope.currency} initialSort="spend" csvName={`audiences-${g}`}
          cols={[
            { key: "option", label: "Option", wrap: true, sub: "note" }, { key: "source", label: "Source", fmt: "badge" }, { key: "type", label: "Type" }, { key: "adSets", label: "Ad sets", fmt: "num" },
            { key: "spend", label: "Spend", fmt: "money" }, { key: "impressions", label: "Impr.", fmt: "compact" }, { key: "ctr", label: "CTR", fmt: "pct" },
            { key: "cpc", label: "CPC", fmt: "money2" }, { key: "leads", label: "Leads", fmt: "num" }, { key: "cpl", label: "CPL", fmt: "money2" },
            { key: "conversions", label: "Conv.", fmt: "num" }, { key: "cpa", label: "CPA", fmt: "money2" },
          ]}
          rows={rows.map((r) => ({ ...mrow(r.m), option: r.value, source: SOURCE_LABEL[r.source], note: r.source === "estimated" || r.source === "adset" ? r.note : "", type: r.type.replace(/_/g, " "), adSets: r.adSets }))} />
      </div>
    </>
  );
}
