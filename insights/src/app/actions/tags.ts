"use server";

import { revalidatePath } from "next/cache";
import { db } from "@/lib/db";
import { STAGES } from "@/lib/funnel";
import { accessWhere, canEdit, canFeature, requireModule } from "@/lib/tenancy";

async function tagger() {
  const v = await requireModule("campaigns");
  if (!canEdit(v) || !canFeature(v, "campaigns.tags")) throw new Error("You can't change tags.");
  return v;
}

export async function createTagAction(form: FormData) {
  const v = await tagger();
  const name = String(form.get("name") ?? "").trim().slice(0, 40);
  if (!name) return;
  const color = /^#[0-9a-f]{6}$/i.test(String(form.get("color"))) ? String(form.get("color")) : "#64748b";
  await db.tag.upsert({ where: { clientId_name: { clientId: v.clientId, name } }, create: { clientId: v.clientId, name, color }, update: { color } });
  revalidatePath("/campaigns");
}

export async function deleteTagAction(form: FormData) {
  const v = await tagger();
  await db.tag.deleteMany({ where: { id: String(form.get("id")), clientId: v.clientId } });
  revalidatePath("/campaigns");
}

/** Replaces a campaign's tags with the ticked ones. */
export async function setCampaignTagsAction(form: FormData) {
  const v = await tagger();
  const campaignId = String(form.get("campaignId"));
  const campaign = await db.campaign.findFirst({ where: { id: campaignId, account: { connection: { clientId: v.clientId }, ...accessWhere(v.access) } }, select: { id: true } });
  if (!campaign) throw new Error("Campaign not found");
  const tags = await db.tag.findMany({ where: { clientId: v.clientId, id: { in: form.getAll("tagId").map(String) } }, select: { id: true } });
  await db.$transaction([
    db.entityTag.deleteMany({ where: { level: "CAMPAIGN", entityId: campaignId, tag: { clientId: v.clientId } } }),
    db.entityTag.createMany({ data: tags.map((t) => ({ tagId: t.id, level: "CAMPAIGN", entityId: campaignId })) }),
  ]);
  revalidatePath(`/campaigns/${campaignId}`);
  revalidatePath("/campaigns");
}

/** Sets an ad set's funnel stage by hand; empty goes back to the detected one. */
export async function setStageAction(form: FormData) {
  const v = await tagger();
  const stage = String(form.get("stage") ?? "");
  const set = await db.adSet.findFirst({ where: { id: String(form.get("adSetId")), account: { connection: { clientId: v.clientId }, ...accessWhere(v.access) } }, select: { id: true, campaignId: true } });
  if (!set) throw new Error("Ad set not found");
  await db.adSet.update({ where: { id: set.id }, data: { funnelStageManual: (STAGES as readonly string[]).includes(stage) ? stage : "" } });
  revalidatePath(`/campaigns/${set.campaignId}`);
}
