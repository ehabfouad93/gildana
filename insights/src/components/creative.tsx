"use client";

import { useState } from "react";

export type CreativeData = {
  id: string; platform: string; name: string; format: string; headline: string; body: string; cta: string;
  thumbUrl: string; landingUrl: string; adSet: string; hasLivePreview: boolean;
  stats: { label: string; value: string }[];
};

const BRAND: Record<string, string> = { META: "#1877f2", GOOGLE: "#16a34a", LINKEDIN: "#0a66c2", SNAPCHAT: "#facc15", TIKTOK: "#111111" };

/**
 * One ad as a card, opening a full preview. Real platform previews (Meta's
 * /previews iframe) load on demand from /api/ads/[id]/preview; otherwise the
 * preview is rebuilt from the creative fields in the platform's layout.
 */
export function CreativeCard({ ad }: { ad: CreativeData }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button type="button" onClick={() => setOpen(true)} className="card flex flex-col !p-0 text-start transition hover:shadow-md">
        <Mock ad={ad} compact />
        <div className="space-y-2 p-3">
          <div className="truncate text-sm font-medium">{ad.name}</div>
          <div className="truncate text-xs text-muted">{ad.adSet}</div>
          <div className="grid grid-cols-3 gap-1 text-xs">
            {ad.stats.map((s) => (
              <div key={s.label}><div className="text-muted">{s.label}</div><div className="font-semibold tabular-nums">{s.value}</div></div>
            ))}
          </div>
        </div>
      </button>
      {open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={() => setOpen(false)}>
          <div className="max-h-[92vh] w-full max-w-3xl overflow-auto rounded-2xl bg-panel p-5" onClick={(e) => e.stopPropagation()}>
            <div className="mb-3 flex items-center justify-between">
              <div>
                <div className="font-semibold">{ad.name}</div>
                <div className="text-xs text-muted">{ad.adSet} · {ad.format}</div>
              </div>
              <button className="btn-ghost" onClick={() => setOpen(false)}>Close</button>
            </div>
            <div className="grid gap-5 md:grid-cols-[360px_1fr]">
              <div>
                {ad.hasLivePreview ? (
                  <iframe title="Ad preview" src={`/api/ads/${ad.id}/preview`} className="h-[560px] w-full rounded-lg border border-line" />
                ) : (
                  <div className="overflow-hidden rounded-lg border border-line"><Mock ad={ad} /></div>
                )}
              </div>
              <div className="space-y-3 text-sm">
                <Field k="Headline" v={ad.headline} />
                <Field k="Primary text" v={ad.body} />
                <Field k="Call to action" v={ad.cta.replace(/_/g, " ")} />
                <Field k="Landing page" v={ad.landingUrl} />
                <div className="grid grid-cols-3 gap-2 pt-2">
                  {ad.stats.map((s) => <div key={s.label} className="rounded-lg bg-bg p-2"><div className="text-xs text-muted">{s.label}</div><div className="font-semibold">{s.value}</div></div>)}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function Field({ k, v }: { k: string; v: string }) {
  return <div><div className="text-xs text-muted">{k}</div><div className="break-words">{v || "—"}</div></div>;
}

function Mock({ ad, compact = false }: { ad: CreativeData; compact?: boolean }) {
  const color = BRAND[ad.platform] ?? "#64748b";
  if (ad.platform === "GOOGLE" && /search/.test(ad.format)) {
    return (
      <div className={`bg-white p-4 ${compact ? "h-44" : ""}`}>
        <div className="text-xs text-slate-700"><b>Sponsored</b> · {safeHost(ad.landingUrl)}</div>
        <div className="mt-1 text-lg leading-snug text-[#1a0dab]">{ad.headline}</div>
        <div className="mt-1 text-sm text-slate-600">{ad.body}</div>
      </div>
    );
  }
  const vertical = /story|reel|snap|tiktok|spark|topview|in_feed_video/.test(ad.format) || ad.platform === "TIKTOK" || ad.platform === "SNAPCHAT";
  return (
    <div className="bg-white">
      {!compact && (
        <div className="flex items-center gap-2 p-3">
          <div className="h-8 w-8 rounded-full" style={{ background: color }} />
          <div className="text-xs"><div className="font-semibold">Advertiser</div><div className="text-slate-500">Sponsored</div></div>
        </div>
      )}
      {!compact && ad.body && <div className="px-3 pb-2 text-sm">{ad.body}</div>}
      <div className={`relative flex items-center justify-center ${compact ? "h-44" : vertical ? "h-[420px]" : "h-72"}`}
        style={ad.thumbUrl ? { backgroundImage: `url(${ad.thumbUrl})`, backgroundSize: "cover", backgroundPosition: "center" } : { background: `linear-gradient(135deg, ${color}, #0f172a)` }}>
        {!ad.thumbUrl && <div className="px-6 text-center text-lg font-semibold text-white">{ad.headline}</div>}
        {/video|reel|story|snap|spark|topview/.test(ad.format) && <div className="absolute rounded-full bg-black/50 px-3 py-1 text-xs text-white">▶ video</div>}
        <span className="absolute start-2 top-2 rounded bg-black/50 px-2 py-0.5 text-[10px] uppercase text-white">{ad.format.replace(/_/g, " ")}</span>
      </div>
      {!compact && (
        <div className="flex items-center justify-between gap-2 bg-slate-50 p-3">
          <div className="min-w-0"><div className="truncate text-xs text-slate-500">{safeHost(ad.landingUrl)}</div><div className="truncate text-sm font-semibold">{ad.headline}</div></div>
          <span className="shrink-0 rounded bg-slate-200 px-3 py-1.5 text-xs font-semibold">{ad.cta.replace(/_/g, " ") || "Learn more"}</span>
        </div>
      )}
    </div>
  );
}

function safeHost(u: string) {
  try { return new URL(u).host; } catch { return u; }
}
