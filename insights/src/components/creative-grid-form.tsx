"use client";

import Link from "next/link";
import { useState, useTransition } from "react";
import { createGridAction, loadGridImagesAction, type GridResult } from "@/app/actions/creative-grid";

export type GridAd = { id: string; label: string; headline: string; body: string; cta: string; adSetId: string; format: string };

const split = (s: string) => [...new Set(s.split("\n").map((x) => x.trim()).filter(Boolean))];

/** Enter options per element; every combination becomes one ad copied from the source ad. */
export function CreativeGridForm({ ads, adSets, ctas, max, initialAd }: { ads: GridAd[]; adSets: { id: string; label: string }[]; ctas: string[]; max: number; initialAd: string }) {
  const [adId, setAdId] = useState(initialAd);
  const ad = ads.find((a) => a.id === adId);
  const [adSetId, setAdSetId] = useState("");
  const [headlines, setHeadlines] = useState("");
  const [texts, setTexts] = useState("");
  const [picked, setPicked] = useState<string[]>([]);
  const [images, setImages] = useState<{ hash: string; url: string; name: string }[] | null>(null);
  const [imgs, setImgs] = useState<string[]>([]);
  const [start, setStart] = useState("paused");
  const [res, setRes] = useState<GridResult | null>(null);
  const [err, setErr] = useState("");
  const [pending, go] = useTransition();
  const n = Math.max(1, split(headlines).length) * Math.max(1, split(texts).length) * Math.max(1, imgs.length) * Math.max(1, picked.length);
  const tick = (list: string[], set: (x: string[]) => void, v: string) => set(list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

  if (res?.ok) {
    return (
      <div className="card border-green-200 bg-green-50">
        <p className="font-medium text-good">✓ {res.message}</p>
        <div className="mt-3 flex gap-2"><Link className="btn" href="/creatives?v=elements">See results by element</Link><button type="button" className="btn-ghost" onClick={() => setRes(null)}>Build another</button></div>
      </div>
    );
  }
  return (
    <div className="space-y-4">
      <div className="card space-y-3">
        <h2 className="font-semibold">1. Ad to copy</h2>
        <select className="input" value={adId} onChange={(e) => { setAdId(e.target.value); setImages(null); setImgs([]); }}>{ads.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}</select>
        {ad && <p className="text-xs text-muted">Now: headline “{ad.headline || "—"}” · button {ad.cta.replace(/_/g, " ").toLowerCase() || "—"} · {ad.format || "ad"}. Everything you leave empty below stays as it is. Works for single image and video ads.</p>}
      </div>
      <div className="card space-y-3">
        <h2 className="font-semibold">2. Options to test</h2>
        <div className="grid gap-3 lg:grid-cols-2">
          <div><label className="label">Headlines — one per line</label><textarea className="input min-h-28" dir="auto" value={headlines} onChange={(e) => setHeadlines(e.target.value)} placeholder={ad?.headline} /></div>
          <div><label className="label">Texts — one per line</label><textarea className="input min-h-28" dir="auto" value={texts} onChange={(e) => setTexts(e.target.value)} placeholder={ad?.body.slice(0, 120)} /></div>
        </div>
        <div>
          <div className="label">Buttons</div>
          <div className="flex flex-wrap gap-2">{ctas.map((c) => <label key={c} className={`pill cursor-pointer !px-3 !py-1 ${picked.includes(c) ? "bg-accent text-white" : "bg-bg"}`}><input type="checkbox" className="me-1" checked={picked.includes(c)} onChange={() => tick(picked, setPicked, c)} />{c.replace(/_/g, " ").toLowerCase()}</label>)}</div>
        </div>
        <div>
          <div className="label">Images from the account&apos;s library</div>
          {!images ? <button type="button" className="btn-ghost !py-1" disabled={pending || !adId} onClick={() => go(async () => { const r = await loadGridImagesAction(adId); if (r.ok) { setImages(r.images); setErr(""); } else setErr(r.message); })}>{pending ? "Loading…" : "Load images"}</button> : (
            <div className="flex flex-wrap gap-2">
              {images.map((i) => (
                <label key={i.hash} className={`cursor-pointer rounded-lg border-2 p-1 text-center text-[11px] ${imgs.includes(i.hash) ? "border-accent" : "border-line"}`}>
                  <input type="checkbox" className="sr-only" checked={imgs.includes(i.hash)} onChange={() => tick(imgs, setImgs, i.hash)} />
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  {i.url ? <img src={i.url} alt="" className="h-16 w-16 rounded object-cover" /> : <span className="flex h-16 w-16 items-center justify-center rounded bg-bg">img</span>}
                  <span className="block max-w-16 truncate">{i.name}</span>
                </label>
              ))}
              {!images.length && <span className="text-sm text-muted">The library is empty.</span>}
            </div>
          )}
          {err && <p className="mt-1 text-xs text-bad">{err}</p>}
        </div>
      </div>
      <div className="card space-y-3">
        <h2 className="font-semibold">3. Create</h2>
        <div><label className="label">Put the new ads in</label>
          <select className="input" value={adSetId} onChange={(e) => setAdSetId(e.target.value)}><option value="">The same ad set as the source ad</option>{adSets.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}</select></div>
        <div className="flex flex-wrap gap-4 text-sm">
          <label className="flex items-center gap-1"><input type="radio" checked={start === "paused"} onChange={() => setStart("paused")} /> Create paused</label>
          <label className="flex items-center gap-1"><input type="radio" checked={start === "now"} onChange={() => setStart("now")} /> Start now</label>
        </div>
        <p className={`rounded-lg bg-bg p-3 text-sm ${n > max ? "text-bad" : ""}`}>{n} combination{n === 1 ? "" : "s"} = {n} new ad{n === 1 ? "" : "s"}{n > max ? ` — the limit is ${max}, remove some options` : n < 2 ? " — add at least two options for one element" : ""}. They share the ad set&apos;s budget, so keep the number small enough for each to get results.</p>
        {res && !res.ok && <p className="text-sm text-bad">{res.message}</p>}
        <button type="button" className="btn" disabled={pending || n < 2 || n > max || !adId}
          onClick={() => { if (start === "now" && !confirm(`Create ${n} ads and start them now?`)) return; go(async () => setRes(await createGridAction({ adId, adSetId, headlines: split(headlines), texts: split(texts), images: imgs, ctas: picked, startNow: start === "now" }))); }}>
          {pending ? "Working…" : `Create ${n} ads`}
        </button>
      </div>
    </div>
  );
}
