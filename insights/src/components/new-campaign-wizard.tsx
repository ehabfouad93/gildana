"use client";

import Link from "next/link";
import { useEffect, useMemo, useRef, useState, useTransition } from "react";
import { aiCopyAction, createCampaignAction, estimateReachAction, loadPageAction, loadSetupAction, searchInterestsAction, searchLocationsAction, uploadMediaAction } from "@/app/actions/new-campaign";
import { SendForApproval } from "@/components/approval-buttons";
import type { AdSpec, CampaignSpec, Goal, Setup } from "@/platforms/meta/new-campaign";

type Item = { type: string; value: string; externalId: string; note?: string };
type Opt = { id: string; name: string };
type ExistingAd = { creativeId: string; name: string; headline: string; campaign: string };

const GOAL_LIST: [Goal, string, string][] = [
  ["leads_form", "Leads — instant form", "People fill a form inside Facebook / Instagram"],
  ["whatsapp", "Messages — WhatsApp", "People start a WhatsApp chat with you"],
  ["messenger", "Messages — Messenger", "People start a Messenger chat with the page"],
  ["website_leads", "Leads — on your website", "People fill a form on your site (needs a pixel)"],
  ["traffic", "Traffic — website visits", "Send people to a page of your site"],
  ["awareness", "Awareness — reach", "Show the ads to as many people as possible"],
  ["video_views", "Engagement — video views", "People watch your video"],
  ["post_engagement", "Engagement — post engagement", "Likes, comments and shares"],
];
const CTAS = ["LEARN_MORE", "SIGN_UP", "GET_QUOTE", "CONTACT_US", "APPLY_NOW", "BOOK_NOW", "DOWNLOAD", "SHOP_NOW", "SUBSCRIBE", "GET_OFFER", "SEND_MESSAGE", "WHATSAPP_MESSAGE", "CALL_NOW"];
const PLACEMENTS: [keyof Exclude<CampaignSpec["placements"], "auto">, string, string[]][] = [
  ["facebook", "Facebook", ["feed", "story", "facebook_reels", "marketplace", "video_feeds", "search", "instream_video"]],
  ["instagram", "Instagram", ["stream", "story", "reels", "explore", "explore_home"]],
  ["messenger", "Messenger", ["story"]],
  ["audience_network", "Audience Network", ["classic", "rewarded_video"]],
];
const plus = (d: number) => new Date(Date.now() + d * 86_400_000).toISOString().slice(0, 10);

function Section({ n, title, children, hint }: { n: number; title: string; hint?: string; children: React.ReactNode }) {
  return (
    <section className="card space-y-3">
      <h2 className="font-semibold"><span className="me-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-accent text-xs text-white">{n}</span>{title}</h2>
      {hint && <p className="-mt-2 text-xs text-muted">{hint}</p>}
      {children}
    </section>
  );
}

/** Chips plus a Meta search, for one audience group or the exclusions. */
function Chips({ items, onChange, accountId, suggestions, placeholder }: { items: Item[]; onChange: (x: Item[]) => void; accountId: string; suggestions: Item[]; placeholder: string }) {
  const [q, setQ] = useState("");
  const [found, setFound] = useState<Item[]>([]);
  const [msg, setMsg] = useState("");
  const [pending, go] = useTransition();
  const same = (a: Item, b: Item) => a.type === b.type && a.externalId === b.externalId;
  const add = (i: Item) => !items.some((x) => same(x, i)) && onChange([...items, i]);
  const mine = suggestions.filter((a) => !items.some((x) => same(x, a)) && (!q || a.value.toLowerCase().includes(q.toLowerCase()))).slice(0, 10);
  return (
    <div className="rounded-lg border border-line p-2">
      <div className="mb-2 flex min-h-7 flex-wrap gap-1.5">
        {items.map((i) => <button key={i.type + i.externalId} type="button" className="pill bg-blue-100 text-blue-800" onClick={() => onChange(items.filter((x) => !same(x, i)))}>{i.value} ×</button>)}
        {!items.length && <span className="text-xs text-muted">{placeholder}</span>}
      </div>
      <div className="flex gap-2">
        <input className="input !py-1" placeholder="Filter your audiences, or search Meta interests…" value={q} onChange={(e) => setQ(e.target.value)} />
        <button type="button" className="btn-ghost shrink-0 !py-1" disabled={pending || q.trim().length < 2} onClick={() => go(async () => { const r = await searchInterestsAction(accountId, q); setFound(r.items); setMsg(r.ok ? (r.items.length ? "" : "Nothing found.") : r.message); })}>{pending ? "…" : "Search Meta"}</button>
      </div>
      {msg && <p className="mt-1 text-xs text-bad">{msg}</p>}
      <div className="mt-2 flex flex-wrap gap-1.5">
        {[...mine, ...found.filter((f) => !items.some((x) => same(x, f)))].map((i) => <button key={"s" + i.type + i.externalId} type="button" className="pill bg-bg hover:bg-line" onClick={() => add(i)} title={i.note}>+ {i.value}{i.note ? <span className="ms-1 text-muted">{i.note}</span> : null}</button>)}
      </div>
    </div>
  );
}

function AdEditor({ ad, i, onChange, onRemove, goal, accountId, images, posts, existing, aiOn, onUploaded }: {
  ad: AdSpec; i: number; onChange: (a: AdSpec) => void; onRemove: () => void; goal: Goal; accountId: string;
  images: { hash: string; url: string; name: string }[]; posts: { id: string; instagram: boolean; text: string; image: string; date: string }[]; existing: ExistingAd[]; aiOn: boolean;
  onUploaded: (img: { hash: string; url: string; name: string }) => void;
}) {
  const [pending, go] = useTransition();
  const [msg, setMsg] = useState("");
  const [ai, setAi] = useState<{ headlines: string[]; texts: string[] } | null>(null);
  const [brief, setBrief] = useState({ product: "", offer: "", audience: "" });
  const file = useRef<HTMLInputElement>(null);
  const set = (p: Partial<AdSpec>) => onChange({ ...ad, ...p });
  const upload = () => {
    const f = file.current?.files?.[0];
    if (!f) return;
    const fd = new FormData(); fd.set("accountId", accountId); fd.set("file", f);
    go(async () => {
      const r = await uploadMediaAction(fd);
      setMsg(r.message);
      if (r.ok && r.kind === "image") { set({ imageHash: r.hash, videoId: undefined }); onUploaded({ hash: r.hash!, url: r.url ?? "", name: f.name }); }
      if (r.ok && r.kind === "video") set({ videoId: r.videoId });
    });
  };
  return (
    <div className="rounded-lg border border-line p-3">
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <input className="input !w-64 !py-1 font-medium" value={ad.name} onChange={(e) => set({ name: e.target.value })} aria-label="Ad name" />
        <div className="flex rounded-lg bg-bg p-0.5 text-sm">
          {([["new", "New ad"], ["post", "Existing post"], ["copy", "Reuse an ad"]] as const).map(([k, l]) => (
            <button key={k} type="button" className={`rounded-md px-2 py-1 ${ad.source === k ? "bg-panel font-medium shadow-sm" : "text-muted"}`} onClick={() => set({ source: k })}>{l}</button>
          ))}
        </div>
        <button type="button" className="ms-auto text-xs text-bad hover:underline" onClick={onRemove}>Remove ad {i + 1}</button>
      </div>

      {ad.source === "post" && (
        <div className="grid max-h-72 gap-2 overflow-y-auto sm:grid-cols-2">
          {posts.map((p) => (
            <label key={p.id} className={`flex cursor-pointer gap-2 rounded-lg border p-2 text-xs ${ad.postId === p.id ? "border-accent" : "border-line"}`}>
              <input type="radio" checked={ad.postId === p.id} onChange={() => set({ postId: p.id, instagram: p.instagram })} />
              {/* eslint-disable-next-line @next/next/no-img-element */}
              {p.image ? <img src={p.image} alt="" className="h-12 w-12 rounded object-cover" /> : null}
              <span><b>{p.instagram ? "Instagram" : "Facebook"}</b> · {p.date}<br />{p.text || "(no text)"}</span>
            </label>
          ))}
          {!posts.length && <p className="text-sm text-muted">Choose the page above to see its recent posts.</p>}
        </div>
      )}

      {ad.source === "copy" && (
        <select className="input" value={ad.creativeId ?? ""} onChange={(e) => { const x = existing.find((a) => a.creativeId === e.target.value); set({ creativeId: e.target.value, headline: x?.headline }); }}>
          <option value="">Choose an existing ad…</option>
          {existing.map((a) => <option key={a.creativeId} value={a.creativeId}>{a.name} · {a.campaign}</option>)}
        </select>
      )}

      {ad.source === "new" && (
        <div className="grid gap-3 lg:grid-cols-2">
          <div className="space-y-2">
            <div className="label">Image or video</div>
            <div className="flex flex-wrap items-center gap-2">
              <input ref={file} type="file" accept="image/*,video/*" className="text-sm" />
              <button type="button" className="btn-ghost !py-1" disabled={pending} onClick={upload}>{pending ? "Uploading…" : "Upload"}</button>
            </div>
            {msg && <p className="text-xs text-muted">{msg}</p>}
            {ad.videoId && <p className="text-xs text-good">✓ Video attached{ad.imageHash ? " (with a cover image)" : ""}</p>}
            {images.length > 0 && (
              <div className="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
                {images.map((m) => (
                  <button key={m.hash} type="button" title={m.name} onClick={() => set({ imageHash: m.hash })} className={`rounded border-2 ${ad.imageHash === m.hash ? "border-accent" : "border-transparent"}`}>
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    {m.url ? <img src={m.url} alt="" className="h-14 w-14 rounded object-cover" /> : <span className="flex h-14 w-14 items-center justify-center rounded bg-bg text-[10px]">{m.name}</span>}
                  </button>
                ))}
              </div>
            )}
            <div><label className="label">Button</label>
              <select className="input" value={ad.cta ?? ""} onChange={(e) => set({ cta: e.target.value || undefined })} disabled={goal === "whatsapp" || goal === "messenger"}>
                <option value="">{goal === "whatsapp" ? "WhatsApp" : goal === "messenger" ? "Send message" : "Default for this goal"}</option>
                {CTAS.map((c) => <option key={c} value={c}>{c.replace(/_/g, " ").toLowerCase()}</option>)}
              </select></div>
            {(goal === "traffic" || goal === "website_leads" || goal === "awareness" || goal === "post_engagement" || goal === "video_views") && (
              <div><label className="label">Link for this ad (optional)</label><input className="input" type="url" value={ad.url ?? ""} onChange={(e) => set({ url: e.target.value })} placeholder="Uses the campaign's website" /></div>
            )}
          </div>
          <div className="space-y-2">
            <div><label className="label">Main text</label><textarea className="input min-h-24" dir="auto" value={ad.text ?? ""} onChange={(e) => set({ text: e.target.value })} maxLength={2000} /></div>
            <div><label className="label">Headline</label><input className="input" dir="auto" value={ad.headline ?? ""} onChange={(e) => set({ headline: e.target.value })} maxLength={255} /></div>
            <div><label className="label">Description (optional)</label><input className="input" dir="auto" value={ad.description ?? ""} onChange={(e) => set({ description: e.target.value })} maxLength={255} /></div>
            {aiOn && (
              <details className="rounded-lg bg-bg p-2 text-sm">
                <summary className="cursor-pointer font-medium">✦ Suggest texts with AI</summary>
                <div className="mt-2 grid gap-2">
                  <input className="input !py-1" dir="auto" placeholder="Product / project (e.g. Porto Golf chalets, Sokhna)" value={brief.product} onChange={(e) => setBrief({ ...brief, product: e.target.value })} />
                  <input className="input !py-1" dir="auto" placeholder="Offer (e.g. 10% down, 8 years)" value={brief.offer} onChange={(e) => setBrief({ ...brief, offer: e.target.value })} />
                  <input className="input !py-1" dir="auto" placeholder="Who it's for (e.g. families in Cairo)" value={brief.audience} onChange={(e) => setBrief({ ...brief, audience: e.target.value })} />
                  <button type="button" className="btn-ghost !py-1" disabled={pending || !brief.product.trim()} onClick={() => go(async () => { const r = await aiCopyAction({ ...brief, goal }); if (r.ok) setAi(r); else setMsg(r.message); })}>{pending ? "Writing…" : "Suggest"}</button>
                </div>
                {ai && (
                  <div className="mt-2 space-y-1 text-xs">
                    <div className="text-muted">Click to use — then edit freely.</div>
                    {ai.headlines.map((h) => <button key={h} type="button" dir="auto" className="block w-full rounded bg-panel px-2 py-1 text-start hover:bg-line" onClick={() => set({ headline: h })}>H: {h}</button>)}
                    {ai.texts.map((t) => <button key={t} type="button" dir="auto" className="block w-full rounded bg-panel px-2 py-1 text-start hover:bg-line" onClick={() => set({ text: t })}>T: {t}</button>)}
                  </div>
                )}
              </details>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

export function NewCampaignWizard({ accounts, initialAccount, prefill, existingAds, aiOn, canApprove, approvalRequired }: {
  accounts: { id: string; name: string; currency: string }[]; initialAccount: string; prefill: Partial<CampaignSpec> | null;
  existingAds: Record<string, ExistingAd[]>; aiOn: boolean; canApprove: boolean; approvalRequired: boolean;
}) {
  const [accountId, setAccountId] = useState(initialAccount);
  const account = accounts.find((a) => a.id === accountId)!;
  const [setup, setSetup] = useState<Setup | null>(null);
  const [images, setImages] = useState<{ hash: string; url: string; name: string }[]>([]);
  const [saved, setSaved] = useState<Item[]>([]);
  const [forms, setForms] = useState<Opt[]>([]);
  const [posts, setPosts] = useState<{ id: string; instagram: boolean; text: string; image: string; date: string }[]>([]);
  const [setupErr, setSetupErr] = useState("");
  const [spec, setSpec] = useState<CampaignSpec>(() => ({
    name: "", goal: "leads_form", special: "", budget: 0, budgetType: "daily", campaignBudget: false, start: plus(1), end: plus(31),
    countries: ["EG"], regions: [], cities: [], ageMin: 18, ageMax: 65, gender: "all", groups: [[]], exclusions: [], advantageAudience: false,
    placements: "auto", startNow: false, ads: [{ name: "Ad 1", source: "new" }], ...(prefill ?? {}),
  } as CampaignSpec));
  const set = (p: Partial<CampaignSpec>) => setSpec((s) => ({ ...s, ...p }));
  const [loc, setLoc] = useState("");
  const [locFound, setLocFound] = useState<{ type: "country" | "region" | "city"; key: string; name: string }[]>([]);
  const [reach, setReach] = useState("");
  const [res, setRes] = useState<{ ok: boolean; message: string } | null>(null);
  const [pending, go] = useTransition();

  useEffect(() => {
    setSetup(null); setSetupErr("");
    loadSetupAction(accountId).then((r) => {
      if (!r.ok) { setSetupErr(r.message); return; }
      setSetup(r.setup); setImages(r.images); setSaved(r.audiences);
      setSpec((s) => (s.pageId || !r.setup.pages[0] ? s : { ...s, pageId: r.setup.pages[0].id, instagramId: r.setup.pages[0].instagram?.id, whatsappNumber: r.setup.pages[0].whatsapp }));
    });
  }, [accountId]);
  useEffect(() => {
    if (!spec.pageId) return;
    loadPageAction(accountId, spec.pageId, spec.instagramId).then((r) => { if (r.ok) { setForms(r.forms); setPosts(r.posts); if (!spec.formId && r.forms[0]) set({ formId: r.forms[0].id }); } });
  }, [accountId, spec.pageId]); // eslint-disable-line react-hooks/exhaustive-deps

  const page = setup?.pages.find((p) => p.id === spec.pageId);
  const fmt = (n: number) => new Intl.NumberFormat("en-US", { style: "currency", currency: account.currency, maximumFractionDigits: 0 }).format(n);
  const days = spec.start && spec.end ? Math.max(1, Math.round((Date.parse(spec.end) - Date.parse(spec.start)) / 86_400_000)) : null;
  const total = spec.budgetType === "daily" ? (days ? spec.budget * days : null) : spec.budget;
  const ready = useMemo(() => spec.name.trim() && spec.budget > 0 && spec.ads.length > 0, [spec]);
  const placements = spec.placements;

  if (res?.ok) {
    return (
      <div className="card border-green-200 bg-green-50">
        <p className="font-medium text-good">✓ {res.message}</p>
        <div className="mt-3 flex gap-2"><Link className="btn" href="/campaigns">Open campaigns</Link><button type="button" className="btn-ghost" onClick={() => setRes(null)}>Create another</button></div>
      </div>
    );
  }
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
      <div className="space-y-4">
        <Section n={1} title="Ad account and goal">
          <div className="grid gap-3 sm:grid-cols-2">
            <div><label className="label">Ad account</label>
              <select className="input" value={accountId} onChange={(e) => { setAccountId(e.target.value); set({ pageId: undefined, formId: undefined, pixelId: undefined }); }}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.name} · {a.currency}</option>)}</select></div>
            <div><label className="label">Campaign name</label><input className="input" dir="auto" value={spec.name} onChange={(e) => set({ name: e.target.value })} maxLength={200} /></div>
          </div>
          {setupErr && <p className="text-sm text-bad">Could not read the account&apos;s pages and pixels: {setupErr}</p>}
          <div className="grid gap-2 sm:grid-cols-2">
            {GOAL_LIST.map(([k, label, hint]) => (
              <label key={k} className={`flex cursor-pointer gap-2 rounded-lg border p-2 text-sm ${spec.goal === k ? "border-accent bg-accent/5" : "border-line"}`}>
                <input type="radio" checked={spec.goal === k} onChange={() => set({ goal: k })} className="mt-1" />
                <span><b>{label}</b><br /><span className="text-xs text-muted">{hint}</span></span>
              </label>
            ))}
          </div>
          <div className="grid gap-3 sm:grid-cols-3">
            <div><label className="label">Facebook page</label>
              <select className="input" value={spec.pageId ?? ""} onChange={(e) => { const p = setup?.pages.find((x) => x.id === e.target.value); set({ pageId: e.target.value || undefined, instagramId: p?.instagram?.id, whatsappNumber: p?.whatsapp, formId: undefined }); }}>
                <option value="">{setup ? "Choose…" : "Loading…"}</option>{setup?.pages.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
              {page && <p className="mt-1 text-xs text-muted">{page.instagram ? `Instagram: @${page.instagram.username}` : "No Instagram account linked"}{spec.goal === "whatsapp" ? ` · WhatsApp: ${page.whatsapp ?? "none linked"}` : ""}</p>}</div>
            {spec.goal === "leads_form" && <div><label className="label">Lead form</label>
              <select className="input" value={spec.formId ?? ""} onChange={(e) => set({ formId: e.target.value || undefined })}><option value="">{forms.length ? "Choose…" : "No forms on this page"}</option>{forms.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}</select>
              <p className="mt-1 text-xs text-muted">Create new forms in Meta&apos;s Instant Forms tool; they appear here.</p></div>}
            {spec.goal === "website_leads" && <div><label className="label">Pixel</label>
              <select className="input" value={spec.pixelId ?? ""} onChange={(e) => set({ pixelId: e.target.value || undefined })}><option value="">Choose…</option>{setup?.pixels.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>}
            {(spec.goal === "traffic" || spec.goal === "website_leads") && <div><label className="label">Website address</label><input className="input" type="url" value={spec.url ?? ""} onChange={(e) => set({ url: e.target.value })} placeholder="https://…" /></div>}
          </div>
          <div><label className="label">Special ad category</label>
            <select className="input !w-auto" value={spec.special} onChange={(e) => { const v = e.target.value as CampaignSpec["special"]; set({ special: v, ...(v ? { ageMin: 18, ageMax: 65, gender: "all" as const, advantageAudience: false } : {}) }); }}>
              <option value="">None</option><option value="HOUSING">Housing (real estate)</option><option value="CREDIT">Credit / financial</option><option value="EMPLOYMENT">Employment</option><option value="ISSUES_ELECTIONS_POLITICS">Social issues, elections, politics</option>
            </select>
            <p className="mt-1 text-xs text-muted">Real-estate ads usually need “Housing”. Meta then limits targeting: ages 18–65, all genders, fewer detailed options.</p></div>
        </Section>

        <Section n={2} title="Who sees the ads">
          <div>
            <div className="label">Locations</div>
            <div className="mb-2 flex flex-wrap gap-1.5">
              {spec.countries.map((c) => <button key={c} type="button" className="pill bg-bg" onClick={() => set({ countries: spec.countries.filter((x) => x !== c) })}>{c} ×</button>)}
              {(spec.regions ?? []).map((r) => <button key={r.key} type="button" className="pill bg-bg" onClick={() => set({ regions: spec.regions!.filter((x) => x.key !== r.key) })}>{r.name} ×</button>)}
              {(spec.cities ?? []).map((c) => (
                <span key={c.key} className="pill bg-bg">{c.name} +<input className="mx-1 w-10 rounded border border-line bg-panel px-1" type="number" min="1" max="80" value={c.radius ?? 10} onChange={(e) => set({ cities: spec.cities!.map((x) => (x.key === c.key ? { ...x, radius: Number(e.target.value) || 10 } : x)) })} />km
                  <button type="button" className="ms-1" onClick={() => set({ cities: spec.cities!.filter((x) => x.key !== c.key) })}>×</button></span>
              ))}
            </div>
            <div className="flex gap-2">
              <input className="input !py-1" placeholder="Search a country, region or city…" value={loc} onChange={(e) => setLoc(e.target.value)} />
              <button type="button" className="btn-ghost shrink-0 !py-1" disabled={pending || loc.trim().length < 2} onClick={() => go(async () => setLocFound((await searchLocationsAction(accountId, loc)).items))}>Search</button>
            </div>
            <div className="mt-1 flex flex-wrap gap-1.5">
              {locFound.map((l) => (
                <button key={l.type + l.key} type="button" className="pill bg-bg hover:bg-line" onClick={() => {
                  if (l.type === "country") set({ countries: [...new Set([...spec.countries, l.key])] });
                  else if (l.type === "region") set({ regions: [...(spec.regions ?? []).filter((x) => x.key !== l.key), { key: l.key, name: l.name }] });
                  else set({ cities: [...(spec.cities ?? []).filter((x) => x.key !== l.key), { key: l.key, name: l.name, radius: 10 }] });
                }}>+ {l.name} <span className="text-muted">{l.type}</span></button>
              ))}
            </div>
          </div>
          <div className="grid gap-3 sm:grid-cols-3">
            <div><label className="label">Age from</label><input className="input" type="number" min="13" max="65" value={spec.ageMin} disabled={!!spec.special} onChange={(e) => set({ ageMin: Number(e.target.value) })} /></div>
            <div><label className="label">to</label><input className="input" type="number" min="13" max="65" value={spec.ageMax} disabled={!!spec.special} onChange={(e) => set({ ageMax: Number(e.target.value) })} /></div>
            <div><label className="label">Gender</label><select className="input" value={spec.gender} disabled={!!spec.special} onChange={(e) => set({ gender: e.target.value as CampaignSpec["gender"] })}><option value="all">All</option><option value="male">Men</option><option value="female">Women</option></select></div>
          </div>
          <div>
            <div className="label">Detailed targeting — people must match every group; inside a group any one item is enough</div>
            {spec.groups.map((g, i) => (
              <div key={i} className="mb-2">
                {i > 0 && <div className="my-1 text-center text-xs font-semibold text-muted">AND</div>}
                <Chips items={g as Item[]} accountId={accountId} suggestions={saved} placeholder={i === 0 ? "Empty = broad (everyone in the locations and ages)" : "Add items or remove this group"} onChange={(x) => set({ groups: spec.groups.map((y, k) => (k === i ? x : y)) })} />
              </div>
            ))}
            <div className="flex gap-2">
              {spec.groups.length < 4 && <button type="button" className="btn-ghost !py-1" onClick={() => set({ groups: [...spec.groups, []] })}>+ AND another group</button>}
              {spec.groups.length > 1 && <button type="button" className="btn-ghost !py-1" onClick={() => set({ groups: spec.groups.slice(0, -1) })}>Remove last group</button>}
            </div>
          </div>
          <div><div className="label">Exclude</div><Chips items={spec.exclusions as Item[]} accountId={accountId} suggestions={saved} placeholder="Nobody matching these is reached (e.g. existing customers)" onChange={(x) => set({ exclusions: x })} /></div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={spec.advantageAudience} disabled={!!spec.special} onChange={(e) => set({ advantageAudience: e.target.checked })} /> Advantage+ audience — let Meta go beyond the targeting when it finds better people</label>
          <div className="flex flex-wrap items-center gap-3">
            <button type="button" className="btn-ghost" disabled={pending} onClick={() => go(async () => setReach((await estimateReachAction(accountId, spec)).message))}>Check reach</button>
            {reach && <span className="text-sm">{reach}</span>}
          </div>
        </Section>

        <Section n={3} title="Budget, dates and placements">
          <div className="grid gap-3 sm:grid-cols-4">
            <div><label className="label">Budget ({account.currency})</label><input className="input" type="number" min="1" step="any" value={spec.budget || ""} onChange={(e) => set({ budget: Number(e.target.value) })} /></div>
            <div><label className="label">Per</label><select className="input" value={spec.budgetType} onChange={(e) => set({ budgetType: e.target.value as "daily" | "lifetime" })}><option value="daily">Day</option><option value="lifetime">Whole campaign</option></select></div>
            <div><label className="label">Start</label><input className="input" type="date" value={spec.start ?? ""} onChange={(e) => set({ start: e.target.value || undefined })} /></div>
            <div><label className="label">End {spec.budgetType === "daily" ? "(optional)" : ""}</label><input className="input" type="date" value={spec.end ?? ""} onChange={(e) => set({ end: e.target.value || undefined })} /></div>
          </div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={spec.campaignBudget} onChange={(e) => set({ campaignBudget: e.target.checked })} /> Advantage+ campaign budget (the budget sits on the campaign)</label>
          <div>
            <div className="label">Placements</div>
            <div className="flex gap-4 text-sm">
              <label className="flex items-center gap-1"><input type="radio" checked={placements === "auto"} onChange={() => set({ placements: "auto" })} /> Advantage+ placements (recommended)</label>
              <label className="flex items-center gap-1"><input type="radio" checked={placements !== "auto"} onChange={() => set({ placements: { facebook: ["feed", "story", "facebook_reels"], instagram: ["stream", "story", "reels"] } })} /> Choose myself</label>
            </div>
            {placements !== "auto" && (
              <div className="mt-2 grid gap-2 sm:grid-cols-2">
                {PLACEMENTS.map(([pub, label, positions]) => (
                  <div key={pub} className="rounded-lg border border-line p-2 text-sm">
                    <div className="font-medium">{label}</div>
                    <div className="flex flex-wrap gap-x-3">
                      {positions.map((pos) => {
                        const on = placements[pub]?.includes(pos) ?? false;
                        return <label key={pos} className="flex items-center gap-1 text-xs"><input type="checkbox" checked={on} onChange={() => set({ placements: { ...placements, [pub]: on ? placements[pub]!.filter((x) => x !== pos) : [...(placements[pub] ?? []), pos] } })} />{pos.replace(/_/g, " ")}</label>;
                      })}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </Section>

        <Section n={4} title="Ads" hint="One or more ads in the ad set. Meta shows the best-performing ones more.">
          {spec.ads.map((a, i) => (
            <AdEditor key={i} ad={a} i={i} goal={spec.goal} accountId={accountId} images={images} posts={posts} existing={existingAds[accountId] ?? []} aiOn={aiOn}
              onUploaded={(img) => setImages((l) => [img, ...l])}
              onChange={(x) => set({ ads: spec.ads.map((y, k) => (k === i ? x : y)) })} onRemove={() => set({ ads: spec.ads.filter((_, k) => k !== i) })} />
          ))}
          {spec.ads.length < 10 && <button type="button" className="btn-ghost" onClick={() => set({ ads: [...spec.ads, { name: `Ad ${spec.ads.length + 1}`, source: "new" }] })}>+ Add another ad</button>}
        </Section>
      </div>

      <aside className="space-y-3 lg:sticky lg:top-4 lg:self-start">
        <div className="card space-y-2 text-sm">
          <h2 className="font-semibold">Summary</h2>
          <div><span className="text-muted">Goal:</span> {GOAL_LIST.find(([k]) => k === spec.goal)?.[1]}</div>
          <div><span className="text-muted">Where:</span> {[...spec.countries, ...(spec.regions ?? []).map((r) => r.name), ...(spec.cities ?? []).map((c) => c.name)].join(", ") || "—"}</div>
          <div><span className="text-muted">Ages:</span> {spec.ageMin}–{spec.ageMax}{spec.gender !== "all" ? `, ${spec.gender === "male" ? "men" : "women"}` : ""}</div>
          <div><span className="text-muted">Audience:</span> {spec.groups.some((g) => g.length) ? spec.groups.filter((g) => g.length).map((g) => g.map((x) => x.value).join(" or ")).join(" AND ") : "Broad"}</div>
          <div><span className="text-muted">Budget:</span> {spec.budget ? `${fmt(spec.budget)} ${spec.budgetType === "daily" ? "/ day" : "total"}` : "—"}</div>
          {total ? <div><span className="text-muted">Up to:</span> <b>{fmt(total)}</b>{days ? ` over ${days} days` : ""}</div> : null}
          <div><span className="text-muted">Ads:</span> {spec.ads.length}</div>
          <hr className="border-line" />
          <label className="flex items-center gap-2"><input type="radio" checked={!spec.startNow} onChange={() => set({ startNow: false })} /> Create paused</label>
          <label className="flex items-center gap-2"><input type="radio" checked={spec.startNow} onChange={() => set({ startNow: true })} /> Start now</label>
          {res && !res.ok && <p className="text-xs text-bad">{res.message}</p>}
          {!approvalRequired && (
            <button type="button" className="btn w-full justify-center" disabled={pending || !ready}
              onClick={() => { if (spec.startNow && !confirm(`Create “${spec.name}” and start it now? It will spend up to ${total ? fmt(total) : "its budget"}.`)) return; go(async () => setRes(await createCampaignAction(accountId, spec))); }}>
              {pending ? "Creating…" : "Create campaign"}
            </button>
          )}
          {canApprove && <SendForApproval kind="new_campaign" disabled={!ready} payload={() => ({ accountId, spec })} />}
          {approvalRequired && <p className="text-xs text-muted">This workspace needs the client&apos;s approval first; launch it from To-do → Approvals once accepted.</p>}
          <p className="text-xs text-muted">Nothing is created until you press the button. Meta reviews every ad before it runs.</p>
        </div>
      </aside>
    </div>
  );
}
