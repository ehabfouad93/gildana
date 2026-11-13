"use server";

import type { Prisma } from "@prisma/client";
import { revalidatePath } from "next/cache";
import { db } from "@/lib/db";
import { decrypt } from "@/lib/crypto";
import { lastNDays, ymd } from "@/lib/dates";
import { enqueueSync, syncNow } from "@/lib/jobs";
import { approvalGate } from "@/lib/approvals";
import { splitTests } from "@/lib/queries";
import { accessWhere, canEdit, canFeature, requireModule, scopedAccountIds } from "@/lib/tenancy";
import { WRITE_SCOPE } from "@/platforms/meta";
import { createInterestSplit, createSplitCampaign, findInterest, pauseAdSets } from "@/platforms/meta/split-test";
import type { RemoteTargetingItem } from "@/platforms/types";

export type SplitResult = { ok: boolean; message: string };

const OFF = "This feature is switched off for your account. Ask a workspace owner to turn it on in Team.";

/**
 * Splits a Meta ad set into one PAUSED ad set per selected interest. Demo
 * logins simulate it; real logins need the ads_management permission.
 */
export async function splitAdSetAction(adSetId: string, targetingIds: string[]): Promise<SplitResult> {
  const v = await requireModule("campaigns");
  if (!canEdit(v)) return { ok: false, message: "Read-only users cannot create split tests." };
  if (!canFeature(v, "audiences.create_test")) return { ok: false, message: OFF };
  const set = await db.adSet.findFirst({
    where: { id: adSetId, account: { connection: { clientId: v.clientId }, ...accessWhere(v.access) } },
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

/** Decodes the `pick` values the suggestion lists send (see pickKey in src/components/not-measured.tsx). */
function parsePicks(raw: string[]): RemoteTargetingItem[] {
  const seen = new Set<string>();
  const out: RemoteTargetingItem[] = [];
  for (const r of raw) {
    const [type, externalId, ...rest] = r.split("~");
    const value = rest.join("~").trim();
    if (!value || (type !== "interest" && type !== "behavior") || seen.has(value.toLowerCase())) continue;
    seen.add(value.toLowerCase());
    out.push({ type, externalId: externalId ?? "", value });
  }
  return out;
}

export type SplitCampaignResult = { ok: boolean; message: string; campaignId?: string };

/**
 * Creates a new Meta campaign with one ad set per chosen interest, copied from
 * a source ad set (ads, optimisation, placements, location, age), each with
 * the same daily budget — so every interest gets an exact result. Paused
 * unless "start now" is ticked. Demo logins simulate it.
 */
export async function createSplitCampaignAction(_: SplitCampaignResult | null, form: FormData): Promise<SplitCampaignResult> {
  const v = await requireModule("audiences");
  if (!canEdit(v)) return { ok: false, message: "Read-only users cannot create campaigns." };
  if (!canFeature(v, "audiences.create_test")) return { ok: false, message: OFF };
  const set = await db.adSet.findFirst({
    where: { id: String(form.get("sourceAdSetId")), platform: "META", account: { connection: { clientId: v.clientId }, ...accessWhere(v.access) } },
    include: { account: { include: { connection: true } }, campaign: { select: { name: true } } },
  });
  if (!set) return { ok: false, message: "Choose the ad set whose ads and settings should be copied." };
  let items = parsePicks(form.getAll("pick").map(String));
  if (!items.length) return { ok: false, message: "Tick at least one interest." };
  if (items.length > 10) return { ok: false, message: "A test can have at most 10 interests — untick some, or create a second test." };
  const dailyBudget = Number(form.get("dailyBudget"));
  if (!Number.isFinite(dailyBudget) || dailyBudget <= 0) return { ok: false, message: "Enter a daily budget for each ad set." };
  const days = Math.min(90, Math.max(0, Math.round(Number(form.get("days")) || 0)));
  const startNow = form.get("start") === "now";
  const name = String(form.get("name") || `Interest test – ${set.campaign.name}`).trim().slice(0, 200);
  const conn = set.account.connection;
  const gate = await approvalGate(v.clientId, String(form.get("approvalId") || "") || null, "split_campaign");
  if (gate) return { ok: false, message: gate };
  if (!conn.isDemo && !conn.scopes.split(",").includes(WRITE_SCOPE)) return { ok: false, message: "This Meta login can only read. Reconnect it with “Connect with split tests” on the Ad accounts page, then try again." };

  // Interests without a Meta id (AI ideas the account never used): look in the workspace first, then ask Meta.
  const dropped: string[] = [];
  const resolved: RemoteTargetingItem[] = [];
  for (const it of items) {
    if (it.externalId) { resolved.push(it); continue; }
    const known = await db.targetingItem.findFirst({ where: { type: "interest", value: { equals: it.value, mode: "insensitive" }, externalId: { not: "" }, adSet: { platform: "META", account: { connection: { clientId: v.clientId } } } }, select: { value: true, externalId: true } });
    if (known) { resolved.push({ type: "interest", value: known.value, externalId: known.externalId }); continue; }
    if (conn.isDemo) { resolved.push({ ...it, externalId: `demo-${it.value.toLowerCase().replace(/[^a-z0-9]+/g, "-")}` }); continue; }
    try {
      const hit = await findInterest(decrypt(conn.tokenEnc), set.account.externalId, it.value);
      if (hit) resolved.push(hit); else dropped.push(it.value);
    } catch (e) {
      return { ok: false, message: `Meta could not look up “${it.value}”: ${(e as Error).message}` };
    }
  }
  items = resolved;
  if (!items.length) return { ok: false, message: `Meta has no interest matching: ${dropped.join(", ")}.` };

  const tag = Date.now().toString(36);
  let arms: { externalId: string; interest: RemoteTargetingItem; adErrors?: string[] }[];
  let newCampaignExternalId: string;
  try {
    if (conn.isDemo) {
      newCampaignExternalId = `${set.account.externalId}-t${tag}`;
      arms = items.map((interest, k) => ({ externalId: `${set.externalId}-t${tag}-${k + 1}`, interest }));
    } else {
      const r = await createSplitCampaign(decrypt(conn.tokenEnc), set.account.externalId, set.externalId, items, { name, dailyBudget, currency: set.account.currency, days, startNow });
      newCampaignExternalId = r.campaignExternalId;
      arms = r.arms;
    }
  } catch (e) {
    await db.interestTest.create({ data: { clientId: v.clientId, accountId: set.accountId, campaignId: set.campaignId, sourceAdSetId: set.id, arms: [], status: "failed", kind: "campaign", name, error: (e as Error).message.slice(0, 1000), createdById: v.id } });
    return { ok: false, message: `Meta refused: ${(e as Error).message}` };
  }

  await db.interestTest.create({
    data: {
      clientId: v.clientId, accountId: set.accountId, campaignId: set.campaignId, sourceAdSetId: set.id, kind: "campaign", name, newCampaignExternalId,
      arms: arms as unknown as Prisma.InputJsonValue, dailyBudget, endsAt: days ? new Date(Date.now() + days * 86_400_000) : null,
      status: conn.isDemo || startNow ? "active" : "created", createdById: v.id,
    },
  });
  await syncNow(set.accountId);
  revalidatePath("/audiences");
  revalidatePath("/campaigns");
  const failed = arms.flatMap((a) => a.adErrors ?? []);
  return {
    ok: true,
    message: [
      conn.isDemo ? `Demo: “${name}” is simulated as live with ${arms.length} ad sets; results appear after the next sync.`
        : startNow ? `“${name}” is live with ${arms.length} ad sets, one per interest.`
        : `“${name}” was created paused with ${arms.length} ad sets, one per interest. Review it in Ads Manager and switch it on to start the test.`,
      dropped.length ? `Not found on Meta and left out: ${dropped.join(", ")}.` : "",
      failed.length ? `${failed.length} ads could not be copied: ${failed[0]}` : "",
    ].filter(Boolean).join(" "),
  };
}

/** Pauses the arms of a split test that clearly lost. Only on request, never by itself. */
export async function pauseLosersAction(_: SplitResult | null, form: FormData): Promise<SplitResult> {
  const v = await requireModule("audiences");
  if (!canEdit(v)) return { ok: false, message: "Read-only users cannot pause ad sets." };
  if (!canFeature(v, "audiences.pause_losers")) return { ok: false, message: OFF };
  const accountIds = await scopedAccountIds(v.clientId, { access: v.access, platform: "META" });
  const client = await db.client.findUniqueOrThrow({ where: { id: v.clientId }, select: { reportingCurrency: true } });
  const test = (await splitTests({ accountIds, currency: client.reportingCurrency })).find((t) => t.id === String(form.get("testId")));
  if (!test) return { ok: false, message: "Split test not found." };
  const losers = test.arms.filter((a) => test.verdict.loserIds.includes(a.id) && a.status !== "PAUSED");
  if (!losers.length) return { ok: false, message: "There is no clearly losing ad set to pause yet." };
  const account = await db.adAccount.findUniqueOrThrow({ where: { id: test.accountId }, include: { connection: true } });
  const conn = account.connection;
  try {
    if (conn.isDemo) {
      const row = await db.interestTest.findUniqueOrThrow({ where: { id: test.id } });
      const arms = (row.arms as { externalId: string }[]).map((a) => (losers.some((l) => l.externalId === a.externalId) ? { ...a, paused: true } : a));
      await db.interestTest.update({ where: { id: test.id }, data: { arms: arms as unknown as Prisma.InputJsonValue } });
    } else {
      if (!conn.scopes.split(",").includes(WRITE_SCOPE)) return { ok: false, message: "This Meta login can only read. Reconnect it with “Connect with split tests”." };
      await pauseAdSets(decrypt(conn.tokenEnc), losers.map((l) => l.externalId));
    }
  } catch (e) {
    return { ok: false, message: `Meta refused: ${(e as Error).message}` };
  }
  await db.adSet.updateMany({ where: { accountId: test.accountId, externalId: { in: losers.map((l) => l.externalId) } }, data: { status: "PAUSED" } });
  await syncNow(test.accountId);
  revalidatePath("/audiences");
  return { ok: true, message: `Paused ${losers.length} ad set${losers.length > 1 ? "s" : ""}: ${losers.map((l) => l.value).join(", ")}.` };
}
