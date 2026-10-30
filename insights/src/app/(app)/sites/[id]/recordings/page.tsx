import Link from "next/link";
import { notFound } from "next/navigation";
import type { Prisma } from "@prisma/client";
import { Filters } from "@/components/filters";
import { SiteNav } from "@/components/site-nav";
import { DataTable } from "@/components/table";
import { db } from "@/lib/db";
import { toDate } from "@/lib/dates";
import { pageContext, qs, type SP } from "@/lib/page";
import { siteForClient } from "@/lib/web";

export const metadata = { title: "Recordings" };

const FILTERS = { all: "All sessions", replay: "With replay", converted: "Converted", frustrated: "Rage or dead clicks", bounced: "Bounced" } as const;

export default async function Recordings({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<SP> }) {
  const { id } = await params;
  const c = await pageContext(searchParams);
  const site = await siteForClient(c.client.id, id);
  if (!site) notFound();
  const f = (c.one("f") in FILTERS ? c.one("f") : "all") as keyof typeof FILTERS;
  const where: Prisma.TrackSessionWhereInput = {
    siteId: id, startedAt: { gte: toDate(c.range.from), lt: new Date(toDate(c.range.to).getTime() + 86_400_000) },
    ...(f === "replay" ? { hasReplay: true } : f === "converted" ? { converted: true } : f === "bounced" ? { pageviews: { lte: 1 }, durationSec: { lt: 10 } } : f === "frustrated" ? { evs: { some: { type: { in: ["rage_click", "dead_click"] } } } } : {}),
  };
  const sessions = await db.trackSession.findMany({ where, orderBy: { startedAt: "desc" }, take: 500 });

  return (
    <>
      <SiteNav id={id} active="/recordings" name={site.name} domain={site.domain} />
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap gap-1">{Object.entries(FILTERS).map(([k, l]) => <Link key={k} href={qs(c.sp, { f: k })} className={`pill !px-3 !py-1.5 ${k === f ? "bg-accent text-white" : "bg-panel text-muted"}`}>{l}</Link>)}</div>
        <Filters t={c.t} companies={[]} range={c.range} days={c.one("days")} showPlatform={false} extra={{ f }} />
      </div>
      {!site.replay && <div className="card mb-4 text-sm text-muted">Session replay is off for this site — every session still has its full journey (pages, clicks, scroll). Turn replay on in <Link className="text-accent underline" href={`/sites/${id}`}>Setup</Link>.</div>}
      <div className="card">
        <DataTable csvName="sessions" initialSort="started" limit={100}
          cols={[{ key: "started", label: "Started (UTC)", href: "href" }, { key: "channel", label: "Channel", sub: "source" }, { key: "landing", label: "Landing", wrap: true }, { key: "pages", label: "Pages", fmt: "num" }, { key: "duration", label: "Seconds", fmt: "num" }, { key: "scroll", label: "Max scroll", fmt: "pct" }, { key: "device", label: "Device", sub: "browser" }, { key: "country", label: "Country" }, { key: "flags", label: "" }]}
          rows={sessions.map((s) => ({
            started: s.startedAt.toISOString().replace("T", " ").slice(0, 16), href: `/sites/${id}/recordings/${encodeURIComponent(s.id.slice(id.length + 1))}`,
            channel: s.channel, source: s.utmSource || s.refHost || "", landing: s.landingPath, pages: s.pageviews, duration: s.durationSec, scroll: s.maxScroll,
            device: s.device, browser: `${s.browser} · ${s.os}`, country: s.country || "—", flags: [s.hasReplay ? "▶ replay" : "", s.converted ? "✓ converted" : ""].filter(Boolean).join("  "),
          }))} />
      </div>
    </>
  );
}
