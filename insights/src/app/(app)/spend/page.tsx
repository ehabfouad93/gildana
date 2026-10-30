import type { Platform } from "@prisma/client";
import { Chart } from "@/components/chart";
import { barOption, lineOption } from "@/components/charts";
import { Filters } from "@/components/filters";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { db } from "@/lib/db";
import { money } from "@/lib/format";
import { pageContext, type SP } from "@/lib/page";
import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import { campaignTable, daily, splits, totals } from "@/lib/queries";

export const metadata = { title: "Spend" };

export default async function SpendPage({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams);
  const { scope, range, t } = c;

  // Daily spend per platform, stacked.
  const perPlatform = await Promise.all(
    PLATFORMS.map(async (p) => ({ p, s: await daily({ ...scope, accountIds: scope.accountIds }, range, { platform: p }) })),
  );
  const [all, sp, camps, cur] = await Promise.all([daily(scope, range), splits(scope, range), campaignTable(scope, range), totals(scope, range)]);
  const dates = all.map((d) => d.date);

  // Budget pacing for the current month, where planned budgets exist.
  const month = new Date(Date.UTC(new Date().getUTCFullYear(), new Date().getUTCMonth(), 1));
  const budgets = await db.budget.findMany({ where: { month, company: { clientId: c.client.id } }, include: { company: { select: { name: true } } } });

  const nDays = all.length || 1;
  return (
    <>
      <PageHeader title={t.spend} sub={`${range.from} → ${range.to} · ${money(cur.spend, scope.currency)} · avg ${money(cur.spend / nDays, scope.currency)}/day`}>
        <Filters t={t} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} />
      </PageHeader>
      <div className="space-y-4">
        <div className="card">
          <h2 className="mb-2 text-sm font-semibold">Daily spend by platform</h2>
          <Chart height={300} option={{
            ...lineOption(dates, []),
            series: perPlatform.filter((x) => x.s.length).map(({ p, s }) => {
              const m = new Map(s.map((d) => [d.date, d.spend]));
              return { name: PLATFORM_META[p].label, type: "bar", stack: "s", barMaxWidth: 18, itemStyle: { color: PLATFORM_META[p].color }, data: dates.map((d) => Math.round((m.get(d) ?? 0) * 100) / 100) };
            }),
          }} />
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">{t.spend} · {t.byCompany}</h2>
            <Chart height={240} option={barOption(sp.byCompany.map((x) => x.name), [{ name: t.spendLabel, data: sp.byCompany.map((x) => x.m.spend), color: "#c4973a" }], true)} />
          </div>
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">{t.spend} · {t.byPlatform}</h2>
            <Chart height={240} option={barOption(sp.byPlatform.map((x) => PLATFORM_META[x.platform as Platform].label), [{ name: t.spendLabel, data: sp.byPlatform.map((x) => x.m.spend), color: "#2563eb" }], true)} />
          </div>
        </div>

        {budgets.length > 0 && (
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">Budget pacing — this month</h2>
            <DataTable search={false} currency={scope.currency} cols={[{ key: "company", label: t.company }, { key: "platform", label: t.platform }, { key: "budget", label: "Budget", fmt: "money" }]}
              rows={budgets.map((b) => ({ company: b.company.name, platform: b.platform ? PLATFORM_META[b.platform].label : "All", budget: Number(b.amount) }))} />
          </div>
        )}

        <div className="card">
          <h2 className="mb-2 text-sm font-semibold">{t.byAccount}</h2>
          <DataTable currency={scope.currency} initialSort="spend" csvName="spend-by-account"
            cols={[{ key: "name", label: t.account, sub: "sub" }, { key: "company", label: t.company }, { key: "spend", label: t.spendLabel, fmt: "money" }, { key: "share", label: "Share", fmt: "pct" }, { key: "perDay", label: "Per day", fmt: "money2" }, { key: "cur", label: "Billing ccy" }]}
            rows={sp.byAccount.map((a) => ({ name: a.name, sub: PLATFORM_META[a.platform].label, company: a.company, spend: a.m.spend, share: cur.spend ? (a.m.spend / cur.spend) * 100 : null, perDay: a.m.spend / nDays, cur: a.currency }))} />
        </div>

        <div className="card">
          <h2 className="mb-2 text-sm font-semibold">{t.campaigns}</h2>
          <DataTable currency={scope.currency} initialSort="spend" csvName="spend-by-campaign"
            cols={[{ key: "name", label: t.campaign, href: "href", sub: "sub", wrap: true }, { key: "status", label: t.status }, { key: "spend", label: t.spendLabel, fmt: "money" }, { key: "share", label: "Share", fmt: "pct" }, { key: "budget", label: "Daily budget", fmt: "money" }]}
            rows={camps.map((x) => ({ name: x.name, sub: `${PLATFORM_META[x.platform].label} · ${x.account.name}`, href: `/campaigns/${x.id}`, status: x.status, spend: x.m.spend, share: cur.spend ? (x.m.spend / cur.spend) * 100 : null, budget: null }))} />
        </div>
      </div>
    </>
  );
}
