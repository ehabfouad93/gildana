import Link from "next/link";
import { notFound } from "next/navigation";
import { Chart } from "@/components/chart";
import { lineOption } from "@/components/charts";
import { Filters } from "@/components/filters";
import { Heatmap } from "@/components/heatmap";
import { SiteNav } from "@/components/site-nav";
import { SnapshotButton } from "@/components/snapshot-button";
import { DataTable } from "@/components/table";
import { db } from "@/lib/db";
import { pageContext, qs, type SP } from "@/lib/page";
import { clickedElements, heatPaths, heatPoints, scrollReach, siteForClient, type HeatKind } from "@/lib/web";

export const metadata = { title: "Heatmaps" };

const WIDTH: Record<string, number> = { desktop: 1440, tablet: 820, mobile: 390 };
const MODES = { click: "Clicks", rage_click: "Rage clicks", dead_click: "Dead clicks", scroll: "Scroll depth" } as const;

export default async function Heatmaps({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<SP> }) {
  const { id } = await params;
  const c = await pageContext(searchParams);
  const site = await siteForClient(c.client.id, id);
  if (!site) notFound();
  const { range, t } = c;

  const paths = await heatPaths(id, range);
  const path = c.one("path") || paths[0]?.path || "/";
  const device = c.one("device") in WIDTH ? c.one("device") : "desktop";
  const mode = (c.one("mode") in MODES ? c.one("mode") : "click") as keyof typeof MODES;

  const [points, scroll, elements, snap] = await Promise.all([
    mode === "scroll" ? Promise.resolve([]) : heatPoints(id, path, device, range, mode as HeatKind),
    scrollReach(id, path, device, range),
    clickedElements(id, path, device, range),
    db.pageSnapshot.findUnique({ where: { siteId_path_device: { siteId: id, path, device } }, select: { height: true, createdAt: true } }),
  ]);
  const pageW = WIDTH[device];
  // Clicks are recorded against the visitor's own document height; normalise to the snapshot's.
  const pageH = snap?.height || scroll.docH || Math.max(1200, ...points.map((p) => p.yPx ?? 0)) + 200;
  const pts = points.map((p) => ({ x: p.xPct ?? 0, y: p.docH && snap?.height ? ((p.yPx ?? 0) / p.docH) * snap.height : p.yPx ?? 0 }));
  const origin = site.isDemo ? "" : `https://${site.domain}`;
  const background = snap ? { kind: "image" as const, src: `/api/sites/${id}/snapshot?path=${encodeURIComponent(path)}&device=${device}&v=${snap.createdAt.getTime()}` } : { kind: "iframe" as const, src: origin + path };

  return (
    <>
      <SiteNav id={id} active="/heatmaps" name={site.name} domain={site.domain} />
      <div className="mb-4 flex flex-wrap items-end gap-2">
        <form method="get" className="flex flex-wrap items-end gap-2">
          <input type="hidden" name="days" value={c.one("days")} /><input type="hidden" name="device" value={device} /><input type="hidden" name="mode" value={mode} />
          <div><label className="label">Page</label>
            <select name="path" defaultValue={path} className="input !w-72">{(paths.some((p) => p.path === path) ? paths : [{ path, views: 0 }, ...paths]).map((p) => <option key={p.path} value={p.path}>{p.path} ({p.views})</option>)}</select></div>
          <button className="btn-ghost">Show</button>
        </form>
        <div className="flex gap-1">
          {Object.keys(WIDTH).map((d) => <Link key={d} href={qs(c.sp, { device: d })} className={`pill !px-3 !py-1.5 ${d === device ? "bg-ink text-white" : "bg-panel text-muted"}`}>{d}</Link>)}
        </div>
        <div className="flex gap-1">
          {Object.entries(MODES).map(([k, l]) => <Link key={k} href={qs(c.sp, { mode: k })} className={`pill !px-3 !py-1.5 ${k === mode ? "bg-accent text-white" : "bg-panel text-muted"}`}>{l}</Link>)}
        </div>
        <div className="ms-auto flex items-end gap-2">
          <SnapshotButton siteId={id} path={path} device={device} />
          <Filters t={t} companies={[]} range={range} days={c.one("days")} showPlatform={false} extra={{ path, device, mode }} />
        </div>
      </div>

      <div className="grid gap-4 xl:grid-cols-[1fr_380px]">
        <div className="card">
          <Heatmap points={pts} pageW={pageW} pageH={pageH} reach={scroll.reach} mode={mode === "scroll" ? "scroll" : "click"} background={background} />
          {!snap && !site.isDemo && <p className="mt-2 text-xs text-muted">Showing the live page in a frame. If it stays blank, the site blocks framing — capture a snapshot instead.</p>}
        </div>
        <div className="space-y-4">
          <div className="card">
            <div className="grid grid-cols-3 gap-2 text-center">
              <div><div className="text-xs text-muted">Page views</div><div className="text-lg font-semibold">{scroll.views.toLocaleString()}</div></div>
              <div><div className="text-xs text-muted">Avg. time</div><div className="text-lg font-semibold">{Math.round(scroll.avgTime)}s</div></div>
              <div><div className="text-xs text-muted">Reach 50%</div><div className="text-lg font-semibold">{Math.round(scroll.reach.find((r) => r.depth === 50)?.pct ?? 0)}%</div></div>
            </div>
          </div>
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">Scroll reach</h2>
            <Chart height={180} option={lineOption(scroll.reach.map((r) => `${r.depth}%`), [{ name: "Visitors reaching", data: scroll.reach.map((r) => r.pct), color: "#c4973a" }])} />
          </div>
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">Most clicked elements</h2>
            <DataTable search={false} limit={15} initialSort="clicks"
              cols={[{ key: "label", label: "Element", sub: "selector", wrap: true }, { key: "clicks", label: "Clicks", fmt: "num" }, { key: "rage", label: "Rage", fmt: "num" }, { key: "dead", label: "Dead", fmt: "num" }]}
              rows={elements.map((e) => ({ label: e.text || "(no text)", selector: e.selector, clicks: e.clicks, rage: e.rage, dead: e.dead }))} />
            <p className="mt-2 text-xs text-muted"><b>Rage</b>: 3+ fast clicks in one spot (frustration). <b>Dead</b>: a click on something that did nothing — often an image or text people expect to be a link.</p>
          </div>
        </div>
      </div>
    </>
  );
}
