import type { Site } from "@prisma/client";

/** Where to crawl a site from. The demo site lives inside this app at /demo-site. */
export function siteStart(site: Pick<Site, "domain" | "isDemo">): { origin: string; start: string } {
  if (site.isDemo) {
    const origin = new URL(process.env.APP_URL || "http://localhost:3000").origin;
    return { origin, start: `${origin}/demo-site` };
  }
  return { origin: `https://${site.domain}`, start: `https://${site.domain}/` };
}
