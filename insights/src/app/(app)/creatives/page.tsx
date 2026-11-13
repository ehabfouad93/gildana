import Link from "next/link";
import { Filters } from "@/components/filters";
import { PageHeader } from "@/components/shell";
import { DataTable } from "@/components/table";
import { byCreative, byElement, fatigue, type Fatigue } from "@/lib/creative-insights";
import { money } from "@/lib/format";
import { pageContext, qs, type SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { creativeData } from "@/lib/queries";

export const metadata = { title: "Creatives" };

const VIEWS = { creatives: "Creatives", elements: "Headlines, texts & buttons", fatigue: "Fatigue" } as const;
const TONE: Record<Fatigue["level"], string> = { tired: "bg-red-100 text-bad", watch: "bg-amber-100 text-amber-800", fresh: "bg-green-100 text-good", new: "bg-slate-100 text-slate-600" };
const LABEL: Record<Fatigue["level"], string> = { tired: "Tired", watch: "Watch", fresh: "Fresh", new: "New" };
const ELEMENTS = [["headline", "Headlines"], ["body", "Texts"], ["cta", "Buttons"], ["format", "Formats"]] as const;

/**
 * Creatives across every campaign: the same creative counted together
 * wherever it runs, which elements work, and which ads are tiring.
 */
export default async function Creatives({ searchParams }: { searchParams: Promise<SP> }) {
  const c = await pageContext(searchParams, "creatives");
  const { scope, range, t } = c;
  const view = (c.one("v") in VIEWS ? c.one("v") : "creatives") as keyof typeof VIEWS;
  const ads = await creativeData(scope, range, { platform: c.platform });
  const cur = scope.currency;
  const m = (n: number | null) => (n == null ? "—" : money(n, cur));

  return (
    <>
      <PageHeader title="Creatives" sub={`${ads.length} ads with delivery · ${range.from} → ${range.to}`}>
        <Filters t={t} entity={c.entityPick} tags={c.tags} freshness={c.freshness} accounts={c.accounts} companies={c.companies} companyId={c.companyId} platform={c.platform} range={range} days={c.one("days")} />
      </PageHeader>
      <div className="mb-4 flex gap-4 border-b border-line">
        {Object.entries(VIEWS).map(([k, label]) => (
          <Link key={k} href={qs(c.sp, { v: k === "creatives" ? undefined : k })} className={`-mb-px border-b-2 px-1 pb-2 text-sm ${k === view ? "border-accent font-semibold" : "border-transparent text-muted hover:text-ink"}`}>{label}</Link>
        ))}
      </div>

      {view === "creatives" && (() => {
        const rows = byCreative(ads);
        return (
          <>
            <p className="mb-3 text-xs text-muted">A creative used in several ad sets or campaigns is one card, with its results added up.</p>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              {rows.slice(0, 120).map((r) => (
                <div key={r.key} className="card flex flex-col !p-0">
                  <div className="relative flex h-40 items-center justify-center overflow-hidden rounded-t-xl bg-bg">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    {r.sample.thumbUrl ? <img src={r.sample.thumbUrl} alt="" className="h-full w-full object-cover" loading="lazy" /> : <span className="px-3 text-center text-sm text-muted">{r.sample.headline || r.sample.name}</span>}
                    <span className={`pill absolute end-2 top-2 ${TONE[r.fatigue.level]}`} title={r.fatigue.note}>{LABEL[r.fatigue.level]}</span>
                    <span className="pill absolute start-2 top-2 text-white" style={{ background: PLATFORM_META[r.sample.platform as keyof typeof PLATFORM_META].color }}>{PLATFORM_META[r.sample.platform as keyof typeof PLATFORM_META].label}</span>
                  </div>
                  <div className="space-y-2 p-3">
                    <div className="line-clamp-2 text-sm font-medium" title={r.sample.headline}>{r.sample.headline || r.sample.name}</div>
                    <div className="line-clamp-2 text-xs text-muted" title={r.sample.body}>{r.sample.body}</div>
                    <div className="grid grid-cols-4 gap-1 text-xs">
                      <div><div className="text-muted">Spend</div><div className="font-semibold tabular-nums">{money(r.spend, cur, 0)}</div></div>
                      <div><div className="text-muted">Results</div><div className="font-semibold tabular-nums">{Math.round(r.results)}</div></div>
                      <div><div className="text-muted">Cost each</div><div className="font-semibold tabular-nums">{m(r.cpr)}</div></div>
                      <div><div className="text-muted">CTR</div><div className="font-semibold tabular-nums">{r.ctr != null ? `${r.ctr.toFixed(2)}%` : "—"}</div></div>
                    </div>
                    <div className="text-xs text-muted">
                      {r.ads} ad{r.ads > 1 ? "s" : ""} · {r.adSets} ad set{r.adSets > 1 ? "s" : ""} · {r.campaigns} campaign{r.campaigns > 1 ? "s" : ""} · {r.active} active
                      {" · "}<Link className="text-accent hover:underline" href={`/campaigns/${r.sample.campaignId}?tab=ads`}>open</Link>
                    </div>
                  </div>
                </div>
              ))}
            </div>
            {!rows.length && <div className="card text-sm text-muted">No ads with delivery in this view.</div>}
            {rows.length > 120 && <p className="mt-3 text-xs text-muted">Showing the 120 creatives with the most spend of {rows.length}. Narrow the filters to see the rest.</p>}
          </>
        );
      })()}

      {view === "elements" && (
        <div className="grid gap-4 lg:grid-cols-2">
          {ELEMENTS.map(([field, title]) => (
            <div key={field} className="card">
              <h2 className="mb-2 text-sm font-semibold">{title}</h2>
              <DataTable search={field === "headline" || field === "body"} currency={cur} initialSort="spend" csvName={`creative-${field}`} limit={25}
                cols={[{ key: "value", label: title.replace(/s$/, ""), wrap: true }, { key: "ads", label: "Ads", fmt: "num" }, { key: "spend", label: "Spend", fmt: "money" }, { key: "results", label: "Results", fmt: "num" }, { key: "cpr", label: "Cost each", fmt: "money2" }, { key: "ctr", label: "CTR", fmt: "pct" }]}
                rows={byElement(ads, field).map((x) => ({ value: x.value.slice(0, 220), ads: x.ads, spend: x.spend, results: x.results, cpr: x.cpr, ctr: x.ctr }))} />
            </div>
          ))}
        </div>
      )}

      {view === "fatigue" && (() => {
        const rows = ads.filter((a) => a.status === "ACTIVE").map((a) => ({ a, f: fatigue(a.days) })).filter((x) => x.f.level === "tired" || x.f.level === "watch")
          .sort((x, y) => (x.f.level === y.f.level ? y.a.spend - x.a.spend : x.f.level === "tired" ? -1 : 1));
        return (
          <div className="card">
            <p className="mb-3 text-xs text-muted">Active ads whose click rate is falling while their cost per result or frequency rises — each ad&apos;s last 7 delivery days against its own first 7. Tired ads are also suggested for a pause in To-do.</p>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th>Ad</th><th>State</th><th className="num">Click rate</th><th className="num">Cost per result</th><th className="num">Frequency</th><th className="num">Spend</th><th /></tr></thead>
                <tbody>
                  {rows.map(({ a, f }) => {
                    const ch = (x: number | null, bad: (v: number) => boolean) => (x == null ? <span className="text-muted">—</span> : <span className={bad(x) ? "text-bad" : ""}>{x > 0 ? "+" : ""}{Math.round(x * 100)}%</span>);
                    return (
                      <tr key={a.id}>
                        <td className="!whitespace-normal"><div className="font-medium">{a.name}</div><div className="text-xs text-muted">{a.campaignName} › {a.adSetName}</div></td>
                        <td><span className={`pill ${TONE[f.level]}`}>{LABEL[f.level]}</span></td>
                        <td className="num">{ch(f.ctrChange, (v) => v <= -0.15)}</td>
                        <td className="num">{ch(f.costChange, (v) => v >= 0.2)}</td>
                        <td className="num">{ch(f.freqChange, (v) => v >= 0.3)}</td>
                        <td className="num">{money(a.spend, cur, 0)}</td>
                        <td><Link className="text-xs text-accent hover:underline" href={`/campaigns/${a.campaignId}?tab=ads`}>Open campaign →</Link></td>
                      </tr>
                    );
                  })}
                  {!rows.length && <tr><td colSpan={7} className="text-muted">No active ad looks tired in this view.</td></tr>}
                </tbody>
              </table>
            </div>
          </div>
        );
      })()}
    </>
  );
}
