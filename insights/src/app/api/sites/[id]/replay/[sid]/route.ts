import { gunzipSync } from "node:zlib";
import { db } from "@/lib/db";
import { getViewer } from "@/lib/tenancy";
import { siteForClient } from "@/lib/web";

/** All rrweb events of one session, in order, for the player. */
export async function GET(_: Request, { params }: { params: Promise<{ id: string; sid: string }> }) {
  const { id, sid } = await params;
  const v = await getViewer();
  if (!v?.clientId || !(await siteForClient(v.clientId, id))) return new Response("Not found", { status: 404 });
  const chunks = await db.trackReplay.findMany({ where: { sessionId: `${id}:${sid}` }, orderBy: { seq: "asc" } });
  const events = chunks.flatMap((c) => { try { return JSON.parse(gunzipSync(c.data).toString()) as unknown[]; } catch { return []; } });
  return Response.json(events, { headers: { "cache-control": "private, max-age=60" } });
}
