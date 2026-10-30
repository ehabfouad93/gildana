import { chromium, type Browser } from "playwright-core";
import { db } from "@/lib/db";
import { getViewer } from "@/lib/tenancy";
import { siteForClient } from "@/lib/web";

const WIDTH: Record<string, number> = { desktop: 1440, tablet: 820, mobile: 390 };

async function access(id: string) {
  const v = await getViewer();
  return v?.clientId ? siteForClient(v.clientId, id) : null;
}

/** The stored screenshot behind a heatmap. */
export async function GET(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!(await access(id))) return new Response("Not found", { status: 404 });
  const u = new URL(req.url);
  const snap = await db.pageSnapshot.findUnique({ where: { siteId_path_device: { siteId: id, path: u.searchParams.get("path") ?? "/", device: u.searchParams.get("device") ?? "desktop" } } });
  if (!snap) return new Response("Not found", { status: 404 });
  return new Response(new Uint8Array(snap.image), { headers: { "content-type": "image/jpeg", "cache-control": "private, max-age=3600" } });
}

/**
 * Captures a full-page screenshot with headless Chromium (Playwright must be
 * installed on the server). Sites that block framing still get a heatmap
 * background this way.
 */
export async function POST(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const site = await access(id);
  if (!site) return new Response("Not found", { status: 404 });
  const { path = "/", device = "desktop" } = (await req.json().catch(() => ({}))) as { path?: string; device?: string };
  if (!path.startsWith("/") || !(device in WIDTH)) return Response.json({ error: "Bad path or device" }, { status: 400 });
  const origin = site.isDemo ? (process.env.APP_URL ?? "http://localhost:3000") : `https://${site.domain}`;

  let browser: Browser;
  try {
    // CHROMIUM_PATH points at any Chrome/Chromium binary; without it, Playwright's own download is used.
    browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ["--no-sandbox"] });
  } catch (e) {
    return Response.json({ error: `No headless browser available on this server — set CHROMIUM_PATH or run \`npx playwright-core install chromium\`. (${(e as Error).message.split("\n")[0]})` }, { status: 501 });
  }
  try {
    const page = await browser.newPage({ viewport: { width: WIDTH[device], height: 900 }, deviceScaleFactor: 1, isMobile: device === "mobile", userAgent: device === "mobile" ? "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 GildanaInsightsSnapshot" : "Mozilla/5.0 GildanaInsightsSnapshot" });
    // Block the tracker so taking a screenshot is not counted as a visit.
    await page.route("**/t.js*", (r) => r.abort());
    await page.goto(origin + path, { waitUntil: "networkidle", timeout: 45_000 });
    const image = new Uint8Array(await page.screenshot({ fullPage: true, type: "jpeg", quality: 70 }));
    const height: number = await page.evaluate(() => document.documentElement.scrollHeight);
    await db.pageSnapshot.upsert({
      where: { siteId_path_device: { siteId: id, path, device } },
      create: { siteId: id, path, device, width: WIDTH[device], height, image },
      update: { width: WIDTH[device], height, image, createdAt: new Date() },
    });
    return Response.json({ ok: true, height });
  } catch (e) {
    return Response.json({ error: (e as Error).message }, { status: 502 });
  } finally {
    await browser.close();
  }
}
