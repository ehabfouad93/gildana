import { Filters } from "@/components/filters";
import { PageHeader } from "@/components/shell";
import { CurrencySwitch, currencyMode } from "@/components/currency-switch";
import { DataTable } from "@/components/table";
import { METRIC_COLS } from "@/components/table-types";
import { pageContext, qs, type SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { campaignTable } from "@/lib/queries";
import { mrow } from "@/lib/rows";

export const metadata = { title: "Campaigns" };

export default async function Campaigns({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams, "campaigns");
  const { scope, range, t } = c;
  const curMode = currencyMode(c.sp, c.client.campaignCurrency);
  const rows = await campaignTable(scope, range, { platform: c.platform, ownCurrency: curMode === "account" });
  const keep = qs({ days: c.one("days"), from: c.one("from"), to: c.one("to"), cur: c.one("cur") });
  const currencies = [...new Set(rows.map((x) => x.account.currency))];

  return (
    <>
      <PageHeader title={t.campaigns} sub={`${rows.length} campaigns · ${range.from} → ${range.to}`}>
        <Filters t={t} freshness={c.freshness} accounts={c.accounts} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} />
      </PageHeader>
      {currencies.some((x) => x !== scope.currency) && (
        <div className="mb-3 flex flex-wrap items-center gap-3">
          <CurrencySwitch sp={c.sp} mode={curMode} account={currencies.length === 1 ? currencies[0] : currencies.join(" / ")} reporting={scope.currency} />
          {curMode === "account" && currencies.length > 1 && <span className="text-xs text-amber-700">Accounts use different currencies, so spend can't be compared row to row — switch to {scope.currency} to compare.</span>}
        </div>
      )}
      <div className="card">
        <DataTable currency={scope.currency} currencyKey="currency" initialSort="spend" csvName="campaigns"
          cols={[{ key: "name", label: t.campaign, href: "href", sub: "sub", wrap: true }, { key: "status", label: t.status }, { key: "objective", label: t.objective }, ...METRIC_COLS]}
          rows={rows.map((x) => ({
            name: x.name, href: `/campaigns/${x.id}${keep}`, sub: `${PLATFORM_META[x.platform].label} · ${x.account.name} · ${x.account.company?.name ?? ""}`,
            status: x.status, objective: x.objective.replace(/^OUTCOME_/, ""), currency: x.currency, ...mrow(x.m),
          }))} />
      </div>
    </>
  );
}
