import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { db } from "@/lib/db";
import { encrypt } from "@/lib/crypto";
import { gscPickProperty, gscToken } from "@/lib/gsc";
import { redirectUri, verifyState } from "@/lib/oauth";
import { getViewer } from "@/lib/tenancy";
import { siteForClient } from "@/lib/web";
import { publicUrl } from "@/lib/app-url";

export async function GET(req: Request) {
  const url = new URL(req.url);
  const st = verifyState(url.searchParams.get("state") ?? "");
  const jar = await cookies();
  const nonce = jar.get("gi_oauth")?.value;
  jar.delete("gi_oauth");
  const v = await getViewer();
  const siteId = st?.platform.startsWith("GSC:") ? st.platform.slice(4) : "";
  const site = v?.clientId && st && st.nonce === nonce && st.clientId === v.clientId ? await siteForClient(v.clientId, siteId) : null;
  if (!site) return NextResponse.redirect(publicUrl("/sites", req));
  try {
    const t = await gscToken({ grant_type: "authorization_code", code: url.searchParams.get("code") ?? "", redirect_uri: redirectUri("gsc") });
    const property = await gscPickProperty(t.access_token, site.domain);
    await db.site.update({ where: { id: site.id }, data: { gscProperty: property, ...(t.refresh_token ? { gscRefreshEnc: encrypt(t.refresh_token) } : {}) } });
    return NextResponse.redirect(publicUrl(`/sites/${site.id}/seo?gsc=${property ? "connected" : "no-property"}`, req));
  } catch (e) {
    return NextResponse.redirect(publicUrl(`/sites/${site.id}/seo?gsc=${encodeURIComponent((e as Error).message.slice(0, 120))}`, req));
  }
}
