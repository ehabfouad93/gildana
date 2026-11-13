"use server";

import type { Prisma } from "@prisma/client";
import { revalidatePath } from "next/cache";
import { createGridAction, type GridInput } from "@/app/actions/creative-grid";
import { mixerLaunchAction } from "@/app/actions/audience-tools";
import { createSplitCampaignAction } from "@/app/actions/split-test";
import { money0 } from "@/lib/approvals";
import { randomToken } from "@/lib/crypto";
import { db } from "@/lib/db";
import { accessWhere, canEdit, canFeature, requireModule, requireWorkspace } from "@/lib/tenancy";
import { mixName, type MixItem } from "@/platforms/meta/audiences";

export type ApprovalResult = { ok: boolean; message: string; link?: string };
type Kind = "split_campaign" | "mixer" | "grid";

const pickValue = (r: string) => r.split("~").slice(2).join("~");

/**
 * Stores a launch exactly as it was filled in and returns a link for the
 * client. Nothing is created on the ad platform until they approve and a
 * team member presses Launch.
 */
export async function sendForApprovalAction(kind: Kind, payload: Record<string, unknown>): Promise<ApprovalResult> {
  const v = await requireWorkspace();
  if (!canEdit(v) || !canFeature(v, "actions.approvals")) return { ok: false, message: "You can't send launches for approval." };
  const mine = { connection: { clientId: v.clientId }, ...accessWhere(v.access) };
  const start = (now: unknown) => (now ? "Starts as soon as it is launched" : "Created paused, then switched on in Ads Manager");
  let title = "", accountId = "", lines: string[] = [];

  if (kind === "split_campaign") {
    const set = await db.adSet.findFirst({ where: { id: String(payload.sourceAdSetId), account: mine }, include: { account: true, campaign: true } });
    const picks = (Array.isArray(payload.pick) ? payload.pick : []).map(String);
    const budget = Number(payload.dailyBudget), days = Number(payload.days) || 0;
    if (!set || !picks.length || !(budget > 0)) return { ok: false, message: "Fill in the interests, the ad set to copy and the budget first." };
    accountId = set.accountId;
    title = String(payload.name || `Interest test – ${set.campaign.name}`);
    lines = [
      `New campaign “${title}” in ad account ${set.account.name}`,
      `${picks.length} ad sets, one per interest: ${picks.map(pickValue).join(", ")}`,
      `Ads and settings copied from the ad set “${set.name}” (${set.campaign.name})`,
      `${money0(budget, set.account.currency)} per day for each ad set${days ? `, for ${days} days — up to ${money0(budget * picks.length * days, set.account.currency)} in total` : ", with no end date"}`,
      start(payload.start === "now"),
    ];
  } else if (kind === "mixer") {
    const set = await db.adSet.findFirst({ where: { id: String(payload.sourceAdSetId), accountId: String(payload.accountId), account: mine }, include: { account: true, campaign: true } });
    const groups = (Array.isArray(payload.groups) ? payload.groups : []) as MixItem[][];
    const budget = Number(payload.dailyBudget), days = Number(payload.days) || 0;
    if (!set || !groups.some((g) => g?.length) || !(budget > 0)) return { ok: false, message: "Build the audience and enter a budget first." };
    accountId = set.accountId;
    const name = mixName(groups, (payload.exclusions ?? []) as MixItem[]);
    title = String(payload.name || name);
    lines = [
      `New ad set in ad account ${set.account.name}${payload.newCampaign ? ", in a new campaign" : `, in the campaign “${set.campaign.name}”`}`,
      `Audience: ${name}`,
      `Ads and settings copied from the ad set “${set.name}”`,
      `${money0(budget, set.account.currency)} per day${days ? `, for ${days} days — up to ${money0(budget * days, set.account.currency)}` : ", with no end date"}`,
      start(payload.startNow),
    ];
  } else {
    const ad = await db.ad.findFirst({ where: { id: String(payload.adId), account: mine }, include: { account: true, adSet: true, campaign: true } });
    const list = (k: string) => (Array.isArray(payload[k]) ? (payload[k] as unknown[]).map(String).filter(Boolean) : []);
    const n = Math.max(1, list("headlines").length) * Math.max(1, list("texts").length) * Math.max(1, list("images").length) * Math.max(1, list("ctas").length);
    if (!ad || n < 2) return { ok: false, message: "Choose the ad and add at least two options first." };
    accountId = ad.accountId;
    title = `${n} ad variations of “${ad.name}”`;
    lines = [
      `${n} new ads in ad account ${ad.account.name}, campaign “${ad.campaign.name}”, copied from the ad “${ad.name}”`,
      ...(list("headlines").length ? [`Headlines: ${list("headlines").join(" · ")}`] : []),
      ...(list("texts").length ? [`Texts: ${list("texts").map((t) => t.slice(0, 80)).join(" · ")}`] : []),
      ...(list("ctas").length ? [`Buttons: ${list("ctas").map((c) => c.replace(/_/g, " ").toLowerCase()).join(", ")}`] : []),
      ...(list("images").length ? [`${list("images").length} images from the library`] : []),
      "They share the ad set's existing budget — no extra spend is added",
      start(payload.startNow),
    ];
  }
  const token = randomToken(24);
  await db.approval.create({ data: { token, clientId: v.clientId, accountId, kind, title: title.slice(0, 200), summary: lines as Prisma.InputJsonValue, payload: payload as Prisma.InputJsonValue, createdById: v.id } });
  revalidatePath("/actions");
  return { ok: true, message: "Saved. Send this link to the client; nothing is created until they approve and you press Launch in To-do → Approvals.", link: `${process.env.APP_URL ?? ""}/a/${token}` };
}

/** Runs an approved launch through the same action its form uses. */
export async function launchApprovalAction(_: ApprovalResult | null, form: FormData): Promise<ApprovalResult> {
  const v = await requireModule("actions");
  if (!canEdit(v) || !canFeature(v, "actions.approvals")) return { ok: false, message: "You can't launch approvals." };
  const a = await db.approval.findFirst({ where: { id: String(form.get("id")), clientId: v.clientId } });
  if (!a) return { ok: false, message: "Approval not found." };
  if (a.status !== "approved") return { ok: false, message: "The client has not approved this yet." };
  if (a.launchedAt) return { ok: false, message: "Already launched." };
  const p = a.payload as Record<string, unknown>;
  let r: { ok: boolean; message: string };
  if (a.kind === "split_campaign") {
    const fd = new FormData();
    for (const k of ["sourceAdSetId", "name", "dailyBudget", "days", "start"]) if (p[k] != null) fd.set(k, String(p[k]));
    for (const x of (p.pick as string[]) ?? []) fd.append("pick", x);
    fd.set("approvalId", a.id);
    r = await createSplitCampaignAction(null, fd);
  } else if (a.kind === "mixer") {
    r = await mixerLaunchAction({ ...(p as Parameters<typeof mixerLaunchAction>[0]), approvalId: a.id });
  } else {
    r = await createGridAction({ ...(p as unknown as GridInput), approvalId: a.id });
  }
  if (r.ok) await db.approval.update({ where: { id: a.id }, data: { launchedAt: new Date(), launchNote: r.message.slice(0, 500) } });
  revalidatePath("/actions");
  return r;
}

export async function deleteApprovalAction(form: FormData) {
  const v = await requireModule("actions");
  if (!canEdit(v) || !canFeature(v, "actions.approvals")) throw new Error("You can't delete approvals.");
  await db.approval.deleteMany({ where: { id: String(form.get("id")), clientId: v.clientId, launchedAt: null } });
  revalidatePath("/actions");
}
