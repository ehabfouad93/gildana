import "server-only";
import { createHash } from "node:crypto";
import { db } from "@/lib/db";
import { scopedAccountIds, type Viewer } from "@/lib/tenancy";

/** Cache key for "this user's accounts (+ one campaign)": limited users get their own answers. */
export async function adviceScope(v: Viewer & { clientId: string }, campaignId?: string) {
  const accountIds = await scopedAccountIds(v.clientId, { access: v.access, platform: "META" });
  const campaign = campaignId ? await db.campaign.findFirst({ where: { id: campaignId, accountId: { in: accountIds } }, select: { id: true, name: true } }) : null;
  const hash = createHash("sha1").update([...accountIds].sort().join(",")).digest("hex").slice(0, 12);
  return { accountIds, campaign, scopeKey: `${campaign?.id ?? "all"}:${hash}` };
}
