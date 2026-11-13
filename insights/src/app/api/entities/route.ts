import { NextResponse } from "next/server";
import { db } from "@/lib/db";
import { platformFromSlug } from "@/lib/platforms";
import { getViewer, scopedAccountIds } from "@/lib/tenancy";

/**
 * Search for the campaign / ad set / ad filter. Only items in ad accounts the
 * signed-in user may see are returned (and inside the page's company,
 * platform and account filters when given).
 */
export async function GET(req: Request) {
  const v = await getViewer();
  if (!v?.clientId) return NextResponse.json({ items: [] }, { status: 401 });
  const u = new URL(req.url);
  const level = u.searchParams.get("level");
  const q = (u.searchParams.get("q") ?? "").trim().slice(0, 100);
  const company = u.searchParams.get("company") || undefined;
  const acc = (u.searchParams.get("acc") ?? "").split(",").filter(Boolean).slice(0, 500);
  const accountIds = await scopedAccountIds(v.clientId, {
    access: v.access, companyId: company, platform: platformFromSlug(u.searchParams.get("platform") ?? "") ?? undefined, accountIds: acc.length ? acc : undefined,
  });
  const name = q ? { name: { contains: q, mode: "insensitive" as const } } : {};
  const base = { accountId: { in: accountIds }, ...name };
  const take = 40;
  let items: { id: string; name: string; sub: string }[] = [];
  if (level === "ad") {
    const rows = await db.ad.findMany({ where: base, take, orderBy: { name: "asc" }, select: { id: true, name: true, status: true, adSet: { select: { name: true } }, campaign: { select: { name: true } } } });
    items = rows.map((r) => ({ id: r.id, name: r.name, sub: `${r.campaign.name} › ${r.adSet.name} · ${r.status.toLowerCase()}` }));
  } else if (level === "adset") {
    const rows = await db.adSet.findMany({ where: base, take, orderBy: { name: "asc" }, select: { id: true, name: true, status: true, campaign: { select: { name: true } } } });
    items = rows.map((r) => ({ id: r.id, name: r.name, sub: `${r.campaign.name} · ${r.status.toLowerCase()}` }));
  } else {
    const rows = await db.campaign.findMany({ where: base, take, orderBy: { name: "asc" }, select: { id: true, name: true, status: true, account: { select: { name: true } } } });
    items = rows.map((r) => ({ id: r.id, name: r.name, sub: `${r.account.name} · ${r.status.toLowerCase()}` }));
  }
  return NextResponse.json({ items });
}
