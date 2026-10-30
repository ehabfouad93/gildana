import { ingest, originAllowed, payloadSchema, siteByKey } from "@/lib/track";

const CORS = { "access-control-allow-origin": "*", "access-control-allow-methods": "POST, OPTIONS", "access-control-allow-headers": "content-type" };

// Per-IP budget: 120 beacons a minute is far above any real visitor.
const hits = new Map<string, { n: number; reset: number }>();
function limited(ip: string): boolean {
  const now = Date.now();
  const h = hits.get(ip);
  if (!h || h.reset < now) { hits.set(ip, { n: 1, reset: now + 60_000 }); if (hits.size > 50_000) hits.clear(); return false; }
  return ++h.n > 120;
}

export function OPTIONS() {
  return new Response(null, { status: 204, headers: CORS });
}

/** Beacon endpoint for t.js. Always answers 204 so a broken site never shows errors to its visitors. */
export async function POST(req: Request) {
  const ip = (req.headers.get("x-forwarded-for") ?? "").split(",")[0].trim() || "local";
  if (limited(ip)) return new Response(null, { status: 429, headers: CORS });
  let body: unknown;
  try { body = JSON.parse(await req.text()); } catch { return new Response(null, { status: 400, headers: CORS }); }
  const parsed = payloadSchema.safeParse(body);
  if (!parsed.success) return new Response(null, { status: 400, headers: CORS });

  const site = await siteByKey(parsed.data.k);
  if (!site) return new Response(null, { status: 404, headers: CORS });
  const origin = req.headers.get("origin") || req.headers.get("referer") || "";
  if (!originAllowed(origin, site.domain)) return new Response(null, { status: 403, headers: CORS });

  try {
    await ingest(site.id, site.domain, parsed.data, {
      ua: req.headers.get("user-agent") ?? "",
      country: (req.headers.get("cf-ipcountry") ?? req.headers.get("x-vercel-ip-country") ?? req.headers.get("x-country-code") ?? "").slice(0, 2).toUpperCase(),
      city: decodeURIComponent(req.headers.get("x-vercel-ip-city") ?? req.headers.get("cf-ipcity") ?? "").slice(0, 80),
    });
  } catch (e) {
    console.error("[collect]", (e as Error).message);
  }
  return new Response(null, { status: 204, headers: CORS });
}
