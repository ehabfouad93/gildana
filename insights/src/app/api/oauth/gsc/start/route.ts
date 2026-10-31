import { NextResponse } from "next/server";
import { randomToken } from "@/lib/crypto";
import { gscAuthUrl, gscConfigured } from "@/lib/gsc";
import { redirectUri, signState } from "@/lib/oauth";
import { canEdit, getViewer } from "@/lib/tenancy";
import { siteForClient } from "@/lib/web";
import { publicUrl } from "@/lib/app-url";

export async function GET(req: Request) {
  const siteId = new URL(req.url).searchParams.get("site") ?? "";
  const v = await getViewer();
  if (!v?.clientId || !canEdit(v) || !(await siteForClient(v.clientId, siteId)) || !gscConfigured()) return NextResponse.redirect(publicUrl(`/sites/${siteId}/seo?gsc=unavailable`, req));
  const nonce = randomToken(16);
  const res = NextResponse.redirect(gscAuthUrl(signState({ clientId: v.clientId, platform: `GSC:${siteId}`, nonce }), redirectUri("gsc")));
  res.cookies.set("gi_oauth", nonce, { httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", maxAge: 600, path: "/" });
  return res;
}
