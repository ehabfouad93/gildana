import { describe, expect, it } from "vitest";
import { geoChecks, geoScore, readAnswer } from "@/lib/geo";
import { analyzeHtml, pageIssues, robotsAllows, seoScore, siteIssues } from "@/lib/seo";

const HTML = `<!doctype html><html lang="en"><head><title>Acme Homes — Apartments in Cairo</title>
<meta name="description" content="Acme Homes builds apartments in New Cairo with 8-year installment plans and 10% down payment.">
<meta name="viewport" content="width=device-width"><link rel="canonical" href="https://acme.test/">
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Acme"},{"@type":"FAQPage"}]}</script></head>
<body><h1>Acme Homes</h1><h2>How much is the down payment?</h2><ul><li>10%</li></ul><img src="a.png"><img src="b.png" alt="b">
<a href="/pricing">Pricing</a><a href="https://other.example/x">Source</a><script>var x = "not text";</script></body></html>`;

describe("seo analysis", () => {
  const p = analyzeHtml(HTML, "https://acme.test/", { status: 200, ms: 120, bytes: HTML.length });
  it("extracts on-page facts", () => {
    expect(p.title).toBe("Acme Homes — Apartments in Cairo");
    expect(p.h1Count).toBe(1);
    expect(p.schemaTypes).toEqual(expect.arrayContaining(["Organization", "FAQPage"]));
    expect(p.imagesNoAlt).toBe(1);
    expect(p.links).toEqual(["https://acme.test/pricing"]);
    expect(p.outbound).toEqual(["other.example"]);
    expect(p.questionHeadings).toBe(1);
    expect(p.text).not.toContain("not text");
  });
  it("flags issues and scores", () => {
    const codes = pageIssues(p).map((i) => i.code);
    expect(codes).toEqual(expect.arrayContaining(["img_alt", "thin", "few_links", "no_og"]));
    expect(codes).not.toContain("no_title");
    expect(pageIssues({ ...p, status: 404 })[0].code).toBe("http_4xx");
    expect(seoScore(1, [])).toBe(100);
    expect(seoScore(1, [{ code: "x", severity: "error" }])).toBeLessThan(seoScore(1, [{ code: "x", severity: "notice" }]));
  });
  it("finds duplicates across pages", () => {
    const dup = siteIssues([p, { ...p, url: "https://acme.test/b" }]);
    expect(dup.filter((d) => d.issue.code === "dup_title")).toHaveLength(2);
  });
});

describe("robots.txt", () => {
  const robots = "User-agent: *\nDisallow: /admin\nAllow: /admin/public\n\nUser-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n";
  it("applies the most specific group and longest rule", () => {
    expect(robotsAllows(robots, "ClaudeBot", "/")).toBe(true);
    expect(robotsAllows(robots, "ClaudeBot", "/admin/x")).toBe(false);
    expect(robotsAllows(robots, "ClaudeBot", "/admin/public/y")).toBe(true);
    expect(robotsAllows(robots, "GPTBot", "/")).toBe(false);
    expect(robotsAllows(robots, "CCBot", "/blog")).toBe(false);
    expect(robotsAllows("", "GPTBot", "/")).toBe(true);
  });
});

describe("geo", () => {
  const p = analyzeHtml(HTML, "https://acme.test/", { status: 200, ms: 120, bytes: HTML.length });
  it("fails when AI search bots are blocked and scores accordingly", () => {
    const open = geoChecks({ pages: [p], robotsTxt: "", llmsTxt: { status: 404, text: "" }, sitemapOk: true, brand: "Acme" });
    const shut = geoChecks({ pages: [p], robotsTxt: "User-agent: OAI-SearchBot\nDisallow: /", llmsTxt: { status: 404, text: "" }, sitemapOk: true, brand: "Acme" });
    expect(open.find((c) => c.id === "ai_bots")!.status).toBe("pass");
    expect(shut.find((c) => c.id === "ai_bots")!.status).toBe("fail");
    expect(geoScore(shut)).toBeLessThan(geoScore(open));
    expect(open.find((c) => c.id === "schema_org")!.status).toBe("pass");
  });
  it("reads an assistant answer for the brand", () => {
    const answer = "Good options:\n1. **Palm Hills** – large compounds\n2. **Acme Homes**: installments\n3. Mountain View (east)";
    expect(readAnswer(answer, "Acme Homes", "acme.test", ["https://www.acme.test/pricing"])).toEqual({ mentioned: true, position: 2, cited: true, competitors: ["Palm Hills", "Mountain View"] });
    expect(readAnswer("No list here", "Acme", "", []).mentioned).toBe(false);
  });
});
