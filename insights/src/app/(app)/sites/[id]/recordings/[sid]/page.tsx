import { notFound } from "next/navigation";
import { ReplayPlayer } from "@/components/replay-player";
import { SiteNav } from "@/components/site-nav";
import { db } from "@/lib/db";
import { requireWorkspace } from "@/lib/tenancy";
import { siteForClient } from "@/lib/web";

export const metadata = { title: "Session" };

export default async function SessionView({ params }: { params: Promise<{ id: string; sid: string }> }) {
  const { id, sid } = await params;
  const v = await requireWorkspace();
  const site = await siteForClient(v.clientId, id);
  if (!site) notFound();
  const s = await db.trackSession.findUnique({ where: { id: `${id}:${decodeURIComponent(sid)}` }, include: { views: { orderBy: { ts: "asc" } }, evs: { orderBy: { ts: "asc" }, take: 500 } } });
  if (!s) notFound();
  const timeline = [
    ...s.views.map((p) => ({ ts: p.ts, kind: "page", text: `${p.path}`, detail: `${p.activeSec}s active · scrolled ${p.scrollPct}%` })),
    ...s.evs.map((e) => ({ ts: e.ts, kind: e.type, text: e.text || e.name || e.selector, detail: e.selector && e.text ? e.selector : "" })),
  ].sort((a, b) => a.ts.getTime() - b.ts.getTime());
  const color: Record<string, string> = { page: "bg-blue-100 text-blue-800", click: "bg-slate-100", rage_click: "bg-red-100 text-bad", dead_click: "bg-amber-100 text-amber-800", form_submit: "bg-green-100 text-good", conversion: "bg-green-100 text-good" };

  return (
    <>
      <SiteNav id={id} active="/recordings" name={site.name} domain={site.domain} />
      <div className="grid gap-4 xl:grid-cols-[1fr_380px]">
        <div className="card">
          {s.hasReplay ? <ReplayPlayer url={`/api/sites/${id}/replay/${encodeURIComponent(decodeURIComponent(sid))}`} /> : <p className="text-sm text-muted">No replay was recorded for this session. Its journey is on the right.</p>}
        </div>
        <div className="space-y-4">
          <div className="card text-sm">
            <div className="grid grid-cols-2 gap-y-1">
              <span className="text-muted">Started</span><span>{s.startedAt.toISOString().replace("T", " ").slice(0, 19)} UTC</span>
              <span className="text-muted">Duration</span><span>{s.durationSec}s · {s.pageviews} pages</span>
              <span className="text-muted">Channel</span><span>{s.channel}</span>
              <span className="text-muted">Source</span><span>{[s.utmSource, s.utmMedium, s.utmCampaign].filter(Boolean).join(" / ") || s.refHost || "direct"}</span>
              <span className="text-muted">Device</span><span>{s.device} · {s.browser} · {s.os}</span>
              <span className="text-muted">Location</span><span>{[s.city, s.country].filter(Boolean).join(", ") || "—"}</span>
              <span className="text-muted">Visitor</span><span>{s.isNew ? "New" : "Returning"}</span>
              <span className="text-muted">Converted</span><span>{s.converted ? "Yes" : "No"}</span>
            </div>
          </div>
          <div className="card">
            <h2 className="mb-2 text-sm font-semibold">Journey</h2>
            <ol className="space-y-1.5 text-sm">
              {timeline.map((x, i) => (
                <li key={i} className="flex gap-2">
                  <span className="w-14 shrink-0 text-xs tabular-nums text-muted">+{Math.round((x.ts.getTime() - s.startedAt.getTime()) / 1000)}s</span>
                  <span className={`pill shrink-0 ${color[x.kind] ?? "bg-slate-100"}`}>{x.kind.replace(/_/g, " ")}</span>
                  <span className="min-w-0"><span className="break-words">{x.text}</span>{x.detail && <span className="block truncate text-xs text-muted">{x.detail}</span>}</span>
                </li>
              ))}
            </ol>
          </div>
        </div>
      </div>
    </>
  );
}
