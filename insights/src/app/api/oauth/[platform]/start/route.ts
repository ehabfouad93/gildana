import { NextResponse } from "next/server";
import { publicUrl } from "@/lib/app-url";
import { randomToken } from "@/lib/crypto";
import { AGENCY, redirectUri, safeNext, signState } from "@/lib/oauth";
import { platformFromSlug } from "@/lib/platforms";
import { canEdit, getViewer } from "@/lib/tenancy";
import { demoEnabled, liveConnector } from "@/platforms";

/**
 * Starts a platform login. `?agency=1` (admins only) connects Gildana's own
 * login, which serves accounts clients share with Gildana. Without app
 * credentials it falls back to a demo login when demo mode is on.
 */
export async function GET(req: Request, { params }: { params: Promise<{ platform: string }> }) {
  const { platform: slug } = await params;
  const q = new URL(req.url).searchParams;
  const platform = platformFromSlug(slug);
  const v = await getViewer();
  const agency = q.get("agency") === "1";
  const next = safeNext(q.get("next"), agency ? "/admin/platforms" : "/connections");
  const owner = agency ? (v?.isAdmin ? AGENCY : null) : v?.clientId && canEdit(v) ? v.clientId : null;
  if (!platform || !v || !owner) return NextResponse.redirect(publicUrl(next, req));

  const demo = q.get("demo") === "1";
  const write = q.get("write") === "1"; // Meta: also ask for ads_management (interest split tests)
  const live = liveConnector(platform);
  if (!live && !(demo && demoEnabled())) return NextResponse.redirect(publicUrl(`${next}${next.includes("?") ? "&" : "?"}error=${slug}-not-configured`, req));

  const nonce = randomToken(16);
  const state = signState({ clientId: owner, platform, nonce, next });
  const target = live && !demo
    ? live.authUrl(state, redirectUri(slug), { write })
    : publicUrl(`/api/oauth/${slug}/callback?demo=1&code=demo-${nonce.slice(0, 8)}&state=${encodeURIComponent(state)}`, req).toString();

  const res = NextResponse.redirect(target);
  res.cookies.set("gi_oauth", nonce, { httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", maxAge: 600, path: "/" });
  return res;
}
