"use client";

import { useActionState, useEffect, useState, useTransition } from "react";
import { createLookalikesAction, createPresetsAction, loadSourcesAction, refreshAudiencesAction, uploadCustomerListAction } from "@/app/actions/audience-tools";

type Sources = { pixels: { id: string; name: string }[]; pages: { id: string; name: string }[]; instagram: { id: string; name: string }[]; videos: string[] };
const STAGE: Record<string, string> = { reengagement: "Re-engagement", retargeting: "Retargeting" };

const Note = ({ r }: { r: { ok: boolean; message: string } | null }) => (r ? <p className={`mt-2 text-sm ${r.ok ? "text-good" : "text-bad"}`}>{r.message}</p> : null);

export function RefreshAudiences({ accountId }: { accountId: string }) {
  const [res, action, pending] = useActionState(refreshAudiencesAction, null);
  return (
    <form action={action} className="flex items-center gap-2">
      <input type="hidden" name="accountId" value={accountId} />
      {res && <span className={`text-xs ${res.ok ? "text-good" : "text-bad"}`}>{res.message}</span>}
      <button className="btn-ghost" disabled={pending}>{pending ? "Reading…" : "↻ Read audiences from Meta"}</button>
    </form>
  );
}

/** Ticks ready-made audiences and creates them from the account's pixel, page and Instagram account. */
export function PresetsForm({ accountId, presets, existing }: { accountId: string; presets: { key: string; label: string; stage: string; kind: string }[]; existing: string[] }) {
  const [res, action, pending] = useActionState(createPresetsAction, null);
  const [src, setSrc] = useState<Sources | null>(null);
  const [err, setErr] = useState("");
  const [loading, start] = useTransition();
  useEffect(() => { start(async () => { const r = await loadSourcesAction(accountId); if (r.ok) setSrc(r.sources); else setErr(r.message); }); }, [accountId]);
  const has = (kind: string) => !!src && (kind === "website" ? src.pixels.length : kind === "page" ? src.pages.length : kind === "instagram" ? src.instagram.length : src.videos.length) > 0;
  const pick = (name: string, label: string, list: { id: string; name: string }[]) => (
    <div><label className="label">{label}</label>
      <select className="input" name={name} disabled={!list.length}>{list.length ? list.map((x) => <option key={x.id} value={x.id}>{x.name}</option>) : <option value="">None found in this account</option>}</select></div>
  );
  return (
    <form action={action} className="space-y-3">
      <input type="hidden" name="accountId" value={accountId} />
      {loading && <p className="text-sm text-muted">Reading the account&apos;s pixels, pages and videos from Meta…</p>}
      {err && <p className="text-sm text-bad">Could not read the account&apos;s pixels and pages: {err}</p>}
      {src && (
        <>
          <div className="grid gap-3 sm:grid-cols-3">{pick("pixelId", "Pixel (website visitors)", src.pixels)}{pick("pageId", "Facebook page", src.pages)}{pick("instagramId", "Instagram account", src.instagram)}</div>
          <input type="hidden" name="videoIds" value={src.videos.join(",")} />
          {(["retargeting", "reengagement"] as const).map((stage) => (
            <div key={stage}>
              <div className="label">{STAGE[stage]}</div>
              <div className="grid gap-1 sm:grid-cols-2">
                {presets.filter((p) => p.stage === stage).map((p) => (
                  <label key={p.key} className={`flex items-center gap-2 text-sm ${has(p.kind) ? "" : "text-muted"}`}>
                    <input type="checkbox" name="preset" value={p.key} disabled={!has(p.kind) || existing.includes(p.label)} defaultChecked={has(p.kind) && !existing.includes(p.label) && ["web_30", "web_180", "page_365", "ig_365", "video_75"].includes(p.key)} />
                    {p.label}{existing.includes(p.label) && <span className="pill bg-green-100 text-good">exists</span>}{!has(p.kind) && <span className="text-xs">— needs a {p.kind === "website" ? "pixel" : p.kind === "video" ? "video in the account" : p.kind === "page" ? "Facebook page" : "linked Instagram account"}</span>}
                  </label>
                ))}
              </div>
            </div>
          ))}
          <button className="btn" disabled={pending}>{pending ? "Creating…" : "Create ticked audiences"}</button>
        </>
      )}
      <Note r={res} />
    </form>
  );
}

/** Lookalikes of one audience, in a country, at 1 / 2 / 5 %. */
export function LookalikeForm({ accountId, audiences }: { accountId: string; audiences: { id: string; name: string }[] }) {
  const [res, action, pending] = useActionState(createLookalikesAction, null);
  if (!audiences.length) return <p className="text-sm text-muted">Create or read an audience first; lookalikes are modelled on one.</p>;
  return (
    <form action={action} className="space-y-3">
      <input type="hidden" name="accountId" value={accountId} />
      <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
        <div><label className="label">People similar to</label><select className="input" name="audienceId">{audiences.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</select></div>
        <div><label className="label">Country (2 letters)</label><input className="input uppercase" name="country" maxLength={2} required placeholder="EG" /></div>
      </div>
      <div className="flex flex-wrap gap-4 text-sm">
        <span className="text-muted">Size</span>
        {[1, 2, 5].map((p) => <label key={p} className="flex items-center gap-1"><input type="checkbox" name="percent" value={p} defaultChecked={p === 1} /> {p}% <span className="text-xs text-muted">{p === 1 ? "(closest)" : p === 5 ? "(widest)" : ""}</span></label>)}
      </div>
      <button className="btn" disabled={pending}>{pending ? "Creating…" : "Create lookalikes"}</button>
      <Note r={res} />
    </form>
  );
}

/** Upload or paste a customer list; it is hashed on the server and becomes a Meta custom audience. */
export function CustomerListForm({ accountId }: { accountId: string }) {
  const [res, action, pending] = useActionState(uploadCustomerListAction, null);
  return (
    <form action={action} className="space-y-3">
      <input type="hidden" name="accountId" value={accountId} />
      <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
        <div><label className="label">Audience name</label><input className="input" name="name" required maxLength={120} placeholder="e.g. Customers 2026" /></div>
        <div><label className="label">Country code for local numbers</label><input className="input" name="countryCode" placeholder="20" maxLength={4} /></div>
      </div>
      <div><label className="label">CSV file of emails and/or phone numbers</label><input className="input" type="file" name="file" accept=".csv,.txt,text/csv,text/plain" /></div>
      <div><label className="label">…or paste them (one per line or comma-separated)</label><textarea className="input min-h-20 font-mono text-xs" name="text" /></div>
      <button className="btn" disabled={pending}>{pending ? "Uploading…" : "Create audience from list"}</button>
      <p className="text-xs text-muted">Emails and numbers are hashed (SHA-256) on this server; only the hashes go to Meta, and the list is not stored here. Upload only people who agreed to be contacted. At least 100 are needed.</p>
      <Note r={res} />
    </form>
  );
}
