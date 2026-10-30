import Link from "next/link";
import { Filters } from "@/components/filters";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { db } from "@/lib/db";
import { pageContext, qs, type SP } from "@/lib/page";
import { keywordTable, placementTable, searchTermTable } from "@/lib/queries";
import { mrow } from "@/lib/rows";
import { scopedAccountIds } from "@/lib/tenancy";

export const metadata = { title: "Google Search" };

const TABS = { keywords: "Keywords", terms: "Search terms", placements: "Display & YouTube placements" } as const;

export default async function Google({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams);
  const { range, t } = c;
  const accountIds = await scopedAccountIds(c.client.id, { companyId: c.companyId, platform: "GOOGLE" });
  const scope = { ...c.scope, accountIds };
  const tab = (c.one("tab") in TABS ? c.one("tab") : "keywords") as keyof typeof TABS;
  const camps = new Map((await db.campaign.findMany({ where: { accountId: { in: accountIds } }, select: { id: true, name: true } })).map((x) => [x.id, x.name]));

  return (
    <>
      <PageHeader title={t.google} sub="Keyword, search-term and placement performance across every Google Ads account">
        <Filters t={t} companies={c.companies} companyId={c.companyId} range={range} days={c.one("days")} showPlatform={false} extra={{ tab }} />
      </PageHeader>
      <nav className="mb-4 flex gap-1 border-b border-line">
        {Object.entries(TABS).map(([k, label]) => (
          <Link key={k} href={qs(c.sp, { tab: k })} className={`-mb-px border-b-2 px-3 py-2 text-sm ${tab === k ? "border-accent font-medium" : "border-transparent text-muted"}`}>{label}</Link>
        ))}
      </nav>
      {!accountIds.length ? <div className="card text-sm text-muted">No Google Ads accounts connected.</div> : (
        <div className="card">
          {tab === "keywords" && <Keywords />}
          {tab === "terms" && <Terms />}
          {tab === "placements" && <Placements />}
        </div>
      )}
    </>
  );

  async function Keywords() {
    const rows = await keywordTable(scope, range);
    return <DataTable currency={scope.currency} initialSort="spend" csvName="google-keywords"
      cols={[{ key: "text", label: "Keyword", sub: "campaign" }, { key: "matchType", label: "Match" }, { key: "qs", label: "QS", fmt: "dec" }, { key: "spend", label: "Spend", fmt: "money" }, { key: "impressions", label: "Impr.", fmt: "compact" }, { key: "clicks", label: "Clicks", fmt: "num" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "cpc", label: "CPC", fmt: "money2" }, { key: "conversions", label: "Conv.", fmt: "num" }, { key: "convRate", label: "Conv. rate", fmt: "pct" }, { key: "cpa", label: "CPA", fmt: "money2" }]}
      rows={rows.map((r) => ({ ...mrow(r.m), text: r.text, campaign: camps.get(r.campaignId) ?? "", matchType: r.matchType, qs: r.qs }))} />;
  }

  async function Terms() {
    const rows = await searchTermTable(scope, range);
    return <DataTable currency={scope.currency} initialSort="spend" csvName="google-search-terms"
      cols={[{ key: "term", label: "Search term", sub: "campaign", wrap: true }, { key: "keywordText", label: "Keyword" }, { key: "matchType", label: "Match" }, { key: "spend", label: "Spend", fmt: "money" }, { key: "clicks", label: "Clicks", fmt: "num" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "cpc", label: "CPC", fmt: "money2" }, { key: "conversions", label: "Conv.", fmt: "num" }, { key: "cpa", label: "CPA", fmt: "money2" }]}
      rows={rows.map((r) => ({ ...mrow(r.m), term: r.term, campaign: camps.get(r.campaignId) ?? "", keywordText: r.keywordText, matchType: r.matchType }))} />;
  }

  async function Placements() {
    const rows = await placementTable(scope, range);
    return <DataTable currency={scope.currency} initialSort="spend" csvName="google-placements"
      cols={[{ key: "display", label: "Placement", sub: "campaign", wrap: true }, { key: "kind", label: "Type" }, { key: "target", label: "URL / ID" }, { key: "spend", label: "Spend", fmt: "money" }, { key: "impressions", label: "Impr.", fmt: "compact" }, { key: "clicks", label: "Clicks", fmt: "num" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "conversions", label: "Conv.", fmt: "num" }, { key: "cpa", label: "CPA", fmt: "money2" }]}
      rows={rows.map((r) => ({ ...mrow(r.m), display: r.display || r.target, campaign: camps.get(r.campaignId) ?? "", kind: r.kind.replace(/_/g, " "), target: r.target }))} />;
  }
}
