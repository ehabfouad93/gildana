import Link from "next/link";
import { notFound } from "next/navigation";
import { disconnectGscAction, startCrawlAction } from "@/app/actions/sites";
import { Chart } from "@/components/chart";
import { lineOption } from "@/components/charts";
import { SiteNav } from "@/components/site-nav";
import { DataTable } from "@/components/table";
import { db } from "@/lib/db";
import { lastNDays } from "@/lib/dates";
import { gscConfigured, gscQuery } from "@/lib/gsc";
import { pageContext, qs, type SP } from "@/lib/page";
import { ISSUE_LABEL } from "@/lib/seo";
import { canEdit } from "@/lib/tenancy";
import { siteForClient } from "@/lib/web";

export const metadata = { title: "SEO" };
export const dynamic = "force-dynamic";

const TABS = { issues: "Issues", pages: "Pages", search: "Search Console" } as const;
const SEV = { error: "bg-red-100 text-bad", warning: "bg-amber-100 text-amber-800", notice: "bg-slate-100 text-slate-600" } as const;

export default async function Seo({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<SP> }) {
  const { id } = await params;
  const c = await pageContext(searchParams);
  const site = await db.site.findFirst({ where: { id, company: { clientId: c.client.id } } });
  if (!site || !(await siteForClient(c.client.id, id))) notFound();
  const tab = (c.one("tab") in TABS ? c.one("tab") : "issues") as keyof typeof TABS;
  const crawl = await db.seoCrawl.findFirst({ where: { siteId: id }, orderBy: { createdAt: "desc" } });
  const done = await db.seoCrawl.findFirst({ where: { siteId: id, status: "done" }, orderBy: { createdAt: "desc" } });
  const history = await db.seoCrawl.findMany({ where: { siteId: id, status: "done" }, orderBy: { createdAt: "asc" }, take: 20, select: { createdAt: true, score: true } });
  const summary = (done?.summary ?? {}) as Record<string, any>;
  const v = summary.vitals as Record<string, any> | null | undefined;
  const busy = crawl && (crawl.status === "queued" || crawl.status === "running");

  return (
    <>
      <SiteNav id={id} active="/seo" name={site.name} domain={site.domain} />
      <div className="mb-4 grid gap-3 md:grid-cols-[200px_1fr_auto]">
        <div className="card text-center">
          <div className="text-xs text-muted">SEO health</div>
          <div className={`text-4xl font-bold ${(done?.score ?? 0) >= 80 ? "text-good" : (done?.score ?? 0) >= 50 ? "text-amber-600" : "text-bad"}`}>{done?.score ?? "—"}</div>
          <div className="text-xs text-muted">{done ? `${done.pagesDone} pages · ${done.createdAt.toISOString().slice(0, 10)}` : "not crawled yet"}</div>
        </div>
        <div className="card grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
          <Check ok={summary.https} label="HTTPS" />
          <Check ok={summary.robotsTxt} label="robots.txt" />
          <Check ok={summary.sitemap} label={`Sitemap${summary.sitemapUrls ? ` (${summary.sitemapUrls} URLs)` : ""}`} />
          <div><span className="text-muted">Avg. response</span> <b>{summary.avgMs ?? "—"} ms</b></div>
          {v ? (
            <>
              <div><span className="text-muted">Performance</span> <b>{v.performance}</b></div>
              <div><span className="text-muted">LCP</span> <b>{v.lcp}</b>{v.fieldLcpMs ? <span className="text-xs text-muted"> · field {Math.round(v.fieldLcpMs)} ms</span> : null}</div>
              <div><span className="text-muted">CLS</span> <b>{v.cls}</b></div>
              <div><span className="text-muted">INP (field)</span> <b>{v.fieldInpMs ? `${v.fieldInpMs} ms` : "—"}</b></div>
            </>
          ) : <div className="col-span-4 text-xs text-muted">Core Web Vitals come from Google PageSpeed Insights on the next crawl (public sites only).</div>}
        </div>
        {canEdit(c.viewer) && (
          <form action={startCrawlAction} className="card flex flex-col justify-center gap-2">
            <input type="hidden" name="id" value={id} />
            <label className="text-xs text-muted">Max pages <input className="input !w-20 !py-1" name="maxPages" type="number" defaultValue={200} /></label>
            <button className="btn" disabled={!!busy}>{busy ? `Crawling… ${crawl!.pagesDone}` : "Run crawl"}</button>
            {crawl?.status === "failed" && <span className="text-xs text-bad">Last crawl failed: {crawl.error}</span>}
          </form>
        )}
      </div>
      {history.length > 1 && <div className="card mb-4"><Chart height={140} option={lineOption(history.map((h) => h.createdAt.toISOString().slice(0, 10)), [{ name: "SEO score", data: history.map((h) => h.score), color: "#c4973a" }])} /></div>}

      <nav className="mb-4 flex gap-1 border-b border-line">
        {Object.entries(TABS).map(([k, l]) => <Link key={k} href={qs(c.sp, { tab: k })} className={`-mb-px border-b-2 px-3 py-2 text-sm ${tab === k ? "border-accent font-medium" : "border-transparent text-muted"}`}>{l}</Link>)}
      </nav>
      {tab === "issues" && (done ? <Issues crawlId={done.id} /> : <div className="card text-sm text-muted">Run a crawl to see issues.</div>)}
      {tab === "pages" && (done ? <Pages crawlId={done.id} /> : <div className="card text-sm text-muted">Run a crawl to list pages.</div>)}
      {tab === "search" && <Search />}
    </>
  );

  async function Issues({ crawlId }: { crawlId: string }) {
    const issues = await db.seoIssue.findMany({ where: { crawlId }, orderBy: { url: "asc" } });
    const groups = new Map<string, typeof issues>();
    for (const i of issues) groups.set(i.code, [...(groups.get(i.code) ?? []), i]);
    const order = { error: 0, warning: 1, notice: 2 } as Record<string, number>;
    const sorted = [...groups.entries()].sort((a, b) => order[a[1][0].severity] - order[b[1][0].severity] || b[1].length - a[1].length);
    return (
      <div className="space-y-2">
        {sorted.map(([code, list]) => (
          <details key={code} className="card !p-0">
            <summary className="flex cursor-pointer items-center gap-3 p-3">
              <span className={`pill ${SEV[list[0].severity as keyof typeof SEV]}`}>{list[0].severity}</span>
              <b className="text-sm">{ISSUE_LABEL[code] ?? code}</b>
              <span className="ms-auto text-sm text-muted">{list.length} page{list.length > 1 ? "s" : ""}</span>
            </summary>
            <table className="tbl"><tbody>{list.slice(0, 200).map((i) => <tr key={i.id}><td className="!whitespace-normal break-all">{i.url}</td><td className="text-xs text-muted">{i.detail}</td></tr>)}</tbody></table>
          </details>
        ))}
        {!sorted.length && <div className="card text-sm text-good">No issues found.</div>}
      </div>
    );
  }

  async function Pages({ crawlId }: { crawlId: string }) {
    const pages = await db.seoPage.findMany({ where: { crawlId } });
    return (
      <div className="card">
        <DataTable csvName="seo-pages" initialSort="depth" cols={[
          { key: "url", label: "URL", sub: "title", wrap: true }, { key: "status", label: "Status", fmt: "num" }, { key: "ms", label: "ms", fmt: "num" },
          { key: "words", label: "Words", fmt: "num" }, { key: "titleLen", label: "Title len.", fmt: "num" }, { key: "descLen", label: "Desc. len.", fmt: "num" },
          { key: "h1Count", label: "H1s", fmt: "num" }, { key: "internal", label: "Int. links", fmt: "num" }, { key: "noAlt", label: "No alt", fmt: "num" }, { key: "schema", label: "Schema", wrap: true }, { key: "depth", label: "Depth", fmt: "num" },
        ]} rows={pages.map((p) => ({ url: p.url, title: p.title, status: p.status, ms: p.ms, words: p.wordCount, titleLen: p.title.length, descLen: p.description.length, h1Count: p.h1Count, internal: p.internalLinks, noAlt: p.imagesNoAlt, schema: p.schemaTypes, depth: p.depth }))} />
      </div>
    );
  }

  async function Search() {
    if (!site!.gscRefreshEnc) {
      return (
        <div className="card space-y-2 text-sm">
          <p>Connect Google Search Console to see the queries and pages that bring organic traffic, with clicks, impressions, CTR and average position.</p>
          {c.one("gsc") && <p className="text-bad">{c.one("gsc")}</p>}
          {gscConfigured() ? <a className="btn" href={`/api/oauth/gsc/start?site=${id}`}>Connect Search Console</a> : <p className="text-muted">Set GSC_CLIENT_ID / GSC_CLIENT_SECRET (or the Google Ads OAuth client) on the server to enable this.</p>}
        </div>
      );
    }
    const r = c.one("days") ? c.range : lastNDays(28);
    const [queries, pages, daily] = await Promise.all([gscQuery(site!, r, "query"), gscQuery(site!, r, "page"), gscQuery(site!, r, "date", 500)]).catch(() => [null, null, null]);
    return (
      <div className="space-y-4">
        <div className="card flex items-center gap-3 text-sm">
          <span>Property <b>{site!.gscProperty || "(none matched this domain)"}</b> · {r.from} → {r.to}</span>
          <form action={disconnectGscAction} className="ms-auto"><input type="hidden" name="id" value={id} /><button className="text-xs text-bad">Disconnect</button></form>
        </div>
        {daily && <div className="card"><Chart height={220} option={lineOption(daily.map((d: any) => d.key), [{ name: "Clicks", data: daily.map((d: any) => d.clicks), color: "#2563eb" }, { name: "Impressions", data: daily.map((d: any) => d.impressions), axis: 1, color: "#c4973a" }], ["", ""])} /></div>}
        <div className="grid gap-4 xl:grid-cols-2">
          {[["Queries", queries], ["Pages", pages]].map(([label, rows]) => (
            <div key={label as string} className="card">
              <h2 className="mb-2 text-sm font-semibold">{label as string}</h2>
              <DataTable csvName={`gsc-${String(label).toLowerCase()}`} initialSort="clicks" cols={[{ key: "key", label: label as string, wrap: true }, { key: "clicks", label: "Clicks", fmt: "num" }, { key: "impressions", label: "Impr.", fmt: "num" }, { key: "ctr", label: "CTR", fmt: "pct" }, { key: "position", label: "Position", fmt: "dec" }]} rows={(rows as any[]) ?? []} />
            </div>
          ))}
        </div>
      </div>
    );
  }
}

function Check({ ok, label }: { ok: boolean | undefined; label: string }) {
  return <div>{ok == null ? "○" : ok ? <span className="text-good">✓</span> : <span className="text-bad">✗</span>} {label}</div>;
}
