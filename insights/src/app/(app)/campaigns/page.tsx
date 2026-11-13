import { Filters } from "@/components/filters";
import { PageHeader } from "@/components/shell";
import { CurrencySwitch, currencyMode } from "@/components/currency-switch";
import { DataTable } from "@/components/table";
import { METRIC_COLS } from "@/components/table-types";
import { pageContext, qs, type SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { campaignTable, campaignTags } from "@/lib/queries";
import { canEdit, canFeature } from "@/lib/tenancy";
import { createTagAction, deleteTagAction } from "@/app/actions/tags";
import { mrow } from "@/lib/rows";

export const metadata = { title: "Campaigns" };

export default async function Campaigns({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams, "campaigns");
  const { scope, range, t } = c;
  const curMode = currencyMode(c.sp, c.client.campaignCurrency);
  const rows = await campaignTable(scope, range, { platform: c.platform, ownCurrency: curMode === "account" });
  const keep = qs({ days: c.one("days"), from: c.one("from"), to: c.one("to"), cur: c.one("cur") });
  const currencies = [...new Set(rows.map((x) => x.account.currency))];
  const tagsOf = await campaignTags(c.client.id, rows.map((x) => x.id));
  const mayTag = canEdit(c.viewer) && canFeature(c.viewer, "campaigns.tags");

  return (
    <>
      <PageHeader title={t.campaigns} sub={`${rows.length} campaigns · ${range.from} → ${range.to}`}>
        <Filters t={t} entity={c.entityPick} tags={c.tags} freshness={c.freshness} accounts={c.accounts} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} />
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
            name: x.name, href: `/campaigns/${x.id}${keep}`, sub: `${PLATFORM_META[x.platform].label} · ${x.account.name} · ${x.account.company?.name ?? ""}${tagsOf.get(x.id)?.length ? ` · 🏷 ${tagsOf.get(x.id)!.map((g) => g.name).join(", ")}` : ""}`,
            status: x.status, objective: x.objective.replace(/^OUTCOME_/, ""), currency: x.currency, ...mrow(x.m),
          }))} />
      </div>
      {canFeature(c.viewer, "campaigns.tags") && (
        <div className="card mt-4">
          <h2 className="mb-2 text-sm font-semibold">Tags</h2>
          <p className="mb-3 text-xs text-muted">Group campaigns your own way (a product, a season, a team). Tick a campaign&apos;s tags on its page; filter any report by tag from the bar above, and compare tags on Performance.</p>
          <div className="flex flex-wrap items-center gap-2">
            {c.tags.map((g) => (
              <form key={g.id} action={deleteTagAction} className="pill !px-3 !py-1 text-white" style={{ background: g.color }}>
                <input type="hidden" name="id" value={g.id} />
                <a href={qs(c.sp, { tag: g.id })} className="hover:underline">{g.name}</a>
                {mayTag && <button className="ms-2 opacity-80 hover:opacity-100" title="Delete tag" aria-label={`Delete tag ${g.name}`}>×</button>}
              </form>
            ))}
            {!c.tags.length && <span className="text-sm text-muted">No tags yet.</span>}
          </div>
          {mayTag && (
            <form action={createTagAction} className="mt-3 flex flex-wrap items-end gap-2">
              <div><label className="label">New tag</label><input className="input !w-48" name="name" maxLength={40} required placeholder="e.g. Ramadan" /></div>
              <div><label className="label">Colour</label><input className="h-9 w-12 rounded border border-line" type="color" name="color" defaultValue="#c4973a" /></div>
              <button className="btn">Add tag</button>
            </form>
          )}
        </div>
      )}
    </>
  );
}
