import { parse, type HTMLElement } from "node-html-parser";

/**
 * SEO page analysis: pure functions over fetched HTML, unit-tested, used by
 * the crawler in src/worker/seo.ts.
 */

export type PageData = {
  url: string; status: number; redirectTo: string; ms: number; bytes: number;
  title: string; description: string; h1: string; h1Count: number; h2Count: number; canonical: string; robots: string; lang: string;
  wordCount: number; internalLinks: number; externalLinks: number; images: number; imagesNoAlt: number;
  schemaTypes: string[]; hreflang: string[]; ogTitle: string; ogImage: string; viewport: boolean; links: string[];
  text: string; questionHeadings: number; lists: number; tables: number; hasAuthor: boolean; dateModified: string; outbound: string[];
};

const clean = (s: string | undefined | null) => (s ?? "").replace(/\s+/g, " ").trim();

function schemaTypes(root: HTMLElement): string[] {
  const types = new Set<string>();
  const visit = (x: unknown) => {
    if (Array.isArray(x)) return x.forEach(visit);
    if (x && typeof x === "object") {
      const o = x as Record<string, unknown>;
      const t = o["@type"];
      if (typeof t === "string") types.add(t);
      if (Array.isArray(t)) t.forEach((v) => typeof v === "string" && types.add(v));
      Object.values(o).forEach(visit);
    }
  };
  for (const s of root.querySelectorAll('script[type="application/ld+json"]')) {
    try { visit(JSON.parse(s.text)); } catch { /* invalid JSON-LD is reported as an issue elsewhere */ }
  }
  for (const el of root.querySelectorAll("[itemtype]")) types.add((el.getAttribute("itemtype") ?? "").split("/").pop() ?? "");
  types.delete("");
  return [...types];
}

export function analyzeHtml(html: string, url: string, meta: { status: number; redirectTo?: string; ms: number; bytes: number }): PageData {
  const root = parse(html, { blockTextElements: { script: true, style: true, noscript: false } });
  const base = new URL(url);
  const head = (sel: string, attr = "content") => clean(root.querySelector(sel)?.getAttribute(attr));
  const h1s = root.querySelectorAll("h1");

  const links: string[] = [];
  const outbound: string[] = [];
  let internal = 0, external = 0;
  for (const a of root.querySelectorAll("a[href]")) {
    const href = a.getAttribute("href") ?? "";
    if (/^(mailto|tel|javascript|#)/i.test(href)) continue;
    try {
      const u = new URL(href, base);
      u.hash = "";
      if (u.hostname.replace(/^www\./, "") === base.hostname.replace(/^www\./, "")) { internal++; links.push(u.toString()); }
      else { external++; outbound.push(u.hostname); }
    } catch { /* malformed href */ }
  }
  const imgs = root.querySelectorAll("img");
  const body = root.querySelector("body") ?? root;
  for (const n of body.querySelectorAll("script,style,noscript,svg")) n.remove();
  const text = clean(body.text);
  const headings = root.querySelectorAll("h2,h3,h4").map((h) => clean(h.text));

  return {
    url, status: meta.status, redirectTo: meta.redirectTo ?? "", ms: meta.ms, bytes: meta.bytes,
    title: clean(root.querySelector("title")?.text), description: head('meta[name="description"]'),
    h1: clean(h1s[0]?.text), h1Count: h1s.length, h2Count: root.querySelectorAll("h2").length,
    canonical: head('link[rel="canonical"]', "href"), robots: head('meta[name="robots"]'), lang: clean(root.querySelector("html")?.getAttribute("lang")),
    wordCount: text ? text.split(" ").length : 0, internalLinks: internal, externalLinks: external,
    images: imgs.length, imagesNoAlt: imgs.filter((i) => !i.hasAttribute("alt")).length,
    schemaTypes: schemaTypes(root), hreflang: root.querySelectorAll('link[rel="alternate"][hreflang]').map((l) => l.getAttribute("hreflang") ?? ""),
    ogTitle: head('meta[property="og:title"]'), ogImage: head('meta[property="og:image"]'), viewport: !!root.querySelector('meta[name="viewport"]'),
    links: [...new Set(links)], text: text.slice(0, 20_000),
    questionHeadings: headings.filter((h) => /\?$|^(what|how|why|when|where|who|which|can|does|is|are|ما|كيف|لماذا|متى|أين|هل)\b/i.test(h)).length,
    lists: root.querySelectorAll("ul,ol").length, tables: root.querySelectorAll("table").length,
    hasAuthor: !!root.querySelector('meta[name="author"], [rel="author"], [itemprop="author"], .author, .byline') || /"author"\s*:/.test(html),
    dateModified: head('meta[property="article:modified_time"]') || (html.match(/"dateModified"\s*:\s*"([^"]+)"/)?.[1] ?? ""),
    outbound: [...new Set(outbound)],
  };
}

export type Issue = { code: string; severity: "error" | "warning" | "notice"; detail?: string };

export const ISSUE_LABEL: Record<string, string> = {
  http_4xx: "Page returns 4xx", http_5xx: "Page returns 5xx", redirect: "Internal link redirects", no_title: "Missing title",
  title_long: "Title longer than 60 characters", title_short: "Title shorter than 25 characters", dup_title: "Duplicate title",
  no_description: "Missing meta description", desc_long: "Meta description longer than 160 characters", desc_short: "Meta description shorter than 70 characters",
  dup_description: "Duplicate meta description", no_h1: "Missing H1", multi_h1: "More than one H1", no_canonical: "Missing canonical",
  canonical_other: "Canonical points to another URL", noindex: "Page is noindex", thin: "Thin content (< 300 words)", img_alt: "Images without alt text",
  slow: "Slow response (> 1.5 s)", heavy: "Large HTML (> 1 MB)", no_lang: "Missing html lang", no_schema: "No structured data", no_og: "Missing Open Graph tags",
  no_viewport: "Missing mobile viewport", few_links: "Few internal links (< 3)", orphan: "Orphan page (no internal links point to it)",
};

const SEVERITY_WEIGHT = { error: 6, warning: 2, notice: 0.5 } as const;

export function pageIssues(p: PageData): Issue[] {
  const out: Issue[] = [];
  if (p.status >= 500) return [{ code: "http_5xx", severity: "error", detail: String(p.status) }];
  if (p.status >= 400) return [{ code: "http_4xx", severity: "error", detail: String(p.status) }];
  if (p.redirectTo) return [{ code: "redirect", severity: "notice", detail: `→ ${p.redirectTo}` }];
  if (!p.title) out.push({ code: "no_title", severity: "error" });
  else if (p.title.length > 60) out.push({ code: "title_long", severity: "warning", detail: `${p.title.length} chars` });
  else if (p.title.length < 25) out.push({ code: "title_short", severity: "notice", detail: `${p.title.length} chars` });
  if (!p.description) out.push({ code: "no_description", severity: "warning" });
  else if (p.description.length > 160) out.push({ code: "desc_long", severity: "notice", detail: `${p.description.length} chars` });
  else if (p.description.length < 70) out.push({ code: "desc_short", severity: "notice", detail: `${p.description.length} chars` });
  if (!p.h1Count) out.push({ code: "no_h1", severity: "warning" });
  if (p.h1Count > 1) out.push({ code: "multi_h1", severity: "notice", detail: `${p.h1Count} H1s` });
  if (!p.canonical) out.push({ code: "no_canonical", severity: "notice" });
  else {
    try { if (new URL(p.canonical, p.url).toString().replace(/\/$/, "") !== p.url.replace(/\/$/, "")) out.push({ code: "canonical_other", severity: "notice", detail: p.canonical }); } catch { /* relative junk */ }
  }
  if (/noindex/i.test(p.robots)) out.push({ code: "noindex", severity: "warning" });
  if (p.wordCount < 300) out.push({ code: "thin", severity: "notice", detail: `${p.wordCount} words` });
  if (p.imagesNoAlt) out.push({ code: "img_alt", severity: "warning", detail: `${p.imagesNoAlt} of ${p.images}` });
  if (p.ms > 1500) out.push({ code: "slow", severity: "warning", detail: `${p.ms} ms` });
  if (p.bytes > 1_000_000) out.push({ code: "heavy", severity: "warning", detail: `${Math.round(p.bytes / 1024)} KB` });
  if (!p.lang) out.push({ code: "no_lang", severity: "notice" });
  if (!p.schemaTypes.length) out.push({ code: "no_schema", severity: "notice" });
  if (!p.ogTitle) out.push({ code: "no_og", severity: "notice" });
  if (!p.viewport) out.push({ code: "no_viewport", severity: "error" });
  if (p.internalLinks < 3) out.push({ code: "few_links", severity: "notice", detail: `${p.internalLinks} links` });
  return out;
}

/** Cross-page issues: duplicate titles/descriptions and orphans. */
export function siteIssues(pages: PageData[]): { url: string; issue: Issue }[] {
  const out: { url: string; issue: Issue }[] = [];
  const ok = pages.filter((p) => p.status < 300 && !p.redirectTo);
  for (const field of ["title", "description"] as const) {
    const by = new Map<string, string[]>();
    for (const p of ok) if (p[field]) by.set(p[field], [...(by.get(p[field]) ?? []), p.url]);
    for (const [v, urls] of by) if (urls.length > 1) for (const u of urls) out.push({ url: u, issue: { code: field === "title" ? "dup_title" : "dup_description", severity: "warning", detail: `${urls.length} pages: “${v.slice(0, 60)}”` } });
  }
  const linked = new Set(pages.flatMap((p) => p.links.map((l) => l.replace(/\/$/, ""))));
  for (const p of ok.slice(1)) if (!linked.has(p.url.replace(/\/$/, ""))) out.push({ url: p.url, issue: { code: "orphan", severity: "notice" } });
  return out;
}

/** 0–100: the share of pages without problems, weighted by severity. */
export function seoScore(pageCount: number, issues: Issue[]): number {
  if (!pageCount) return 0;
  const penalty = issues.reduce((a, i) => a + SEVERITY_WEIGHT[i.severity], 0) / pageCount;
  return Math.max(0, Math.min(100, Math.round(100 - penalty * 6)));
}

/** Minimal robots.txt reader: does `agent` (or *) disallow `path`? Longest matching rule wins. */
export function robotsAllows(robots: string, agent: string, path: string): boolean {
  const groups: { agents: string[]; rules: { allow: boolean; path: string }[] }[] = [];
  let cur: (typeof groups)[number] | null = null;
  let lastWasAgent = false;
  for (const raw of robots.split(/\r?\n/)) {
    const line = raw.replace(/#.*/, "").trim();
    const m = line.match(/^([a-z-]+)\s*:\s*(.*)$/i);
    if (!m) continue;
    const [, k, v] = m;
    if (/^user-agent$/i.test(k)) {
      if (!cur || !lastWasAgent) { cur = { agents: [], rules: [] }; groups.push(cur); }
      cur.agents.push(v.toLowerCase());
      lastWasAgent = true;
    } else if (cur && /^(allow|disallow)$/i.test(k)) {
      if (v) cur.rules.push({ allow: /^allow$/i.test(k), path: v });
      lastWasAgent = false;
    }
  }
  const mine = groups.filter((g) => g.agents.includes(agent.toLowerCase()));
  const rules = (mine.length ? mine : groups.filter((g) => g.agents.includes("*"))).flatMap((g) => g.rules);
  let best: { allow: boolean; path: string } | null = null;
  for (const r of rules) {
    const re = new RegExp("^" + r.path.replace(/[.+?^${}()|[\]\\]/g, "\\$&").replace(/\*/g, ".*").replace(/\\\$$/, "$"));
    if (re.test(path) && (!best || r.path.length > best.path.length || (r.path.length === best.path.length && r.allow))) best = r;
  }
  return best ? best.allow : true;
}
