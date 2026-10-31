import type { Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { fetchPage, fetchText } from "@/lib/fetch-page";
import { analyzeHtml, pageIssues, robotsAllows, seoScore, siteIssues, type Issue, type PageData } from "@/lib/seo";
import { siteStart } from "@/lib/site-origin";
import { setting } from "@/lib/settings";

const SKIP = /\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4|mp3|css|js|ico|woff2?|xml|json)(\?|$)/i;

/** Breadth-first crawl of one site, bounded by the crawl's maxPages and robots.txt. */
export async function runCrawl(crawlId: string) {
  const crawl = await db.seoCrawl.findUniqueOrThrow({ where: { id: crawlId }, include: { site: true } });
  const { origin, start } = siteStart(crawl.site);
  const host = new URL(origin).host;

  const robots = await fetchText(`${origin}/robots.txt`);
  const robotsTxt = robots.status === 200 ? robots.text : "";
  const sitemapUrls = [...robotsTxt.matchAll(/^sitemap:\s*(\S+)/gim)].map((m) => m[1]);
  const sitemap = await fetchText(sitemapUrls[0] ?? `${origin}/sitemap.xml`);
  const sitemapLocs = sitemap.status === 200 ? [...sitemap.text.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/g)].map((m) => m[1]) : [];

  const queue: { url: string; depth: number }[] = [{ url: start, depth: 0 }, ...sitemapLocs.filter((u) => !/\.xml$/i.test(u)).slice(0, 50).map((url) => ({ url, depth: 1 }))];
  const seen = new Set(queue.map((q) => q.url.replace(/\/$/, "")));
  const pages: (PageData & { depth: number })[] = [];
  const blocked: string[] = [];

  while (queue.length && pages.length < crawl.maxPages) {
    const batch = queue.splice(0, 4);
    const results = await Promise.all(batch.map(async ({ url, depth }) => {
      const path = new URL(url).pathname;
      if (robotsTxt && !robotsAllows(robotsTxt, "GildanaInsightsBot", path)) { blocked.push(url); return null; }
      const r = await fetchPage(url);
      const data = analyzeHtml(r.html, r.finalUrl, { status: r.status, redirectTo: r.redirectTo, ms: r.ms, bytes: r.bytes });
      return { ...data, url, depth };
    }));
    for (const p of results) {
      if (!p) continue;
      pages.push(p);
      for (const l of p.links) {
        const u = new URL(l);
        const key = l.replace(/\/$/, "");
        if (u.host !== host || SKIP.test(u.pathname) || seen.has(key) || seen.size > crawl.maxPages * 4) continue;
        seen.add(key);
        queue.push({ url: l, depth: p.depth + 1 });
      }
    }
    await db.seoCrawl.update({ where: { id: crawlId }, data: { pagesDone: pages.length } });
  }

  const issues: { url: string; issue: Issue }[] = [...pages.flatMap((p) => pageIssues(p).map((issue) => ({ url: p.url, issue }))), ...siteIssues(pages)];
  const vitals = await pageSpeed(start);

  await db.$transaction([
    db.seoPage.deleteMany({ where: { crawlId } }),
    db.seoPage.createMany({ data: pages.map((p) => ({
      crawlId, url: p.url, status: p.status, redirectTo: p.redirectTo, ms: p.ms, bytes: p.bytes, title: p.title.slice(0, 500), description: p.description.slice(0, 1000),
      h1: p.h1.slice(0, 500), h1Count: p.h1Count, canonical: p.canonical, robots: p.robots, lang: p.lang, wordCount: p.wordCount, internalLinks: p.internalLinks,
      externalLinks: p.externalLinks, images: p.images, imagesNoAlt: p.imagesNoAlt, schemaTypes: p.schemaTypes.join(", "), hreflang: p.hreflang.join(", "), ogTitle: p.ogTitle, depth: p.depth,
    })) }),
    db.seoIssue.createMany({ data: issues.map(({ url, issue }) => ({ crawlId, url, code: issue.code, severity: issue.severity, detail: issue.detail ?? "" })) }),
    db.seoCrawl.update({
      where: { id: crawlId },
      data: {
        status: "done", finishedAt: new Date(), pagesDone: pages.length, score: seoScore(pages.length, issues.map((i) => i.issue)),
        summary: {
          https: origin.startsWith("https://"), robotsTxt: robots.status === 200, sitemap: sitemap.status === 200, sitemapUrls: sitemapLocs.length,
          blockedByRobots: blocked.length, avgMs: pages.length ? Math.round(pages.reduce((a, p) => a + p.ms, 0) / pages.length) : 0,
          vitals: vitals ?? null,
        } as Prisma.InputJsonValue,
      },
    }),
  ]);
}

/** Core Web Vitals and Lighthouse scores from the PageSpeed Insights API (mobile). Optional PAGESPEED_API_KEY raises the quota. */
async function pageSpeed(url: string) {
  if (/localhost|127\.0\.0\.1/.test(url)) return null; // PSI cannot reach local addresses
  try {
    const u = new URL("https://www.googleapis.com/pagespeedonline/v5/runPagespeed");
    u.searchParams.set("url", url);
    u.searchParams.set("strategy", "mobile");
    for (const c of ["performance", "seo", "accessibility", "best-practices"]) u.searchParams.append("category", c);
    if (setting("PAGESPEED_API_KEY")) u.searchParams.set("key", setting("PAGESPEED_API_KEY"));
    const r = await fetch(u, { signal: AbortSignal.timeout(90_000) });
    if (!r.ok) return null;
    const j: any = await r.json();
    const audits = j.lighthouseResult?.audits ?? {};
    const cats = j.lighthouseResult?.categories ?? {};
    const field = j.loadingExperience?.metrics ?? {};
    return {
      performance: Math.round((cats.performance?.score ?? 0) * 100), seo: Math.round((cats.seo?.score ?? 0) * 100),
      accessibility: Math.round((cats.accessibility?.score ?? 0) * 100), bestPractices: Math.round((cats["best-practices"]?.score ?? 0) * 100),
      lcp: audits["largest-contentful-paint"]?.displayValue ?? "", cls: audits["cumulative-layout-shift"]?.displayValue ?? "", tbt: audits["total-blocking-time"]?.displayValue ?? "",
      fieldLcpMs: field.LARGEST_CONTENTFUL_PAINT_MS?.percentile ?? null, fieldInpMs: field.INTERACTION_TO_NEXT_PAINT?.percentile ?? null, fieldCls: field.CUMULATIVE_LAYOUT_SHIFT_SCORE?.percentile ?? null,
    };
  } catch {
    return null;
  }
}
