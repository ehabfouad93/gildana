import { robotsAllows, type PageData } from "@/lib/seo";

/**
 * GEO — generative engine optimisation: how ready a site is to be read,
 * understood and cited by AI assistants (ChatGPT, Claude, Perplexity,
 * Gemini, Google AI Overviews). Pure checks; src/worker/geo.ts gathers the inputs.
 */

export const AI_BOTS: { agent: string; owner: string; purpose: string }[] = [
  { agent: "GPTBot", owner: "OpenAI", purpose: "training" },
  { agent: "OAI-SearchBot", owner: "OpenAI", purpose: "ChatGPT search" },
  { agent: "ChatGPT-User", owner: "OpenAI", purpose: "user-requested browsing" },
  { agent: "ClaudeBot", owner: "Anthropic", purpose: "training" },
  { agent: "Claude-SearchBot", owner: "Anthropic", purpose: "Claude search" },
  { agent: "Claude-User", owner: "Anthropic", purpose: "user-requested browsing" },
  { agent: "PerplexityBot", owner: "Perplexity", purpose: "search index" },
  { agent: "Google-Extended", owner: "Google", purpose: "Gemini / AI training" },
  { agent: "Applebot-Extended", owner: "Apple", purpose: "Apple Intelligence" },
  { agent: "CCBot", owner: "Common Crawl", purpose: "open dataset used by many models" },
];

export type GeoCheck = { id: string; group: string; label: string; status: "pass" | "warn" | "fail"; weight: number; detail: string; fix: string };

export type GeoInput = {
  pages: PageData[];
  robotsTxt: string;
  llmsTxt: { status: number; text: string };
  sitemapOk: boolean;
  brand: string;
  renderedWords?: number | null; // words on the home page after JavaScript ran, if a browser was available
};

const pct = (n: number, d: number) => (d ? Math.round((n / d) * 100) : 0);

export function geoChecks(x: GeoInput): GeoCheck[] {
  const out: GeoCheck[] = [];
  const add = (c: GeoCheck) => out.push(c);
  const home = x.pages[0];
  const ok = x.pages.filter((p) => p.status < 300 && !p.redirectTo);
  const allTypes = new Set(ok.flatMap((p) => p.schemaTypes));

  // ── Access ──
  const blockedBots = AI_BOTS.filter((b) => !robotsAllows(x.robotsTxt, b.agent, "/"));
  const searchBlocked = blockedBots.filter((b) => /search|browsing/.test(b.purpose));
  add({
    id: "ai_bots", group: "Access", label: "AI crawlers allowed in robots.txt", weight: 15,
    status: searchBlocked.length ? "fail" : blockedBots.length ? "warn" : "pass",
    detail: blockedBots.length ? `Blocked: ${blockedBots.map((b) => `${b.agent} (${b.owner}, ${b.purpose})`).join(", ")}` : "All major AI crawlers may read the site.",
    fix: searchBlocked.length ? "Allow the search/browsing bots (OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot). Blocking them removes the site from AI answers; blocking only training bots is a business choice." : "",
  });
  add({
    id: "llms_txt", group: "Access", label: "llms.txt", weight: 5,
    status: x.llmsTxt.status === 200 && x.llmsTxt.text.trim().length > 50 ? "pass" : "warn",
    detail: x.llmsTxt.status === 200 ? `${x.llmsTxt.text.length} characters` : "No /llms.txt",
    fix: "Publish /llms.txt: a short markdown summary of the business and links to the key pages (services, pricing, FAQ, contact). Cheap, and read by several assistants.",
  });
  add({ id: "sitemap", group: "Access", label: "XML sitemap", weight: 4, status: x.sitemapOk ? "pass" : "warn", detail: x.sitemapOk ? "Found" : "No sitemap found", fix: "Publish /sitemap.xml with lastmod dates and reference it in robots.txt." });
  if (x.renderedWords != null && home) {
    const ratio = x.renderedWords ? home.wordCount / x.renderedWords : 1;
    add({
      id: "no_js", group: "Access", label: "Content readable without JavaScript", weight: 12,
      status: ratio > 0.7 ? "pass" : ratio > 0.3 ? "warn" : "fail",
      detail: `${home.wordCount} words in the raw HTML vs ${x.renderedWords} after JavaScript (${Math.round(ratio * 100)}%).`,
      fix: "Most AI crawlers do not run JavaScript. Render the main content on the server (SSR/SSG) so it is in the HTML.",
    });
  } else if (home) {
    add({ id: "no_js", group: "Access", label: "Content readable without JavaScript", weight: 12, status: home.wordCount > 150 ? "pass" : "fail", detail: `${home.wordCount} words in the raw HTML of the home page.`, fix: "Most AI crawlers do not run JavaScript. Render the main content on the server so it is in the HTML." });
  }

  // ── Structured data & entity ──
  const org = ["Organization", "LocalBusiness", "Corporation", "RealEstateAgent", "Store", "Restaurant", "MedicalBusiness", "ProfessionalService"].some((t) => allTypes.has(t));
  add({ id: "schema_org", group: "Entity", label: "Organization / LocalBusiness schema", weight: 10, status: org ? "pass" : "fail", detail: org ? "Present" : "Missing", fix: "Add Organization (or LocalBusiness) JSON-LD on the home page: name, logo, url, address, telephone, and sameAs links to official social profiles and Wikipedia/Wikidata if any." });
  add({ id: "schema_faq", group: "Entity", label: "FAQ / Q&A schema", weight: 5, status: allTypes.has("FAQPage") || allTypes.has("QAPage") ? "pass" : "warn", detail: allTypes.has("FAQPage") ? "FAQPage found" : "None", fix: "Mark up real FAQ sections with FAQPage JSON-LD." });
  const offer = ["Product", "Offer", "Service", "Course", "Event", "Article", "BlogPosting", "HowTo"].filter((t) => allTypes.has(t));
  add({ id: "schema_content", group: "Entity", label: "Content-type schema (Product, Service, Article…)", weight: 5, status: offer.length ? "pass" : "warn", detail: offer.length ? offer.join(", ") : "None", fix: "Describe what you sell or publish with Product/Offer, Service or Article schema so assistants can quote prices, features and dates." });
  const brandRe = new RegExp(x.brand.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i");
  const brandOn = x.brand ? [home?.title, home?.h1, home?.description].filter((s) => s && brandRe.test(s)).length : 0;
  add({ id: "entity_name", group: "Entity", label: "Brand name clear on the home page", weight: 6, status: brandOn >= 2 ? "pass" : brandOn === 1 ? "warn" : "fail", detail: `Brand found in ${brandOn} of title, H1 and description.`, fix: "State the brand name and what it is in the title, the H1 and the first sentence (\"<Brand> is a … in …\")." });
  const paths = ok.map((p) => new URL(p.url).pathname.toLowerCase());
  const about = paths.some((p) => /about|who-we-are|company|من-نحن/.test(p)), contact = paths.some((p) => /contact|اتصل/.test(p)) || ok.some((p) => /\+?\d[\d\s-]{7,}/.test(p.text));
  add({ id: "about_contact", group: "Entity", label: "About and contact information", weight: 5, status: about && contact ? "pass" : about || contact ? "warn" : "fail", detail: `${about ? "About page found" : "No about page"} · ${contact ? "contact details found" : "no contact details"}`, fix: "Have a crawlable About page (who, since when, where, credentials) and visible contact details/address." });

  // ── Answer-ready content ──
  const q = ok.reduce((a, p) => a + p.questionHeadings, 0);
  add({ id: "questions", group: "Content", label: "Question-style headings", weight: 8, status: q >= 5 ? "pass" : q >= 1 ? "warn" : "fail", detail: `${q} headings phrased as questions across ${ok.length} pages.`, fix: "Use the questions customers ask as H2/H3 headings and answer each in the first 1–2 sentences below it." });
  const structured = ok.filter((p) => p.lists + p.tables > 0).length;
  add({ id: "structure", group: "Content", label: "Lists and tables", weight: 5, status: pct(structured, ok.length) >= 50 ? "pass" : structured ? "warn" : "fail", detail: `${structured} of ${ok.length} pages use lists or tables.`, fix: "Put specs, prices, steps and comparisons in lists and tables — assistants extract them verbatim." });
  const deep = ok.filter((p) => p.wordCount >= 500).length;
  add({ id: "depth", group: "Content", label: "Substantial pages", weight: 6, status: pct(deep, ok.length) >= 40 ? "pass" : deep ? "warn" : "fail", detail: `${deep} of ${ok.length} pages have 500+ words.`, fix: "Thin pages are rarely cited. Expand key service/product pages with specifics: numbers, locations, process, prices, FAQs." });
  const facts = ok.filter((p) => /\d/.test(p.text) && /(%|egp|sar|aed|usd|\$|m²|sqm|years?|سنة|جنيه)/i.test(p.text)).length;
  add({ id: "facts", group: "Content", label: "Concrete facts and numbers", weight: 4, status: pct(facts, ok.length) >= 50 ? "pass" : facts ? "warn" : "fail", detail: `${facts} of ${ok.length} pages state concrete figures.`, fix: "Assistants prefer quotable specifics (prices, sizes, dates, counts) over adjectives." });

  // ── Trust ──
  const authored = ok.filter((p) => p.hasAuthor).length, dated = ok.filter((p) => p.dateModified).length;
  add({ id: "authorship", group: "Trust", label: "Authors and update dates", weight: 4, status: authored || dated ? "pass" : "warn", detail: `${authored} pages name an author · ${dated} show a modified date.`, fix: "Show who wrote expert content and when it was last updated (dateModified in Article schema)." });
  const cites = ok.filter((p) => p.outbound.length > 0).length;
  add({ id: "citations", group: "Trust", label: "Links to sources", weight: 3, status: cites ? "pass" : "warn", detail: `${cites} of ${ok.length} pages link out.`, fix: "Cite authoritative sources (regulators, studies, partners) where you make claims." });
  const meta = ok.filter((p) => p.title && p.description).length;
  add({ id: "meta", group: "Trust", label: "Titles and descriptions", weight: 3, status: pct(meta, ok.length) >= 90 ? "pass" : "warn", detail: `${meta} of ${ok.length} pages have both.`, fix: "Write a specific title and description for every page — they are the summary assistants see first." });
  return out;
}

export function geoScore(checks: GeoCheck[]): number {
  const total = checks.reduce((a, c) => a + c.weight, 0);
  const got = checks.reduce((a, c) => a + (c.status === "pass" ? c.weight : c.status === "warn" ? c.weight * 0.5 : 0), 0);
  return total ? Math.round((got / total) * 100) : 0;
}

/**
 * Reads an assistant's answer for the brand: mentioned at all, its rank in
 * the first list of options, whether its domain was a cited source, and the
 * other names in that list.
 */
export function readAnswer(answer: string, brand: string, domain: string, sources: string[]) {
  const norm = (s: string) => s.toLowerCase();
  const b = norm(brand), d = norm(domain).replace(/^www\./, "");
  const mentioned = !!b && (norm(answer).includes(b) || (!!d && norm(answer).includes(d)));
  const items = answer.split(/\n/).map((l) => l.trim()).filter((l) => /^(\d+[.)]|[-*•])\s+/.test(l)).map((l) => l.replace(/^(\d+[.)]|[-*•])\s+/, "").replace(/\*\*/g, ""));
  const idx = items.findIndex((l) => norm(l).includes(b) || (!!d && norm(l).includes(d)));
  const names = items.map((l) => l.split(/\s[–—-]\s|:|\(|,/)[0].trim()).filter((n) => n && n.length < 60 && !norm(n).includes(b));
  const cited = !!d && sources.some((s) => { try { const h = new URL(s).hostname.replace(/^www\./, ""); return h === d || h.endsWith("." + d); } catch { return false; } });
  return { mentioned, position: idx >= 0 ? idx + 1 : null, cited, competitors: [...new Set(names)].slice(0, 8) };
}
