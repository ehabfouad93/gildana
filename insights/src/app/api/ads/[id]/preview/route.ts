import { db } from "@/lib/db";
import { decrypt } from "@/lib/crypto";
import { getViewer, scopedAccountIds } from "@/lib/tenancy";
import { meta } from "@/platforms/meta";

/** Live Meta ad preview (the platform's own rendering), proxied so the token never reaches the browser. */
export async function GET(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const v = await getViewer();
  if (!v?.clientId) return new Response("Unauthorized", { status: 401 });
  const ad = await db.ad.findUnique({ where: { id }, include: { account: { include: { connection: true } } } });
  if (!ad || !(await scopedAccountIds(v.clientId)).includes(ad.accountId)) return new Response("Not found", { status: 404 });
  if (ad.platform !== "META" || ad.account.connection.isDemo) return new Response("No live preview for this ad", { status: 404 });

  const format = new URL(req.url).searchParams.get("format") ?? "MOBILE_FEED_STANDARD";
  try {
    const html = await meta.preview(decrypt(ad.account.connection.tokenEnc), ad.externalId, format.replace(/[^A-Z_]/g, ""));
    return new Response(`<!doctype html><meta charset="utf-8"><body style="margin:0;display:flex;justify-content:center">${html}</body>`, {
      headers: { "content-type": "text/html; charset=utf-8", "cache-control": "private, max-age=300" },
    });
  } catch (e) {
    return new Response(`Preview unavailable: ${(e as Error).message}`, { status: 502 });
  }
}
