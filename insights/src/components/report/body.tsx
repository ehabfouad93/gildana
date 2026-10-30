import type { Platform } from "@prisma/client";
import { Chart } from "@/components/chart";
import { barOption, donutOption, heatOption, lineOption } from "@/components/charts";
import { KpiGrid } from "@/components/kpi";
import { DataTable } from "@/components/table";
import { METRIC_COLS } from "@/components/table-types";
import { db } from "@/lib/db";
import { previousRange, type Range } from "@/lib/dates";
import type { Dict } from "@/lib/i18n";
import { DIM_LABEL } from "@/lib/labels";
import { PLATFORM_META } from "@/lib/platforms";
import { adSetTable, adTable, breakdown, campaignTable, daily, splits, totals, type Scope } from "@/lib/queries";
import { mrow } from "@/lib/rows";
import { scopedAccountIds } from "@/lib/tenancy";

export type ReportSpec = { clientId: string; companyId?: string | null; platform?: Platform | null; campaignId?: string | null; range: Range; title: string };

/**
 * The client report. Rendered both inside the app (print / save as PDF) and
 * on public share links, so it takes its scope as data and never reads the
 * session.
 */
export async function ReportBody({ spec, t, linkCampaigns = false }: { spec: ReportSpec; t: Dict; linkCampaigns?: boolean }) {
  const client = await db.client.findUniqueOrThrow({ where: { id: spec.clientId } });
  const accountIds = await scopedAccountIds(spec.clientId, { companyId: spec.companyId ?? undefined, platform: spec.platform ?? undefined });
  const scope: Scope = { accountIds, currency: client.reportingCurrency };
  const { range } = spec;
  const prev = previousRange(range);

  const campaign = spec.campaignId ? await db.campaign.findFirst({ where: { id: spec.campaignId, accountId: { in: accountIds } } }) : null;
  const where = campaign ? { entityId: campaign.id } : {};
  const level = campaign ? ("CAMPAIGN" as const) : ("ACCOUNT" as const);
  const bdWhere = campaign ? { campaignId: campaign.id } : {};

  const [cur, before, series, sp, camps, age, gender, country, ageGender] = await Promise.all([
    totals(scope, range, where, level), totals(scope, prev, where, level), daily(scope, range, where, level),
    campaign ? null : splits(scope, range), campaign ? null : campaignTable(scope, range),
    breakdown(scope, range, "age", bdWhere), breakdown(scope, range, "gender", bdWhere), breakdown(scope, range, "country", bdWhere),
    breakdown(scope, range, "age_gender", bdWhere),
  ]);
  const company = spec.companyId ? await db.company.findUnique({ where: { id: spec.companyId } }) : null;
  const lead = cur.leads > 0;

  return (
    <div className="space-y-4">
      <div className="card flex flex-wrap items-end justify-between gap-2 !bg-side text-white">
        <div>
          <div className="text-xs uppercase tracking-[0.2em] text-accent">{client.name}{company ? ` · ${company.name}` : ""}</div>
          <h1 className="text-2xl font-semibold">{spec.title}</h1>
          <div className="text-sm text-side-ink">
            {range.from} → {range.to} · {spec.platform ? PLATFORM_META[spec.platform].label : "All platforms"}{campaign ? ` · ${campaign.name}` : ""} · {scope.currency}
          </div>
        </div>
        <div className="text-xs text-side-ink">Generated {new Date().toISOString().slice(0, 16).replace("T", " ")} UTC</div>
      </div>

      <KpiGrid cur={cur} prev={before} currency={scope.currency} t={t} keys={["spend", "impressions", "reach", "clicks", "ctr", "cpc", "cpm", "conversions", lead ? "leads" : "value", lead ? "cpl" : "cpa", "roas", "frequency"]} />

      <div className="grid gap-4 lg:grid-cols-3">
        <div className={`card ${sp ? "lg:col-span-2" : "lg:col-span-3"}`}>
          <h2 className="mb-2 text-sm font-semibold">{t.trend}</h2>
          <Chart height={260} option={lineOption(series.map((d) => d.date), [
            { name: t.spendLabel, data: series.map((d) => d.spend), type: "bar", color: "#c4973a" },
            { name: lead ? t.leads : t.conversions, data: series.map((d) => (lead ? d.leads : d.conversions)), axis: 1, color: "#2563eb" },
          ], [scope.currency, ""])} />
        </div>
        {sp && (
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">{t.spend} · {t.byPlatform}</h2>
            <Chart height={260} option={donutOption(sp.byPlatform.map((p) => ({ name: PLATFORM_META[p.platform].label, value: p.m.spend, color: PLATFORM_META[p.platform].color })))} />
          </div>
        )}
      </div>

      {sp && sp.byPlatform.length > 1 && (
        <div className="card">
          <h2 className="mb-2 text-sm font-semibold">{t.byPlatform}</h2>
          <DataTable search={false} currency={scope.currency} initialSort="spend" cols={[{ key: "name", label: t.platform }, ...METRIC_COLS]}
            rows={sp.byPlatform.map((p) => ({ ...mrow(p.m), name: PLATFORM_META[p.platform].label }))} />
        </div>
      )}
      {sp && !spec.companyId && sp.byCompany.length > 1 && (
        <div className="card">
          <h2 className="mb-2 text-sm font-semibold">{t.byCompany}</h2>
          <DataTable search={false} currency={scope.currency} initialSort="spend" cols={[{ key: "name", label: t.company }, ...METRIC_COLS]}
            rows={sp.byCompany.map((p) => ({ ...mrow(p.m), name: p.name }))} />
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        {[["age", age], ["gender", gender], ["country", country]].map(([d, rows]) => {
          const r = (rows as typeof age);
          const sorted = d === "age" ? [...r].sort((a, b) => a.value.localeCompare(b.value)) : r.slice(0, 8);
          return (
            <div key={d as string} className="card">
              <h2 className="mb-2 text-sm font-semibold">{DIM_LABEL[d as string]}</h2>
              {sorted.length ? <Chart height={220} option={barOption(sorted.map((x) => x.value), [
                { name: t.spendLabel, data: sorted.map((x) => x.m.spend), color: "#2563eb" },
                { name: lead ? t.leads : t.conversions, data: sorted.map((x) => (lead ? x.m.leads : x.m.conversions)), color: "#c4973a" },
              ])} /> : <p className="text-sm text-muted">{t.noData}</p>}
            </div>
          );
        })}
      </div>

      {ageGender.length > 0 && (() => {
        const ages = [...new Set(ageGender.map((r) => r.value.split("|")[0]))].sort();
        const gs = [...new Set(ageGender.map((r) => r.value.split("|")[1]))].sort();
        return (
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">{DIM_LABEL.age_gender} — {lead ? t.leads : t.clicks}</h2>
            <Chart height={200} option={heatOption(ages, gs, ageGender.map((r) => { const [a, g] = r.value.split("|"); return [ages.indexOf(a), gs.indexOf(g), lead ? r.m.leads : r.m.clicks]; }), "")} />
          </div>
        );
      })()}

      {camps && (
        <div className="card">
          <h2 className="mb-2 text-sm font-semibold">{t.campaigns}</h2>
          <DataTable currency={scope.currency} initialSort="spend" csvName="campaigns" limit={50}
            cols={[{ key: "name", label: t.campaign, sub: "sub", wrap: true, ...(linkCampaigns ? { href: "href" } : {}) }, ...METRIC_COLS]}
            rows={camps.filter((x) => x.m.impressions > 0).map((x) => ({ ...mrow(x.m), name: x.name, href: `/campaigns/${x.id}`, sub: `${PLATFORM_META[x.platform].label} · ${x.account.company?.name ?? ""}` }))} />
        </div>
      )}
      {campaign && <CampaignDetail scope={scope} range={range} campaignId={campaign.id} platform={campaign.platform} t={t} />}
    </div>
  );
}

async function CampaignDetail({ scope, range, campaignId, platform, t }: { scope: Scope; range: Range; campaignId: string; platform: Platform; t: Dict }) {
  const [sets, ads] = await Promise.all([adSetTable(scope, range, campaignId), adTable(scope, range, campaignId)]);
  return (
    <>
      <div className="card">
        <h2 className="mb-2 text-sm font-semibold">{PLATFORM_META[platform].adSetLabel}</h2>
        <DataTable search={false} currency={scope.currency} initialSort="spend"
          cols={[{ key: "name", label: PLATFORM_META[platform].adSetLabel, sub: "sub", wrap: true }, ...METRIC_COLS]}
          rows={sets.map((s) => ({ ...mrow(s.m), name: s.name, sub: s.targeting.filter((x) => !x.exclude && ["interest", "keyword", "job_title", "audience", "behavior"].includes(x.type)).map((x) => x.value).join(" · ") }))} />
      </div>
      <div className="card">
        <h2 className="mb-2 text-sm font-semibold">Ads</h2>
        <DataTable search={false} currency={scope.currency} initialSort="spend"
          cols={[{ key: "name", label: "Ad", sub: "sub", wrap: true }, { key: "headline", label: "Headline", wrap: true }, ...METRIC_COLS]}
          rows={ads.map((a) => ({ ...mrow(a.m), name: a.name, sub: a.adSet.name, headline: a.headline }))} />
      </div>
    </>
  );
}
