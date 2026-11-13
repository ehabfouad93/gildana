import Link from "next/link";
import { AiAdviceButton } from "@/components/ai-advice-button";
import { BreakdownSync } from "@/components/breakdown-sync";
import { Filters } from "@/components/filters";
import { NotMeasuredList } from "@/components/not-measured";
import { PageHeader } from "@/components/shell";
import { SplitTestsCard } from "@/components/split-tests-card";
import { SuggestionList } from "@/components/suggestion-list";
import { DataTable } from "@/components/table";
import { SOURCE_LABEL } from "@/components/table-types";
import { adviceScope } from "@/lib/advice-scope";
import { claudeEnabled } from "@/lib/claude";
import { db } from "@/lib/db";
import { money } from "@/lib/format";
import { advise, type AiAdvice } from "@/lib/interest-advice";
import { openaiEnabled } from "@/lib/openai";
import { pageContext, qs, type SP } from "@/lib/page";
import { audienceInsights, interestHistory, splitTests } from "@/lib/queries";
import { mrow } from "@/lib/rows";
import { canEdit, canFeature } from "@/lib/tenancy";

export const metadata = { title: "Audiences" };

const GROUPS: Record<string, { label: string; types: string[] }> = {
  interests: { label: "Interests & behaviours", types: ["interest", "behavior", "demographic"] },
  audiences: { label: "Custom & lookalike audiences", types: ["audience", "lookalike"] },
  b2b: { label: "Job titles & industries (LinkedIn)", types: ["job_title", "seniority", "industry", "company_size"] },
  keywords: { label: "Keywords (Google)", types: ["keyword"] },
  demo: { label: "Age, gender & location", types: ["age", "gender", "geo", "language"] },
  placement: { label: "Placements & devices", types: ["placement", "device"] },
};

const VIEWS = { results: "Exact results", suggest: "Suggestions" } as const;

/** Every targeting option the client uses, with exact results, and what to do next. */
export default async function Audiences({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams, "audiences");
  const { t, range } = c;
  // Views switched off for this team member are hidden and can't be opened by URL.
  const allowed = (Object.keys(VIEWS) as (keyof typeof VIEWS)[]).filter((k) => canFeature(c.viewer, k === "results" ? "audiences.results" : "audiences.suggestions"));
  const view = allowed.find((k) => k === c.one("v")) ?? allowed[0];
  if (!view) return <><PageHeader title={t.audiences} /><div className="card text-sm text-muted">The audience views are switched off for your account. Ask a workspace owner to turn them on in Team.</div></>;

  return (
    <>
      <PageHeader title={t.audiences} sub={view === "results" ? "Exact results of every targeting choice, across every campaign" : "What to scale, stop and test next — read from all your Meta history"}>
        {view === "results" && <Filters t={t} entity={c.entityPick} tags={c.tags} freshness={c.freshness} accounts={c.accounts} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} extra={{ g: c.one("g") }} />}
      </PageHeader>
      <div className="mb-4 flex gap-4 border-b border-line">
        {allowed.map((k) => [k, VIEWS[k]] as const).map(([k, label]) => (
          <Link key={k} href={qs(c.sp, { v: k === "results" ? undefined : k })} className={`-mb-px border-b-2 px-1 pb-2 text-sm ${k === view ? "border-accent font-semibold" : "border-transparent text-muted hover:text-ink"}`}>{label}</Link>
        ))}
      </div>
      {view === "results" ? <Results c={c} /> : <Suggestions c={c} />}
    </>
  );
}

type Ctx = Awaited<ReturnType<typeof pageContext>>;

async function Results({ c }: { c: Ctx }) {
  const { scope, range } = c;
  const g = GROUPS[c.one("g")] ? c.one("g") : "interests";
  const { rows, notMeasured } = await audienceInsights(scope, range, { types: GROUPS[g].types });
  return (
    <>
      {canFeature(c.viewer, "general.sync_breakdowns") && <BreakdownSync accountIds={scope.accountIds} module="audiences" path="/audiences" />}
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
      <div className="mt-4"><NotMeasuredList items={notMeasured} canTest={canEdit(c.viewer) && canFeature(c.viewer, "audiences.create_test")} /></div>
    </>
  );
}

/** Three opinions on the same all-time history: rules, Claude, OpenAI. Each can become a split-test campaign. */
async function Suggestions({ c }: { c: Ctx }) {
  const { accountIds, campaign, scopeKey } = await adviceScope(c.viewer, c.one("camp").split(",")[0] || undefined);
  const currency = c.client.reportingCurrency;
  const fmt = (n: number) => money(n, currency);
  const [history, cached, tests] = await Promise.all([
    interestHistory({ accountIds, currency }),
    db.audienceCache.findMany({ where: { clientId: c.client.id, scopeKey } }),
    splitTests({ accountIds, currency }, { campaignId: campaign?.id }, fmt),
  ]);
  const fromHistory = advise(history, { campaignId: campaign?.id, fmt }).filter((s) => s.action !== "keep");
  const ai = (kind: "claude" | "openai") => cached.find((x) => x.kind === kind);
  const canCreate = canEdit(c.viewer) && canFeature(c.viewer, "audiences.create_test");
  const showAi = canFeature(c.viewer, "audiences.ai");
  const measured = history.sets.filter((s) => s.interests.length === 1 && s.spend > 0).length;
  const mixed = history.sets.filter((s) => s.interests.length > 1 && s.spend > 0).length;

  const aiCard = (kind: "claude" | "openai", name: string, on: boolean) => {
    const hit = ai(kind);
    const advice = hit?.content as unknown as AiAdvice | undefined;
    return (
      <section className="card">
        <div className="mb-2 flex flex-wrap items-center gap-3">
          <h2 className="font-semibold">{name}&apos;s suggestions</h2>
          <span className="pill bg-violet-100 text-violet-800">AI opinion</span>
          {hit && <span className="text-xs text-muted">written {hit.createdAt.toISOString().slice(0, 16).replace("T", " ")} UTC</span>}
          <div className="ms-auto">{on && <AiAdviceButton kind={kind} campaignId={campaign?.id} has={!!hit} />}</div>
        </div>
        {!on ? (
          <p className="text-sm text-muted">{name} isn&apos;t connected. {c.viewer.isAdmin ? <>Add its API key in <a className="text-accent underline" href="/admin/platforms">Admin → Platforms</a>.</> : "Ask Gildana to connect it."}</p>
        ) : !advice ? (
          <p className="text-sm text-muted">Press “Ask {name}” to have it read the same history and write its own suggestions. Your accounts&apos; performance figures are sent to {name} for this.</p>
        ) : (
          <>
            {advice.summary && <p className="mb-3 whitespace-pre-line text-sm">{advice.summary}</p>}
            <SuggestionList items={advice.suggestions} currency={currency} canCreate={canCreate} campaignId={campaign?.id} empty={`${name} had no suggestions.`} />
          </>
        )}
      </section>
    );
  };

  return (
    <div className="space-y-4">
      <div className="card text-sm text-muted">
        {campaign ? <>For the campaign <b className="text-ink">{campaign.name}</b> · <Link className="text-accent underline" href={qs(c.sp, { camp: undefined })}>all campaigns</Link>. </> : null}
        Read from <b className="text-ink">all</b> your Meta history, whatever the date filter: {measured} ad set{measured === 1 ? "" : "s"} that ran one interest alone (exact results) and {mixed} that mixed several.
        Tick suggestions and create a campaign with one ad set per interest to get exact results for them.
      </div>
      <section className="card">
        <div className="mb-2 flex flex-wrap items-center gap-3">
          <h2 className="font-semibold">From your history</h2>
          <span className="pill bg-green-100 text-good">Your own results</span>
        </div>
        <SuggestionList items={fromHistory} currency={currency} canCreate={canCreate} campaignId={campaign?.id}
          empty="No Meta ad sets with interests and spend yet — connect a Meta account or wait for the first sync." />
      </section>
      {showAi && aiCard("claude", "Claude", claudeEnabled())}
      {showAi && aiCard("openai", "OpenAI", openaiEnabled())}
      <SplitTestsCard tests={tests} currency={currency} canPause={canEdit(c.viewer) && canFeature(c.viewer, "audiences.pause_losers")} />
    </div>
  );
}
