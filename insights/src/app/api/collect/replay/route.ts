import { gzipSync } from "node:zlib";
import { z } from "zod";
import { db } from "@/lib/db";
import { originAllowed, siteByKey } from "@/lib/track";

const CORS = { "access-control-allow-origin": "*", "access-control-allow-methods": "POST, OPTIONS", "access-control-allow-headers": "content-type" };
const schema = z.object({ k: z.string().max(64), s: z.string().max(64), q: z.number().int().min(0).max(100_000), ev: z.array(z.unknown()).max(20_000) });

export function OPTIONS() {
  return new Response(null, { status: 204, headers: CORS });
}

/** Session-replay chunks (rrweb events), stored gzipped. Capped at 2 MB per chunk. */
export async function POST(req: Request) {
  const raw = await req.text();
  if (raw.length > 2_000_000) return new Response(null, { status: 413, headers: CORS });
  const p = schema.safeParse((() => { try { return JSON.parse(raw); } catch { return null; } })());
  if (!p.success) return new Response(null, { status: 400, headers: CORS });
  const site = await siteByKey(p.data.k);
  if (!site || !originAllowed(req.headers.get("origin") || req.headers.get("referer") || "", site.domain)) return new Response(null, { status: 403, headers: CORS });

  const sessionId = `${site.id}:${p.data.s}`;
  // The session row is written by the first page-view beacon; a chunk that races ahead of it is dropped.
  const exists = await db.trackSession.findUnique({ where: { id: sessionId }, select: { id: true } });
  if (exists) {
    await db.trackReplay.upsert({
      where: { sessionId_seq: { sessionId, seq: p.data.q } },
      create: { sessionId, seq: p.data.q, ts: new Date(), data: new Uint8Array(gzipSync(JSON.stringify(p.data.ev))) },
      update: {},
    });
    await db.trackSession.update({ where: { id: sessionId }, data: { hasReplay: true } });
  }
  return new Response(null, { status: 204, headers: CORS });
}
