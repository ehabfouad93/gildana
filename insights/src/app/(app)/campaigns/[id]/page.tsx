import Link from "next/link";
import { notFound } from "next/navigation";
import { Chart } from "@/components/chart";
import { barOption, heatOption, lineOption } from "@/components/charts";
import { CreativeCard } from "@/components/creative";
import { Filters } from "@/components/filters";
import { KpiGrid, fmtMetric } from "@/components/kpi";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { METRIC_COLS } from "@/components/table-types";
import { db } from "@/lib/db";
import { money } from "@/lib/format";
import { pageContext, qs, type SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { adSetTable, adTable, breakdown, daily, keywordTable, placementTable, searchTermTable, targetingPerformance, totals } from "@/lib/queries";
import { mrow } from "@/lib/rows";
import { toDate } from "@/lib/dates";
import { DIM_LABEL } from "@/lib/labels";

export const metadata = { title: "Campaign" };


const TABS = [
  ["adsets", "Ad sets"], ["ads", "Ads & creatives"], ["breakdowns", "Breakdowns"], ["targeting", "Targeting & audiences"],
  ["leads", "Leads"], ["keywords", "Keywords"], ["search_terms", "Search terms"], ["placements", "Placements"],
] as const;

export default async function CampaignPage({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<SP> }) {
  const { id } = await params;
  const c = await pageContext(searchParams);
  const { scope, range, prev, t } = c;

  const campaign = await db.campaign.findUnique({ where: { id }, include: { account: { include: { company: true, connection: { select: { isDemo: true } } } } } });
  if (!campaign || !scope.accountIds.includes(campaign.accountId)) notFound();
  const isDemo = campaign.account.connection.isDemo;
  const meta = PLATFORM_META[campaign.platform];
  const isGoogle = campaign.platform === "GOOGLE";
  const where = { entityId: id };

  const [cur, before, series] = await Promise.all([
    totals(scope, range, where, "CAMPAIGN"), totals(scope, prev, where, "CAMPAIGN"), daily(scope, range, where, "CAMPAIGN"),
  ]);
  const leadCount = await db.lead.count({ where: { campaignId: id } });
  const kwCount = isGoogle ? await db.keyword.count({ where: { campaignId: id } }) : 0;
  const plCount = isGoogle ? await db.placement.count({ where: { campaignId: id } }) : 0;

  const tabs = TABS.filter(([k]) => {
    if (k === "leads") return leadCount > 0;
    if (k === "keywords" || k === "search_terms") return kwCount > 0;
    if (k === "placements") return plCount > 0;
    return true;
  });
  const tab = tabs.some(([k]) => k === c.one("tab")) ? c.one("tab") : "adsets";
  const keep = { days: c.one("days"), from: c.one("from"), to: c.one("to") };

  return (
    <>
      <PageHeader title={campaign.name} sub={`${meta.label} · ${campaign.account.name} · ${campaign.account.company?.name ?? ""} · ${campaign.status} · ${campaign.objective}${campaign.dailyBudget ? ` · ${money(Number(campaign.dailyBudget), campaign.account.currency)}/day` : ""}`}>
        <Filters t={t} companies={[]} range={range} days={c.one("days")} showPlatform={false} extra={{ tab }} />
        <Link href={`/reports?campaign=${id}`} className="btn-ghost no-print">Share report</Link>
      </PageHeader>

      <div className="space-y-4">
        <KpiGrid cur={cur} prev={before} currency={scope.currency} t={t} keys={["spend", "impressions", "reach", "clicks", "ctr", "cpc", "cpm", "frequency", "conversions", "leads", "cpl", "roas"]} />

        <div className="card">
          <Chart height={240} option={lineOption(series.map((d) => d.date), [
            { name: t.spendLabel, data: series.map((d) => d.spend), type: "bar", color: meta.color },
            { name: t.cpl, data: series.map((d) => d.cpl), axis: 1, color: "#c4973a" },
            { name: t.ctr, data: series.map((d) => d.ctr), axis: 1, color: "#16a34a" },
          ], [scope.currency, ""])} />
        </div>

        <nav className="no-print flex flex-wrap gap-1 border-b border-line">
          {tabs.map(([k, label]) => (
            <Link key={k} href={`/campaigns/${id}${qs(keep, { tab: k })}`}
              className={`-mb-px border-b-2 px-3 py-2 text-sm ${tab === k ? "border-accent font-medium text-ink" : "border-transparent text-muted hover:text-ink"}`}>
              {k === "adsets" ? meta.adSetLabel : label}
            </Link>
          ))}
        </nav>

        {tab === "adsets" && <AdSetsTab />}
        {tab === "ads" && <AdsTab />}
        {tab === "breakdowns" && <BreakdownsTab />}
        {tab === "targeting" && <TargetingTab />}
        {tab === "leads" && <LeadsTab />}
        {tab === "keywords" && <KeywordsTab />}
        {tab === "search_terms" && <SearchTermsTab />}
        {tab === "placements" && <PlacementsTab />}
      </div>
    </>
  );

  async function AdSetsTab() {
    const sets = await adSetTable(scope, range, id);
    return (
      <div className="card">
        <DataTable currency={scope.currency} initialSort="spend" csvName={`${meta.slug}-adsets`}
          cols={[{ key: "name", label: meta.adSetLabel, sub: "sub", wrap: true }, { key: "status", label: t.status }, { key: "goal", label: "Optimisation" }, ...METRIC_COLS]}
          rows={sets.map((s) => ({
            name: s.name, status: s.status, goal: s.optimizationGoal,
            sub: s.targeting.filter((x) => ["interest", "keyword", "job_title", "audience", "lookalike"].includes(x.type)).slice(0, 4).map((x) => (x.exclude ? `−${x.value}` : x.value)).join(" · "),
            ...mrow(s.m),
          }))} />
      </div>
    );
  }

  async function AdsTab() {
    const ads = await adTable(scope, range, id);
    const cur = scope.currency;
    return (
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {ads.map((a) => (
            <CreativeCard key={a.id} ad={{
              id: a.id, platform: a.platform, name: a.name, format: a.format, headline: a.headline, body: a.body, cta: a.cta,
              thumbUrl: a.thumbUrl, landingUrl: a.landingUrl, adSet: a.adSet.name,
              hasLivePreview: a.platform === "META" && !isDemo,
              stats: [
                { label: "Spend", value: fmtMetric("spend", a.m.spend, cur) }, { label: "CTR", value: fmtMetric("ctr", a.m.ctr, cur) },
                { label: "Leads", value: fmtMetric("leads", a.m.leads, cur) }, { label: "Clicks", value: fmtMetric("clicks", a.m.clicks, cur) },
                { label: "CPC", value: fmtMetric("cpc", a.m.cpc, cur) }, { label: "CPL", value: fmtMetric("cpl", a.m.cpl, cur) },
              ],
            }} />
          ))}
        </div>
        <div className="card">
          <DataTable currency={scope.currency} initialSort="spend" csvName={`${meta.slug}-ads`}
            cols={[{ key: "name", label: "Ad", sub: "sub", wrap: true }, { key: "format", label: "Format" }, { key: "status", label: t.status }, ...METRIC_COLS]}
            rows={ads.map((a) => ({ name: a.name, sub: a.adSet.name, format: a.format, status: a.status, ...mrow(a.m) }))} />
        </div>
      </div>
    );
  }

  async function BreakdownsTab() {
    const dims = (await db.statBreakdown.findMany({ where: { campaignId: id }, distinct: ["dimension"], select: { dimension: true } })).map((d) => d.dimension);
    const data = await Promise.all(dims.map(async (d) => ({ d, rows: await breakdown(scope, range, d, { campaignId: id }) })));
    const order = ["age", "gender", "age_gender", "country", "region", "device", "placement", "network", "hour"];
    data.sort((a, b) => (order.indexOf(a.d) + 99) % 99 - (order.indexOf(b.d) + 99) % 99);
    return (
      <div className="grid gap-4 lg:grid-cols-2">
        {data.map(({ d, rows }) => {
          if (d === "age_gender") {
            const ages = [...new Set(rows.map((r) => r.value.split("|")[0]))].sort();
            const genders = [...new Set(rows.map((r) => r.value.split("|")[1]))].sort();
            const cells = rows.map((r) => { const [a, g] = r.value.split("|"); return [ages.indexOf(a), genders.indexOf(g), r.m.leads || r.m.clicks] as [number, number, number]; });
            return (
              <div key={d} className="card lg:col-span-2">
                <h3 className="mb-2 text-sm font-semibold">{DIM_LABEL[d]} — {cur.leads ? "leads" : "clicks"}</h3>
                <Chart height={220} option={heatOption(ages, genders, cells, cur.leads ? "Leads" : "Clicks")} />
              </div>
            );
          }
          const sorted = d === "hour" || d === "age" ? [...rows].sort((a, b) => a.value.localeCompare(b.value)) : rows;
          return (
            <div key={d} className={`card ${d === "hour" || d === "placement" ? "lg:col-span-2" : ""}`}>
              <h3 className="mb-2 text-sm font-semibold">{DIM_LABEL[d] ?? d}</h3>
              <Chart height={200} option={barOption(sorted.map((r) => r.value), [
                { name: t.spendLabel, data: sorted.map((r) => r.m.spend), color: meta.color },
                { name: cur.leads ? t.leads : t.conversions, data: sorted.map((r) => (cur.leads ? r.m.leads : r.m.conversions)), color: "#c4973a" },
              ])} />
              <DataTable search={false} currency={scope.currency} initialSort="spend"
                cols={[{ key: "bucket", label: DIM_LABEL[d] ?? d }, { key: "spend", label: "Spend", fmt: "money" }, { key: "impressions", label: "Impr.", fmt: "compact" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "conversions", label: "Conv.", fmt: "num" }, { key: "cpa", label: "CPA", fmt: "money2" }, { key: "share", label: "Spend %", fmt: "pct" }]}
                rows={sorted.map((r) => ({ ...mrow(r.m), bucket: r.value, share: cur.spend ? (r.m.spend / cur.spend) * 100 : null }))} />
            </div>
          );
        })}
        {!data.length && <div className="card text-sm text-muted">{t.noData}</div>}
      </div>
    );
  }

  async function TargetingTab() {
    const [perf, sets] = await Promise.all([targetingPerformance(scope, range, { campaignId: id }), adSetTable(scope, range, id)]);
    const ORDER = ["interest", "behavior", "audience", "lookalike", "keyword", "job_title", "seniority", "industry", "company_size", "demographic", "age", "gender", "geo", "language", "placement", "device"];
    const types = [...new Set(perf.map((p) => p.type))].sort((a, b) => (ORDER.indexOf(a) + 99) % 99 - (ORDER.indexOf(b) + 99) % 99);
    return (
      <div className="space-y-4">
        <div className="card border-amber-200 bg-amber-50 text-sm text-amber-900">
          Platforms report results per {meta.adSetLabel.toLowerCase().replace(/s$/, "")}, not per interest. Each option below is credited with the full results of every {meta.adSetLabel.toLowerCase().replace(/s$/, "")} that uses it, so use it to <b>rank</b> options. For a true per-interest cost, run one interest per {meta.adSetLabel.toLowerCase().replace(/s$/, "")}.
        </div>
        {types.map((ty) => (
          <div key={ty} className="card">
            <h3 className="mb-2 text-sm font-semibold capitalize">{ty.replace(/_/g, " ")}</h3>
            <DataTable search={false} currency={scope.currency} initialSort="spend"
              cols={[{ key: "option", label: "Option", wrap: true }, { key: "adSets", label: meta.adSetLabel, fmt: "num" }, { key: "spend", label: "Spend", fmt: "money" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "cpc", label: "CPC", fmt: "money2" }, { key: "leads", label: "Leads", fmt: "num" }, { key: "cpl", label: "CPL", fmt: "money2" }, { key: "leadsCollected", label: "Lead forms", fmt: "num" }, { key: "roas", label: "ROAS", fmt: "ratio" }]}
              rows={perf.filter((p) => p.type === ty).map((p) => ({ ...mrow(p.m), option: p.value, adSets: p.adSets, leadsCollected: p.leadsCollected }))} />
          </div>
        ))}
        <div className="card">
          <h3 className="mb-3 text-sm font-semibold">Full targeting per {meta.adSetLabel.toLowerCase().replace(/s$/, "")}</h3>
          <div className="space-y-4">
            {sets.map((s) => (
              <div key={s.id} className="rounded-lg border border-line p-3">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <div className="font-medium">{s.name}</div>
                  <div className="text-xs text-muted tabular-nums">
                    {fmtMetric("spend", s.m.spend, scope.currency)} · {fmtMetric("leads", s.m.leads, scope.currency)} leads · CPL {fmtMetric("cpl", s.m.cpl, scope.currency)} · CTR {fmtMetric("ctr", s.m.ctr, scope.currency)}
                  </div>
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {s.targeting.map((x) => (
                    <span key={x.id} className={`pill ${x.exclude ? "bg-red-50 text-bad line-through" : "bg-bg text-ink"}`}>
                      <span className="me-1 text-muted">{x.type.replace(/_/g, " ")}:</span>{x.value}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    );
  }

  async function LeadsTab() {
    const leads = await db.lead.findMany({
      where: { campaignId: id, createdAt: { gte: toDate(range.from), lt: new Date(toDate(range.to).getTime() + 86_400_000) } },
      orderBy: { createdAt: "desc" }, take: 2000,
    });
    const sets = await db.adSet.findMany({ where: { campaignId: id }, include: { targeting: { where: { exclude: false, type: { in: ["interest", "behavior", "audience", "lookalike", "job_title", "keyword"] } } } } });
    const ads = await db.ad.findMany({ where: { campaignId: id }, select: { id: true, name: true } });
    const setMap = new Map(sets.map((s) => [s.id, s]));
    const adName = new Map(ads.map((a) => [a.id, a.name]));
    const fieldKeys = [...new Set(leads.flatMap((l) => Object.keys(l.fields as object)))].slice(0, 6);
    const bySet = new Map<string, number>();
    leads.forEach((l) => l.adSetId && bySet.set(l.adSetId, (bySet.get(l.adSetId) ?? 0) + 1));
    return (
      <div className="space-y-4">
        <div className="card">
          <h3 className="mb-2 text-sm font-semibold">Where leads came from</h3>
          <DataTable search={false} cols={[{ key: "set", label: meta.adSetLabel }, { key: "n", label: "Leads", fmt: "num" }, { key: "targeting", label: "Targeting of that " + meta.adSetLabel.toLowerCase().replace(/s$/, ""), wrap: true }]}
            initialSort="n" rows={[...bySet.entries()].map(([sid, n]) => ({ set: setMap.get(sid)?.name ?? "—", n, targeting: setMap.get(sid)?.targeting.map((x) => x.value).join(" · ") ?? "" }))} />
        </div>
        <div className="card">
          <h3 className="mb-2 text-sm font-semibold">Leads ({leads.length})</h3>
          <DataTable csvName="leads" limit={100}
            cols={[{ key: "createdAt", label: "Received" }, ...fieldKeys.map((k) => ({ key: k, label: k.replace(/_/g, " ") })), { key: "set", label: meta.adSetLabel }, { key: "ad", label: "Ad" }, { key: "interests", label: "Targeting", wrap: true }]}
            rows={leads.map((l) => {
              const s = l.adSetId ? setMap.get(l.adSetId) : undefined;
              return {
                createdAt: l.createdAt.toISOString().replace("T", " ").slice(0, 16), ...(l.fields as Record<string, string>),
                set: s?.name ?? "", ad: l.adId ? adName.get(l.adId) ?? "" : "", interests: s?.targeting.map((x) => x.value).join(" · ") ?? "",
              };
            })} />
        </div>
      </div>
    );
  }

  async function KeywordsTab() {
    const rows = await keywordTable(scope, range, id);
    return (
      <div className="card">
        <DataTable currency={scope.currency} initialSort="spend" csvName="keywords"
          cols={[{ key: "text", label: "Keyword" }, { key: "matchType", label: "Match" }, { key: "qs", label: "Quality score", fmt: "dec" }, { key: "spend", label: "Spend", fmt: "money" }, { key: "impressions", label: "Impr.", fmt: "compact" }, { key: "clicks", label: "Clicks", fmt: "num" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "cpc", label: "CPC", fmt: "money2" }, { key: "conversions", label: "Conv.", fmt: "num" }, { key: "cpa", label: "CPA", fmt: "money2" }, { key: "convRate", label: "Conv. rate", fmt: "pct" }]}
          rows={rows.map((r) => ({ text: r.text, matchType: r.matchType, qs: r.qs, ...mrow(r.m) }))} />
      </div>
    );
  }

  async function SearchTermsTab() {
    const rows = await searchTermTable(scope, range, id);
    return (
      <div className="card">
        <DataTable currency={scope.currency} initialSort="spend" csvName="search-terms"
          cols={[{ key: "term", label: "Search term", wrap: true }, { key: "keywordText", label: "Matched keyword" }, { key: "matchType", label: "Match" }, { key: "spend", label: "Spend", fmt: "money" }, { key: "clicks", label: "Clicks", fmt: "num" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "cpc", label: "CPC", fmt: "money2" }, { key: "conversions", label: "Conv.", fmt: "num" }, { key: "cpa", label: "CPA", fmt: "money2" }]}
          rows={rows.map((r) => ({ term: r.term, keywordText: r.keywordText, matchType: r.matchType, ...mrow(r.m) }))} />
      </div>
    );
  }

  async function PlacementsTab() {
    const rows = await placementTable(scope, range, id);
    return (
      <div className="card">
        <p className="mb-3 text-sm text-muted">Where Display, YouTube and app traffic was served, and what each placement delivered.</p>
        <DataTable currency={scope.currency} initialSort="spend" csvName="placements"
          cols={[{ key: "display", label: "Placement", sub: "target", wrap: true }, { key: "kind", label: "Type" }, { key: "spend", label: "Spend", fmt: "money" }, { key: "impressions", label: "Impr.", fmt: "compact" }, { key: "clicks", label: "Clicks", fmt: "num" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "conversions", label: "Conv.", fmt: "num" }, { key: "cpa", label: "CPA", fmt: "money2" }]}
          rows={rows.map((r) => ({ display: r.display || r.target, target: r.target, kind: r.kind.replace(/_/g, " "), ...mrow(r.m) }))} />
      </div>
    );
  }
}
