import { NextResponse } from "next/server";
import { randomToken } from "@/lib/crypto";
import { redirectUri, signState } from "@/lib/oauth";
import { platformFromSlug } from "@/lib/platforms";
import { canEdit, getViewer } from "@/lib/tenancy";
import { demoEnabled, liveConnector } from "@/platforms";
import { publicUrl } from "@/lib/app-url";

/** Starts a platform login. Without app credentials, falls back to a demo login when demo mode is on. */
export async function GET(req: Request, { params }: { params: Promise<{ platform: string }> }) {
  const { platform: slug } = await params;
  const platform = platformFromSlug(slug);
  const v = await getViewer();
  if (!platform || !v?.clientId || !canEdit(v)) return NextResponse.redirect(publicUrl("/connections", req));

  const demo = new URL(req.url).searchParams.get("demo") === "1";
  const write = new URL(req.url).searchParams.get("write") === "1"; // Meta: also ask for ads_management (interest split tests)
  const live = liveConnector(platform);
  if (!live && !(demo || demoEnabled())) return NextResponse.redirect(publicUrl(`/connections?error=${slug}-not-configured`, req));

  const nonce = randomToken(16);
  const state = signState({ clientId: v.clientId, platform, nonce });
  const target = live && !demo
    ? live.authUrl(state, redirectUri(slug), { write })
    : publicUrl(`/api/oauth/${slug}/callback?demo=1&code=demo-${nonce.slice(0, 8)}&state=${encodeURIComponent(state)}`, req).toString();

  const res = NextResponse.redirect(target);
  res.cookies.set("gi_oauth", nonce, { httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", maxAge: 600, path: "/" });
  return res;
}
