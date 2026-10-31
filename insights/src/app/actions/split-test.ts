"use server";

import type { Prisma } from "@prisma/client";
import { revalidatePath } from "next/cache";
import { db } from "@/lib/db";
import { decrypt } from "@/lib/crypto";
import { lastNDays, ymd } from "@/lib/dates";
import { enqueueSync } from "@/lib/jobs";
import { canEdit, requireWorkspace } from "@/lib/tenancy";
import { WRITE_SCOPE } from "@/platforms/meta";
import { createInterestSplit } from "@/platforms/meta/split-test";
import type { RemoteTargetingItem } from "@/platforms/types";

export type SplitResult = { ok: boolean; message: string };

/**
 * Splits a Meta ad set into one PAUSED ad set per selected interest. Demo
 * logins simulate it; real logins need the ads_management permission.
 */
export async function splitAdSetAction(adSetId: string, targetingIds: string[]): Promise<SplitResult> {
  const v = await requireWorkspace();
  if (!canEdit(v)) return { ok: false, message: "Read-only users cannot create split tests." };
  const set = await db.adSet.findFirst({
    where: { id: adSetId, account: { connection: { clientId: v.clientId } } },
    include: { targeting: true, account: { include: { connection: true } } },
  });
  if (!set || set.platform !== "META") return { ok: false, message: "Ad set not found." };
  const items = set.targeting.filter((t) => targetingIds.includes(t.id) && !t.exclude && (t.type === "interest" || t.type === "behavior"));
  if (items.length < 2) return { ok: false, message: "Pick at least two interests to split." };
  if (await db.interestTest.findFirst({ where: { sourceAdSetId: set.id, status: { not: "failed" } } })) return { ok: false, message: "This ad set already has a split test." };

  const conn = set.account.connection;
  const remote: RemoteTargetingItem[] = items.map((t) => ({ type: t.type, value: t.value, externalId: t.externalId }));
  let arms: { externalId: string; interest: RemoteTargetingItem; adErrors?: string[] }[];
  let note = "";
  try {
    if (conn.isDemo) {
      arms = remote.map((interest, k) => ({ externalId: `${set.externalId}-split${k + 1}`, interest }));
      note = "Demo: the new ad sets are simulated as live so results appear after the sync.";
    } else {
      if (!conn.scopes.split(",").includes(WRITE_SCOPE)) return { ok: false, message: "This Meta login can only read. Reconnect it with “Allow split tests” on the Ad accounts page." };
      const r = await createInterestSplit(decrypt(conn.tokenEnc), set.account.externalId, set.externalId, remote);
      arms = r.arms;
      const failed = r.arms.flatMap((a) => a.adErrors);
      note = `Created ${r.arms.length} ad sets, all paused. Switch them on in Ads Manager (and pause the original) to start the test.${failed.length ? ` ${failed.length} ads could not be copied: ${failed[0]}` : ""}`;
    }
  } catch (e) {
    await db.interestTest.create({ data: { clientId: v.clientId, accountId: set.accountId, campaignId: set.campaignId, sourceAdSetId: set.id, arms: [], status: "failed", error: (e as Error).message.slice(0, 1000), createdById: v.id } });
    return { ok: false, message: `Meta refused the split: ${(e as Error).message}` };
  }

  await db.interestTest.create({
    data: { clientId: v.clientId, accountId: set.accountId, campaignId: set.campaignId, sourceAdSetId: set.id, arms: arms as unknown as Prisma.InputJsonValue, status: conn.isDemo ? "active" : "created", createdById: v.id },
  });
  const r = lastNDays(28);
  await enqueueSync(set.accountId, r.from, ymd(new Date()));
  revalidatePath(`/campaigns/${set.campaignId}`);
  return { ok: true, message: note };
}
