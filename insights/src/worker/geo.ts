import { chromium } from "playwright-core";
import { db } from "@/lib/db";
import { askClaude, claudeEnabled } from "@/lib/claude";
import { fetchPage, fetchText } from "@/lib/fetch-page";
import { geoChecks, geoScore, readAnswer } from "@/lib/geo";
import { analyzeHtml, type PageData } from "@/lib/seo";
import { siteStart } from "@/lib/site-origin";

/** GEO audit: home page plus up to 12 linked pages, the access files, an optional rendered-vs-raw check and a Claude review. */
export async function runGeoAudit(auditId: string) {
  const audit = await db.geoAudit.findUniqueOrThrow({ where: { id: auditId }, include: { site: { include: { company: true } } } });
  const site = audit.site;
  const { origin, start } = siteStart(site);

  const [robots, llms, sitemap] = await Promise.all([fetchText(`${origin}/robots.txt`), fetchText(`${origin}/llms.txt`), fetchText(`${origin}/sitemap.xml`)]);
  const first = await fetchPage(start);
  const home = analyzeHtml(first.html, first.finalUrl, { status: first.status, redirectTo: first.redirectTo, ms: first.ms, bytes: first.bytes });
  const pages: PageData[] = [home];
  for (const link of home.links.filter((l) => l.startsWith(origin) && l.replace(/\/$/, "") !== start.replace(/\/$/, "")).slice(0, 12)) {
    const r = await fetchPage(link);
    if (r.status && /html/.test(r.ctype)) pages.push(analyzeHtml(r.html, r.finalUrl, { status: r.status, redirectTo: r.redirectTo, ms: r.ms, bytes: r.bytes }));
  }

  const checks = geoChecks({
    pages, robotsTxt: robots.status === 200 ? robots.text : "", llmsTxt: llms, sitemapOk: sitemap.status === 200 && sitemap.text.includes("<loc>"),
    brand: site.company.name.split(/\s+/)[0] ?? site.name, renderedWords: await renderedWordCount(start),
  });

  let aiReview = "";
  if (claudeEnabled()) {
    const sample = pages.slice(0, 6).map((p) => `URL: ${p.url}\nTitle: ${p.title}\nH1: ${p.h1}\nDescription: ${p.description}\nSchema: ${p.schemaTypes.join(", ") || "none"}\nText: ${p.text.slice(0, 2500)}`).join("\n\n---\n\n");
    const failing = checks.filter((c) => c.status !== "pass").map((c) => `- ${c.label}: ${c.detail}`).join("\n");
    try {
      const r = await askClaude({
        system: "You are a generative-engine-optimisation (GEO) consultant. You review websites for how well AI assistants can understand, trust and cite them. Be specific to the pages you are given; quote them. Write for a marketing manager.",
        prompt: `Company: ${site.company.name}${site.company.industry ? ` (${site.company.industry})` : ""}\nSite: ${origin}\n\nAutomated checks that did not pass:\n${failing || "(none)"}\n\nPage content:\n${sample}\n\nWrite a GEO review in markdown with these sections:\n1. **How an AI assistant would describe this business today** (2–3 sentences, from the content alone)\n2. **Questions this site can already answer well** (bullets)\n3. **High-intent questions it cannot answer yet** (5–8 questions buyers ask assistants, with the page that should answer each)\n4. **Top 5 fixes, in priority order** (each: what to change, on which page, and why it helps AI citation)\n5. **Suggested llms.txt** (a short draft)`,
        effort: "medium",
      });
      aiReview = r.refused ? "" : r.text;
    } catch (e) {
      aiReview = `_AI review unavailable: ${(e as Error).message}_`;
    }
  }

  await db.geoAudit.update({ where: { id: auditId }, data: { status: "done", finishedAt: new Date(), score: geoScore(checks), checks, aiReview } });
}

/** Word count after JavaScript runs, when a headless browser is available; null otherwise. */
async function renderedWordCount(url: string): Promise<number | null> {
  let browser;
  try {
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ["--no-sandbox"] });
    const page = await browser.newPage();
    await page.route("**/t.js*", (r) => r.abort());
    await page.goto(url, { waitUntil: "networkidle", timeout: 30_000 });
    const text: string = await page.evaluate(() => document.body.innerText);
    return text.split(/\s+/).filter(Boolean).length;
  } catch {
    return null;
  } finally {
    await browser?.close();
  }
}

/** Asks Claude (with and without web search) each tracked question and records whether the brand shows up. */
export async function runVisibility(promptId: string) {
  const p = await db.aiPrompt.findUniqueOrThrow({ where: { id: promptId }, include: { site: true } });
  const system = "Answer the user's question the way you normally would for a consumer. When the question asks for options, providers or recommendations, name specific companies or brands in a numbered list.";
  const engines: { engine: string; webSearch: boolean }[] = [{ engine: "Claude (web search)", webSearch: true }, { engine: "Claude (model knowledge)", webSearch: false }];
  for (const e of engines) {
    const r = await askClaude({ system, prompt: p.prompt, webSearch: e.webSearch, effort: "low", maxTokens: 4000 });
    const read = readAnswer(r.text, p.brand, p.site.isDemo ? "" : p.site.domain, r.sources);
    await db.aiVisibility.create({
      data: { promptId, engine: e.engine, mentioned: read.mentioned, position: read.position, cited: read.cited, competitors: read.competitors.join(", "), answer: (r.refused ? "(declined)" : r.text).slice(0, 8000) },
    });
  }
}
