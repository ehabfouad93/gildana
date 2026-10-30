import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { db } from "@/lib/db";
import { encrypt } from "@/lib/crypto";
import { redirectUri, verifyState } from "@/lib/oauth";
import { platformFromSlug } from "@/lib/platforms";
import { getViewer } from "@/lib/tenancy";
import { connectorFor } from "@/platforms";

export async function GET(req: Request, { params }: { params: Promise<{ platform: string }> }) {
  const { platform: slug } = await params;
  const url = new URL(req.url);
  const back = (q: string) => NextResponse.redirect(new URL(`/connections?${q}`, req.url));

  const platform = platformFromSlug(slug);
  const st = verifyState(url.searchParams.get("state") ?? "");
  const jar = await cookies();
  const nonce = jar.get("gi_oauth")?.value;
  jar.delete("gi_oauth");
  const v = await getViewer();
  if (!platform || !st || st.platform !== platform || st.nonce !== nonce || !v || v.clientId !== st.clientId) return back("error=invalid-state");
  if (url.searchParams.get("error")) return back(`error=${encodeURIComponent(url.searchParams.get("error_description") ?? url.searchParams.get("error")!)}`);

  const isDemo = url.searchParams.get("demo") === "1";
  const connector = connectorFor(platform, isDemo);
  try {
    const tok = await connector.exchange(url.searchParams.get("code") ?? "", redirectUri(slug));
    const accounts = await connector.listAccounts(tok.accessToken);
    const conn = await db.adConnection.create({
      data: {
        clientId: st.clientId, userId: v.id, platform, isDemo, label: tok.label ?? "", externalUser: tok.externalUser ?? "",
        tokenEnc: isDemo ? null : encrypt(tok.accessToken), refreshEnc: tok.refreshToken ? encrypt(tok.refreshToken) : null,
        expiresAt: tok.expiresAt ?? null, scopes: tok.scopes ?? "",
      },
    });
    // Accounts start unassigned; nothing syncs until someone puts them in a company.
    await db.adAccount.createMany({
      skipDuplicates: true,
      data: accounts.map((a) => ({ connectionId: conn.id, platform, externalId: a.externalId, name: a.name, currency: a.currency, timezone: a.timezone })),
    });
    return back(`connected=${conn.id}`);
  } catch (e) {
    return back(`error=${encodeURIComponent((e as Error).message.slice(0, 200))}`);
  }
}
