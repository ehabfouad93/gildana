/**
 * Gildana Insights tracker. Embed on any site:
 *
 *   <script async src="https://insights.example.com/t.js" data-key="SITE_KEY"></script>
 *
 * Records page views, clicks (with position, for heatmaps), rage and dead
 * clicks, scroll depth, time on page, form starts/submits and custom events.
 * Optional session replay loads rrweb only for sampled sessions.
 *
 *   window.gildana("event", "video_play")
 *   window.gildana("event", "payment_plan_view", { project: "porto-sokhna", unit: "2-bed" })
 *   window.gildana("conversion", "purchase", 149.0)
 *   window.gildana("identify", { phone: "+20100…", email: "…", name: "…", crm_id: "…", user_id: "…" })
 *   window.gildana("consent", { analytics: true, ads: true, marketing: false })
 *
 * WhatsApp, call and PDF-brochure link clicks and video plays are recorded on
 * their own. With data-identify="1" a sent form that contains a phone or email
 * field identifies the visitor (values go only to this site's own Insights).
 * With data-consent="required" nothing is stored or sent until
 * gildana("consent", { analytics: true }). Honours Do Not Track with data-dnt="1".
 * No fingerprinting: the only identifier is a random first-party id.
 */
(() => {
  const script = document.currentScript as HTMLScriptElement | null;
  if (!script) return;
  const KEY = script.dataset.key ?? "";
  if (!KEY) return;
  if (script.dataset.dnt === "1" && (navigator.doNotTrack === "1" || (window as any).doNotTrack === "1")) return;
  const API = (script.dataset.api ?? new URL(script.src).origin).replace(/\/$/, "");

  const rand = () => Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
  const store = (s: Storage | null, k: string, make: () => string) => {
    try {
      const v = s?.getItem(k);
      if (v) return v;
      const n = make();
      s?.setItem(k, n);
      return n;
    } catch { return make(); }
  };

  // Consent mode: keep everything in memory until analytics consent is given.
  const needsConsent = script.dataset.consent === "required";
  const prior = (() => { try { return localStorage.getItem("gi_c"); } catch { return null; } })();
  let allowed = !needsConsent || prior === "1";
  if (needsConsent && prior === "0") return;
  const isNewVisitor = (() => { try { return !localStorage.getItem("gi_v"); } catch { return true; } })();
  let visitor = allowed ? store(localStorage, "gi_v", rand) : (() => { try { return localStorage.getItem("gi_v") ?? rand(); } catch { return rand(); } })();
  // A session ends after 30 minutes without activity.
  const sessionId = (() => {
    try {
      const raw = sessionStorage.getItem("gi_s");
      const [id, last] = (raw ?? "").split("|");
      if (id && Date.now() - Number(last) < 30 * 60_000) return id;
    } catch { /* storage blocked */ }
    return rand();
  })();
  const touch = () => { if (!allowed) return; try { sessionStorage.setItem("gi_s", `${sessionId}|${Date.now()}`); } catch { /* ignore */ } };
  touch();

  const device = () => { const w = window.innerWidth; return w < 768 ? "mobile" : w < 1024 ? "tablet" : "desktop"; };
  const docH = () => Math.max(document.documentElement.scrollHeight, document.body?.scrollHeight ?? 0);
  const docW = () => Math.max(document.documentElement.scrollWidth, document.body?.scrollWidth ?? 0, 1);
  const path = () => location.pathname + location.search.replace(/([?&])(utm_[^=]+|gclid|fbclid|ttclid|li_fat_id|ScCid|msclkid)=[^&]*/g, "$1").replace(/[?&]+$/, "");

  let queue: Record<string, unknown>[] = [];
  const push = (e: Record<string, unknown>) => { queue.push({ ...e, ts: Date.now(), p: path(), d: device() }); touch(); if (queue.length >= 20) flush(); };
  const flush = (beacon = false) => {
    if (!queue.length || !allowed) return;
    const body = JSON.stringify({ k: KEY, v: visitor, s: sessionId, e: queue });
    queue = [];
    // text/plain keeps the request "simple" — no CORS preflight, and sendBeacon accepts it.
    if (beacon && navigator.sendBeacon) navigator.sendBeacon(`${API}/api/collect`, new Blob([body], { type: "text/plain" }));
    else fetch(`${API}/api/collect`, { method: "POST", body, keepalive: true, headers: { "content-type": "text/plain" } }).catch(() => {});
  };
  setInterval(() => flush(), 5000);

  // ── page views and time on page ──
  let pv = "", active = 0, lastTick = Date.now(), maxScroll = 0;
  const visible = () => document.visibilityState === "visible";
  setInterval(() => { if (visible()) active += (Date.now() - lastTick) / 1000; lastTick = Date.now(); }, 1000);

  const leave = () => {
    if (!pv) return;
    push({ t: "leave", pv, a: Math.round(active), sc: maxScroll, dh: docH() });
  };
  let lastUrl = "";
  const pageview = () => {
    const here = location.pathname + location.search;
    if (here === lastUrl) return;
    lastUrl = here;
    leave();
    pv = rand(); active = 0; maxScroll = 0;
    const q = new URLSearchParams(location.search);
    const utm: Record<string, string> = {};
    for (const k of ["utm_source", "utm_medium", "utm_campaign", "utm_content", "utm_term", "gclid", "fbclid", "ttclid", "li_fat_id", "ScCid", "msclkid"]) {
      const v = q.get(k);
      if (v) utm[k] = v.slice(0, 200);
    }
    push({ t: "pv", pv, ti: document.title.slice(0, 200), r: document.referrer.slice(0, 500), vw: window.innerWidth, vh: window.innerHeight, dh: docH(), u: utm, nv: isNewVisitor });
    measureScroll();
  };

  const measureScroll = () => {
    const pct = Math.min(100, Math.round(((window.scrollY + window.innerHeight) / Math.max(docH(), 1)) * 100));
    if (pct > maxScroll) maxScroll = pct;
  };
  addEventListener("scroll", measureScroll, { passive: true });

  // SPA navigation
  const wrap = (fn: "pushState" | "replaceState") => {
    const orig = history[fn];
    history[fn] = function (this: History, ...args: Parameters<History["pushState"]>) {
      const r = orig.apply(this, args);
      setTimeout(pageview, 0);
      return r;
    } as History["pushState"];
  };
  wrap("pushState");
  wrap("replaceState");
  addEventListener("popstate", () => setTimeout(pageview, 0));

  // ── clicks ──
  const selector = (el: Element | null): string => {
    const parts: string[] = [];
    for (let e = el, i = 0; e && e.nodeType === 1 && i < 5; e = e.parentElement, i++) {
      if (e.id) { parts.unshift(`#${CSS.escape(e.id)}`); break; }
      let s = e.tagName.toLowerCase();
      const cls = [...e.classList].filter((c) => !/^(is-|has-|active|hover|focus)/.test(c)).slice(0, 2);
      if (cls.length) s += "." + cls.map((c) => CSS.escape(c)).join(".");
      const parent = e.parentElement;
      if (parent) {
        const same = [...parent.children].filter((c) => c.tagName === e!.tagName);
        if (same.length > 1) s += `:nth-of-type(${same.indexOf(e) + 1})`;
      }
      parts.unshift(s);
    }
    return parts.join(" > ").slice(0, 300);
  };
  const interactive = (el: Element | null) => !!el?.closest("a,button,input,select,textarea,label,summary,[role=button],[onclick],[tabindex]");

  let recent: { x: number; y: number; t: number }[] = [];
  addEventListener("click", (ev) => {
    const target = ev.target as Element | null;
    const x = ev.pageX, y = ev.pageY;
    const base = { xp: Math.round((x / docW()) * 10000) / 100, y: Math.round(y), dw: docW(), dh: docH(), sel: selector(target), tx: (target?.textContent ?? "").trim().replace(/\s+/g, " ").slice(0, 80) };
    push({ t: "click", ...base });
    const link = target?.closest?.("a") as HTMLAnchorElement | null;
    const href = (link?.getAttribute("href") ?? "").trim().toLowerCase();
    if (href) {
      if (/^(https?:\/\/)?(wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com|web\.whatsapp\.com)|^whatsapp:/.test(href)) push({ t: "custom", n: "whatsapp_click" });
      else if (href.startsWith("tel:")) push({ t: "custom", n: "call_click" });
      else if (/\.pdf(\?|#|$)/.test(href) || link?.hasAttribute("download")) push({ t: "custom", n: "brochure_download", pr: { file: href.split("/").pop()!.split("?")[0].slice(0, 120) } });
    }

    const now = Date.now();
    recent = recent.filter((c) => now - c.t < 700 && Math.abs(c.x - x) < 30 && Math.abs(c.y - y) < 30);
    recent.push({ x, y, t: now });
    if (recent.length === 3) push({ t: "rage_click", ...base });

    if (!interactive(target)) {
      // A click on something that is not interactive and changes nothing is a "dead" click.
      let changed = false;
      const mo = new MutationObserver(() => (changed = true));
      mo.observe(document.body, { childList: true, subtree: true, attributes: true });
      const href = location.href;
      setTimeout(() => { mo.disconnect(); if (!changed && href === location.href) push({ t: "dead_click", ...base }); }, 1000);
    }
  }, { capture: true, passive: true });

  // ── video ──
  const videoSrc = (v: HTMLMediaElement) => (v.currentSrc || v.getAttribute("src") || "").split("/").pop()!.split("?")[0].slice(0, 120);
  const played = new WeakSet<EventTarget>();
  addEventListener("play", (ev) => { const v = ev.target as HTMLMediaElement; if (v?.tagName === "VIDEO" && !played.has(v)) { played.add(v); push({ t: "custom", n: "video_play", pr: { video: videoSrc(v) } }); } }, true);
  addEventListener("ended", (ev) => { const v = ev.target as HTMLMediaElement; if (v?.tagName === "VIDEO") push({ t: "custom", n: "video_complete", pr: { video: videoSrc(v) } }); }, true);

  // ── forms ──
  const started = new WeakSet<HTMLFormElement>();
  addEventListener("focusin", (ev) => {
    const f = (ev.target as Element | null)?.closest?.("form") as HTMLFormElement | null;
    if (f && !started.has(f)) { started.add(f); push({ t: "form_start", n: f.id || f.getAttribute("name") || selector(f) }); }
  });
  const autoIdentify = script.dataset.identify === "1";
  const formIdentity = (f: HTMLFormElement) => {
    const who: Record<string, string> = {};
    for (const el of Array.from(f.elements) as HTMLInputElement[]) {
      if (!el.name && !el.id && !el.type) continue;
      const key = `${el.name} ${el.id} ${el.getAttribute("autocomplete") ?? ""} ${el.type}`.toLowerCase();
      const v = (el.value ?? "").trim();
      if (!v || el.type === "password" || el.type === "hidden" && !/crm/.test(key)) continue;
      if (!who.email && (el.type === "email" || /e-?mail/.test(key))) who.email = v.slice(0, 200);
      else if (!who.phone && (el.type === "tel" || /phone|mobile|tel|whats/.test(key))) who.phone = v.slice(0, 40);
      else if (!who.name && /(^|\s)(full_?name|name|first_?name)(\s|$)/.test(key)) who.name = v.slice(0, 120);
    }
    return who;
  };
  addEventListener("submit", (ev) => {
    const f = ev.target as HTMLFormElement;
    push({ t: "form_submit", n: f.id || f.getAttribute("name") || selector(f) });
    if (autoIdentify && f.dataset.giIgnore !== "1") { const who = formIdentity(f); if (who.phone || who.email) push({ t: "identify", id: who }); }
    flush(true);
  }, { capture: true });

  // ── public API ──
  const clean = (o: unknown, max = 20): Record<string, string | number | boolean> => {
    const out: Record<string, string | number | boolean> = {};
    if (!o || typeof o !== "object") return out;
    for (const [k, v] of Object.entries(o as Record<string, unknown>).slice(0, max)) {
      if (typeof v === "string") out[k.slice(0, 40)] = v.slice(0, 200);
      else if (typeof v === "number" || typeof v === "boolean") out[k.slice(0, 40)] = v;
    }
    return out;
  };
  const api = (cmd: string, name?: unknown, value?: unknown) => {
    if (cmd === "event" || cmd === "conversion") {
      const pr = typeof value === "object" ? clean(value) : undefined;
      const val = typeof value === "number" ? value : typeof pr?.value === "number" ? (pr.value as number) : undefined;
      push({ t: cmd === "event" ? "custom" : "conversion", n: String(name ?? "").slice(0, 100), val, pr });
    } else if (cmd === "identify") {
      const w = clean(name) as Record<string, string>;
      const id = { phone: w.phone, email: w.email, name: w.name, crm: w.crm_id ?? w.crm, uid: w.user_id ?? w.uid };
      if (Object.values(id).some(Boolean)) { push({ t: "identify", id }); flush(); }
    } else if (cmd === "consent") {
      const c = clean(name) as Record<string, boolean>;
      if (typeof c.analytics === "boolean") {
        try { localStorage.setItem("gi_c", c.analytics ? "1" : "0"); } catch { /* ignore */ }
        if (!c.analytics) { queue = []; allowed = false; return; }
        if (!allowed) { allowed = true; visitor = store(localStorage, "gi_v", () => visitor); touch(); }
      }
      push({ t: "consent", cs: { analytics: c.analytics, ads: c.ads, marketing: c.marketing } });
      flush();
    }
  };
  const pending = ((window as any).gildana?.q ?? []) as [string, unknown?, unknown?][];
  (window as any).gildana = api;
  pending.forEach((a) => api(...a));

  addEventListener("visibilitychange", () => { if (!visible()) { leave(); flush(true); } });
  addEventListener("pagehide", () => { leave(); flush(true); });

  pageview();

  // ── session replay (sampled) ──
  const replayRate = Number(script.dataset.replay ?? "0");
  const sampled = (() => { try { return store(sessionStorage, "gi_rr", () => (Math.random() * 100 < replayRate ? "1" : "0")) === "1"; } catch { return false; } })();
  if (replayRate > 0 && sampled) {
    const s = document.createElement("script");
    s.src = `${API}/vendor/rrweb-record.min.js`; // self-hosted: no third-party request from the client's site
    s.async = true;
    s.onload = () => {
      const record = (window as any).rrwebRecord;
      if (typeof record !== "function") return;
      let buf: unknown[] = [];
      let seq = Number(sessionStorage.getItem("gi_rseq") ?? "0");
      record({ emit: (e: unknown) => buf.push(e), maskAllInputs: true, sampling: { mousemove: 50, scroll: 150, input: "last" } });
      const send = (beacon = false) => {
        if (!buf.length) return;
        const body = JSON.stringify({ k: KEY, s: sessionId, q: seq++, ev: buf });
        buf = [];
        try { sessionStorage.setItem("gi_rseq", String(seq)); } catch { /* ignore */ }
        if (beacon && navigator.sendBeacon) navigator.sendBeacon(`${API}/api/collect/replay`, new Blob([body], { type: "text/plain" }));
        else fetch(`${API}/api/collect/replay`, { method: "POST", body, keepalive: body.length < 60000, headers: { "content-type": "text/plain" } }).catch(() => {});
      };
      setInterval(send, 10_000);
      addEventListener("pagehide", () => send(true));
    };
    document.head.appendChild(s);
  }
})();
