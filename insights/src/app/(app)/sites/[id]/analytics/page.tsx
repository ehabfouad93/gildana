import Link from "next/link";
import { notFound } from "next/navigation";
import { Chart } from "@/components/chart";
import { barOption, donutOption, lineOption } from "@/components/charts";
import { Filters } from "@/components/filters";
import { SiteNav } from "@/components/site-nav";
import { DataTable } from "@/components/table";
import type { Col } from "@/components/table-types";
import { delta } from "@/lib/metrics";
import { pageContext, qs, type SP } from "@/lib/page";
import { PLATFORM_META } from "@/lib/platforms";
import { WEB_DIMS, siteForClient, webAdsJoin, webBy, webDaily, webEvents, webFunnel, webPages, webRealtime, webTotals, type WebDim, type WebTotals } from "@/lib/web";

export const metadata = { title: "Website analytics" };

const TABS = { overview: "Overview", acquisition: "Acquisition", ads: "Ads → site", pages: "Pages", audience: "Audience", events: "Events", funnel: "Funnel" } as const;
const DIM_LABEL: Record<WebDim, string> = {
  channel: "Channel", source: "Source", medium: "Medium", campaign: "Campaign", referrer: "Referrer", country: "Country", city: "City",
  device: "Device", browser: "Browser", os: "OS", landing: "Landing page", exit: "Exit page", visitor: "New vs returning", hour: "Hour (UTC)",
};

const WEB_COLS: Col[] = [
  { key: "users", label: "Users", fmt: "num" }, { key: "sessions", label: "Sessions", fmt: "num" }, { key: "engaged", label: "Engaged", fmt: "num" },
  { key: "bounceRate", label: "Bounce", fmt: "pct" }, { key: "pagesPerSession", label: "Pages / sess.", fmt: "dec" }, { key: "avgDuration", label: "Avg. sec", fmt: "num" },
  { key: "conversions", label: "Conv.", fmt: "num" }, { key: "convRate", label: "Conv. rate", fmt: "pct" },
];

const fmtDur = (s: number | null) => (s == null ? "—" : `${Math.floor(s / 60)}m ${String(Math.round(s % 60)).padStart(2, "0")}s`);

export default async function Analytics({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<SP> }) {
  const { id } = await params;
  const c = await pageContext(searchParams);
  const site = await siteForClient(c.client.id, id);
  if (!site) notFound();
  const { range, prev, t } = c;
  const tab = (c.one("tab") in TABS ? c.one("tab") : "overview") as keyof typeof TABS;

  return (
    <>
      <SiteNav id={id} active="/analytics" name={site.name} domain={site.domain} />
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <nav className="flex flex-wrap gap-1">
          {Object.entries(TABS).map(([k, label]) => (
            <Link key={k} href={qs(c.sp, { tab: k })} className={`pill !px-3 !py-1.5 ${k === tab ? "bg-accent text-white" : "bg-panel text-muted hover:text-ink"}`}>{label}</Link>
          ))}
        </nav>
        <Filters t={t} companies={[]} range={range} days={c.one("days")} showPlatform={false} extra={{ tab, ...(c.one("steps") ? { steps: c.one("steps") } : {}), ...(c.one("dim") ? { dim: c.one("dim") } : {}) }} />
      </div>
      {tab === "overview" && <Overview />}
      {tab === "acquisition" && <Breakdown dims={["channel", "source", "medium", "campaign", "referrer"]} />}
      {tab === "audience" && <Breakdown dims={["country", "city", "device", "browser", "os", "visitor", "hour"]} />}
      {tab === "pages" && <Pages />}
      {tab === "events" && <Events />}
      {tab === "funnel" && <Funnel />}
      {tab === "ads" && <Ads />}
    </>
  );

  async function Overview() {
    const [cur, before, series, rt, channels, devices, pages, countries] = await Promise.all([
      webTotals(id, range), webTotals(id, prev), webDaily(id, range), webRealtime(id),
      webBy(id, range, "channel"), webBy(id, range, "device"), webPages(id, range), webBy(id, range, "country", 10),
    ]);
    return (
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
          <div className="card !p-3"><div className="text-xs text-muted">Active now</div><div className="text-lg font-semibold text-good">● {rt.active}</div><div className="text-xs text-muted">last 5 min</div></div>
          <Stat label="Users" cur={cur.users} prev={before.users} />
          <Stat label="New users" cur={cur.newUsers} prev={before.newUsers} />
          <Stat label="Sessions" cur={cur.sessions} prev={before.sessions} />
          <Stat label="Page views" cur={cur.pageviews} prev={before.pageviews} />
          <Stat label="Bounce rate" cur={cur.bounceRate} prev={before.bounceRate} fmt="pct" invert />
          <Stat label="Avg. session" cur={cur.avgDuration} prev={before.avgDuration} fmt="dur" />
          <Stat label="Conversions" cur={cur.conversions} prev={before.conversions} sub={cur.convRate != null ? `${cur.convRate.toFixed(2)}% rate` : ""} />
        </div>
        <div className="grid gap-4 lg:grid-cols-3">
          <div className="card lg:col-span-2">
            <h2 className="mb-2 text-sm font-semibold">Users, sessions and conversions</h2>
            <Chart option={lineOption(series.map((d) => d.day), [
              { name: "Users", data: series.map((d) => d.users), color: "#2563eb" },
              { name: "Sessions", data: series.map((d) => d.sessions), color: "#94a3b8" },
              { name: "Conversions", data: series.map((d) => d.conversions), axis: 1, type: "bar", color: "#c4973a" },
            ], ["", ""])} />
          </div>
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">Sessions by channel</h2>
            <Chart option={donutOption(channels.map((x) => ({ name: x.key, value: x.sessions })))} />
          </div>
        </div>
        <div className="grid gap-4 lg:grid-cols-3">
          <div className="card lg:col-span-2">
            <h2 className="mb-2 text-sm font-semibold">Top pages</h2>
            <DataTable search={false} limit={10} initialSort="views" cols={[{ key: "path", label: "Page", sub: "title", wrap: true }, { key: "views", label: "Views", fmt: "num" }, { key: "avgTime", label: "Avg. sec", fmt: "num" }, { key: "avgScroll", label: "Avg. scroll", fmt: "pct" }, { key: "exitRate", label: "Exit rate", fmt: "pct" }]}
              rows={pages.map((p) => ({ path: p.path, title: p.title, views: p.views, avgTime: p.avgTime, avgScroll: p.avgScroll, exitRate: p.views ? (p.exits / p.views) * 100 : null }))} />
          </div>
          <div className="card space-y-4">
            <div>
              <h2 className="mb-2 text-sm font-semibold">Devices</h2>
              <Chart height={180} option={donutOption(devices.map((x) => ({ name: x.key, value: x.sessions })))} />
            </div>
            <div>
              <h2 className="mb-2 text-sm font-semibold">Countries</h2>
              <Chart height={200} option={barOption(countries.map((x) => x.key), [{ name: "Sessions", data: countries.map((x) => x.sessions), color: "#2563eb" }], true)} />
            </div>
          </div>
        </div>
        {!!rt.pages.length && (
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">Being viewed now</h2>
            <div className="flex flex-wrap gap-2">{rt.pages.map((p) => <span key={p.path} className="pill bg-bg">{p.path} · {p.n}</span>)}</div>
          </div>
        )}
      </div>
    );
  }

  async function Breakdown({ dims }: { dims: WebDim[] }) {
    const dim = (dims.includes(c.one("dim") as WebDim) ? c.one("dim") : dims[0]) as WebDim;
    const rows = await webBy(id, range, dim, 500);
    return (
      <div className="card">
        <div className="mb-3 flex flex-wrap gap-1">
          {dims.map((d) => <Link key={d} href={qs(c.sp, { dim: d })} className={`pill ${d === dim ? "bg-ink text-white" : "bg-bg text-muted"}`}>{DIM_LABEL[d]}</Link>)}
        </div>
        {rows.length > 1 && <Chart height={220} option={barOption(rows.slice(0, 15).map((r) => r.key), [{ name: "Sessions", data: rows.slice(0, 15).map((r) => r.sessions), color: "#2563eb" }, { name: "Conversions", data: rows.slice(0, 15).map((r) => r.conversions), color: "#c4973a" }])} />}
        <DataTable csvName={`web-${dim}`} initialSort="sessions" cols={[{ key: "key", label: DIM_LABEL[dim], wrap: true }, ...WEB_COLS]} rows={rows.map(row)} />
      </div>
    );
  }

  async function Pages() {
    const pages = await webPages(id, range);
    return (
      <div className="card">
        <DataTable csvName="web-pages" initialSort="views"
          cols={[{ key: "path", label: "Page", sub: "title", wrap: true, href: "href" }, { key: "views", label: "Views", fmt: "num" }, { key: "sessions", label: "Sessions", fmt: "num" }, { key: "avgTime", label: "Avg. time (s)", fmt: "num" }, { key: "avgScroll", label: "Avg. scroll", fmt: "pct" }, { key: "entrances", label: "Entrances", fmt: "num" }, { key: "exits", label: "Exits", fmt: "num" }, { key: "exitRate", label: "Exit rate", fmt: "pct" }]}
          rows={pages.map((p) => ({ ...p, href: `/sites/${id}/heatmaps?path=${encodeURIComponent(p.path)}`, exitRate: p.views ? (p.exits / p.views) * 100 : null }))} />
        <p className="mt-2 text-xs text-muted">Click a page to open its heatmap.</p>
      </div>
    );
  }

  async function Events() {
    const rows = await webEvents(id, range);
    return (
      <div className="card">
        <DataTable csvName="web-events" initialSort="n" cols={[{ key: "type", label: "Event" }, { key: "name", label: "Name" }, { key: "n", label: "Count", fmt: "num" }, { key: "sessions", label: "Sessions", fmt: "num" }]}
          rows={rows.map((r) => ({ type: r.type.replace(/_/g, " "), name: r.name || "—", n: r.n, sessions: r.sessions }))} />
      </div>
    );
  }

  async function Funnel() {
    const def = [site!.goals.find((g) => g.kind === "path")?.match].filter(Boolean) as string[];
    const steps = (c.one("steps") || ["/", ...def].join(",")).split(",").map((s) => s.trim()).filter(Boolean).slice(0, 8);
    const rows = await webFunnel(id, range, steps);
    return (
      <div className="card space-y-4">
        <form method="get" className="flex flex-wrap items-end gap-2">
          <input type="hidden" name="tab" value="funnel" /><input type="hidden" name="days" value={c.one("days")} />
          <div className="min-w-80 flex-1"><label className="label">Steps — page paths in order, comma separated (each matches as a prefix)</label><input className="input" name="steps" defaultValue={steps.join(", ")} /></div>
          <button className="btn">Update</button>
        </form>
        <Chart height={260} option={barOption(rows.map((r) => r.step), [{ name: "Sessions", data: rows.map((r) => r.sessions), color: "#c4973a" }])} />
        <DataTable search={false} cols={[{ key: "step", label: "Step" }, { key: "sessions", label: "Sessions", fmt: "num" }, { key: "rate", label: "Of step 1", fmt: "pct" }, { key: "drop", label: "Drop-off", fmt: "pct" }]} rows={rows} />
      </div>
    );
  }

  async function Ads() {
    const rows = await webAdsJoin(id, site!.companyId, range, c.client.reportingCurrency);
    return (
      <div className="card">
        <p className="mb-3 text-sm text-muted">Visits tagged with <code>utm_campaign</code> matched to the ad campaign of the same name (or id) in {site!.company.name}&apos;s ad accounts. Spend is from the ad platforms, behaviour from the site — so this is your real cost per engaged visit and per site conversion.</p>
        <DataTable currency={c.client.reportingCurrency} csvName="ads-to-site" initialSort="sessions"
          cols={[{ key: "utm", label: "utm_campaign", sub: "platform", wrap: true, href: "href" }, { key: "spend", label: "Ad spend", fmt: "money" }, { key: "adClicks", label: "Ad clicks", fmt: "num" }, { key: "sessions", label: "Sessions", fmt: "num" }, { key: "clickToSession", label: "Click → visit", fmt: "pct" }, { key: "bounceRate", label: "Bounce", fmt: "pct" }, { key: "avgDuration", label: "Avg. sec", fmt: "num" }, { key: "conversions", label: "Site conv.", fmt: "num" }, { key: "costPerSession", label: "Cost / visit", fmt: "money2" }, { key: "costPerConversion", label: "Cost / site conv.", fmt: "money2" }]}
          rows={rows.map((r) => ({ ...r, platform: r.platform ? PLATFORM_META[r.platform].label : "not matched to a campaign", href: r.campaignId ? `/campaigns/${r.campaignId}` : undefined }))} />
      </div>
    );
  }
}

function row(r: { key: string } & WebTotals) {
  return { key: r.key, users: r.users, sessions: r.sessions, engaged: r.engaged, bounceRate: r.bounceRate, pagesPerSession: r.pagesPerSession, avgDuration: r.avgDuration, conversions: r.conversions, convRate: r.convRate };
}

function Stat({ label, cur, prev, fmt = "num", invert = false, sub }: { label: string; cur: number | null; prev: number | null; fmt?: "num" | "pct" | "dur"; invert?: boolean; sub?: string }) {
  const d = delta(cur, prev);
  const good = d == null ? null : invert ? d < 0 : d > 0;
  const v = cur == null ? "—" : fmt === "pct" ? `${cur.toFixed(1)}%` : fmt === "dur" ? fmtDur(cur) : cur.toLocaleString();
  return (
    <div className="card !p-3">
      <div className="text-xs text-muted">{label}</div>
      <div className="text-lg font-semibold tabular-nums">{v}</div>
      {d != null ? <div className={`text-xs ${good ? "text-good" : "text-bad"}`}>{d > 0 ? "▲" : "▼"} {Math.abs(d).toFixed(1)}%</div> : sub ? <div className="text-xs text-muted">{sub}</div> : null}
    </div>
  );
}
