import type { Prisma } from "@prisma/client";
import { db } from "@/lib/db";
import { addDays, ymd } from "@/lib/dates";
import { classifyChannel } from "@/lib/track";

/**
 * Synthetic visits for a demo site, laid out to match public/demo-site so
 * heatmap points land on the real buttons. Deterministic per site and day.
 */
function rng(seed: string) {
  let h = 2166136261;
  for (let i = 0; i < seed.length; i++) h = Math.imul(h ^ seed.charCodeAt(i), 16777619);
  return () => { h = Math.imul(h ^ (h >>> 15), 2246822507); h = Math.imul(h ^ (h >>> 13), 3266489909); h ^= h >>> 16; return (h >>> 0) / 4294967296; };
}

// Click targets measured on public/demo-site: [selector, text, desktop x%, y, mobile x%, y, weight].
// Desktop document is 1440×2106, mobile 390×2854; a mobile y of 0 means hidden on mobile.
const HOTSPOTS: [string, string, number, number, number, number, number][] = [
  ["#cta-visit", "Book a visit", 11, 477, 25, 463, 30], ["#cta-brochure", "Download brochure", 24, 477, 36, 513, 14], ["#nav-contact", "Contact", 92, 36, 0, 0, 6],
  ["header > nav > a:nth-of-type(2)", "Pricing", 83, 36, 0, 0, 8], ["div.card:nth-of-type(1)", "2-bed apartment", 20, 925, 50, 925, 9], ["div.card:nth-of-type(2)", "3-bed apartment", 50, 925, 50, 1269, 7],
  ["div.card:nth-of-type(3)", "Townhouse", 80, 925, 50, 1613, 5], ["div.card:nth-of-type(1) > div.img", "", 20, 870, 50, 870, 6], ["#faq > h3:nth-of-type(1)", "What is the minimum down payment?", 26, 1297, 50, 1985, 4],
  ["#form-submit", "Send", 24, 1859, 50, 2607, 10], ["#contact > form > input:nth-of-type(1)", "", 24, 1746, 50, 2494, 8], ["header > b", "Nile Heights", 9, 36, 18, 36, 3],
];
const DOC = { desktop: [1440, 2106], tablet: [820, 2400], mobile: [390, 2854] } as const;
const SOURCES: [string, string, string, string, number][] = [
  // utm_source, utm_medium, referrer host, click id, weight
  ["facebook", "paid_social", "", "fbclid", 26], ["google", "cpc", "", "gclid", 18], ["", "", "google.com", "", 16], ["", "", "", "", 14],
  ["tiktok", "paid_social", "", "ttclid", 8], ["", "", "instagram.com", "", 6], ["linkedin", "paid_social", "", "li_fat_id", 4], ["", "", "chatgpt.com", "", 3],
  ["newsletter", "email", "", "", 3], ["", "", "propertyfinder.eg", "", 2],
];
const pickW = <T extends unknown[]>(r: () => number, xs: readonly T[], wi: number): T => {
  const total = xs.reduce((a, x) => a + (x[wi] as number), 0);
  let n = r() * total;
  for (const x of xs) if ((n -= x[wi] as number) <= 0) return x;
  return xs[0];
};

export async function generateDemoTraffic(siteId: string, days = 30) {
  const site = await db.site.findUniqueOrThrow({ where: { id: siteId }, include: { company: { include: { accounts: { include: { campaigns: { select: { name: true } } } } } } } });
  const campaignNames = site.company.accounts.flatMap((a) => a.campaigns.map((c) => c.name)).slice(0, 8);
  const today = ymd(new Date());
  await db.trackSession.deleteMany({ where: { siteId } });
  if (!(await db.goal.count({ where: { siteId } }))) {
    await db.goal.createMany({ data: [{ siteId, name: "Lead form sent", kind: "path", match: "/demo-site/thank-you" }, { siteId, name: "Brochure click", kind: "event", match: "brochure" }] });
  }

  for (let d = days; d >= 1; d--) {
    const day = addDays(today, -d);
    const r = rng(`${siteId}:${day}`);
    const n = 40 + Math.floor(r() * 40);
    const sessions: Prisma.TrackSessionCreateManyInput[] = [], views: Prisma.TrackPageviewCreateManyInput[] = [], events: Prisma.TrackEventCreateManyInput[] = [];
    for (let i = 0; i < n; i++) {
      const sid = `${siteId}:demo-${day}-${i}`;
      const [src, med, ref, clickId] = pickW(r, SOURCES, 4);
      const device: keyof typeof DOC = r() < 0.6 ? "mobile" : r() < 0.85 ? "desktop" : "tablet";
      const start = new Date(`${day}T${String(7 + Math.floor(r() * 16)).padStart(2, "0")}:${String(Math.floor(r() * 60)).padStart(2, "0")}:00Z`);
      const bounced = r() < 0.42;
      const paths = bounced ? ["/demo-site"] : ["/demo-site", ...(r() < 0.5 ? ["/demo-site/pricing.html"] : []), ...(r() < 0.35 ? ["/demo-site/thank-you.html"] : [])];
      const converted = paths.includes("/demo-site/thank-you.html");
      let t = start.getTime();
      let maxScroll = 0;
      paths.forEach((p, k) => {
        const scroll = bounced ? Math.round(15 + r() * 40) : Math.round(35 + r() * 65);
        const active = bounced ? Math.round(3 + r() * 12) : Math.round(10 + r() * 120);
        maxScroll = Math.max(maxScroll, scroll);
        views.push({ sessionId: sid, siteId, path: p, title: p.includes("pricing") ? "Pricing — Nile Heights" : p.includes("thank") ? "Thank you — Nile Heights" : "Nile Heights — Homes in New Cairo", ts: new Date(t), activeSec: active, scrollPct: scroll, viewportW: DOC[device][0], viewportH: device === "mobile" ? 844 : 900, docH: DOC[device][1], device, pvKey: `${i}-${k}` });
        if (!p.includes("thank")) {
          const clicks = bounced ? Math.floor(r() * 2) : 1 + Math.floor(r() * 4);
          for (let c = 0; c < clicks; c++) {
            const [sel, text, dx, dy, mx, my] = pickW(r, HOTSPOTS, 6);
            const mobile = device === "mobile";
            if (mobile && !my) continue; // hidden on mobile
            const x = mobile ? mx : dx, yy = mobile ? my : dy;
            const [docW, docH] = DOC[device];
            if ((yy / docH) * 100 > scroll + 20) continue; // can't click what was never on screen
            const jitter = () => (r() - 0.5) * 2;
            const type = r() < 0.04 ? "rage_click" : sel.includes("img") && r() < 0.6 ? "dead_click" : "click";
            events.push({ sessionId: sid, siteId, path: p, type: "click", ts: new Date(t + 2000 + c * 4000), device, xPct: Math.max(0, Math.min(100, x + jitter() * 3)), yPx: Math.round(yy + jitter() * 12), docW, docH, selector: sel, text });
            if (type !== "click") events.push({ sessionId: sid, siteId, path: p, type, ts: new Date(t + 2100 + c * 4000), device, xPct: x, yPx: yy, docW, docH, selector: sel, text });
            if (sel === "#cta-brochure") events.push({ sessionId: sid, siteId, path: p, type: "custom", name: "brochure", ts: new Date(t + 2200 + c * 4000), device, selector: "", text: "" });
          }
          if (!bounced && r() < 0.5) events.push({ sessionId: sid, siteId, path: p, type: "form_start", name: "lead-form", ts: new Date(t + 9000), device, selector: "#lead-form", text: "" });
          if (converted && k === paths.length - 2) events.push({ sessionId: sid, siteId, path: p, type: "form_submit", name: "lead-form", ts: new Date(t + 15000), device, selector: "#lead-form", text: "" });
        }
        t += active * 1000;
      });
      const campaign = src && campaignNames.length ? campaignNames[Math.floor(r() * campaignNames.length)] : "";
      const country = r() < 0.7 ? "EG" : r() < 0.6 ? "SA" : r() < 0.6 ? "AE" : "KW";
      sessions.push({
        id: sid, siteId, visitorId: `v-${Math.floor(r() * n * days * 0.7)}`, isNew: r() < 0.72, startedAt: start, lastSeenAt: new Date(t),
        landingPath: "/demo-site", exitPath: paths[paths.length - 1], referrer: ref ? `https://${ref}/` : "", refHost: ref,
        utmSource: src, utmMedium: med, utmCampaign: campaign, clickIdType: clickId,
        channel: classifyChannel({ utmSource: src, utmMedium: med, refHost: ref, clickIdType: clickId }),
        device, browser: device === "mobile" ? (r() < 0.5 ? "Chrome" : "Facebook/Instagram app") : "Chrome", os: device === "mobile" ? (r() < 0.6 ? "Android" : "iOS") : "Windows",
        country, city: country === "EG" ? (r() < 0.6 ? "Cairo" : "Giza") : "", pageviews: paths.length, events: 0,
        durationSec: Math.round((t - start.getTime()) / 1000), maxScroll, converted,
      });
    }
    await db.trackSession.createMany({ data: sessions });
    await db.trackPageview.createMany({ data: views });
    await db.trackEvent.createMany({ data: events });
  }
}
