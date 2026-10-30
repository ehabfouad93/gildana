"use server";

import bcrypt from "bcryptjs";
import { revalidatePath } from "next/cache";
import { z } from "zod";
import { db } from "@/lib/db";
import { randomToken } from "@/lib/crypto";
import { platformFromSlug } from "@/lib/platforms";
import { canEdit, requireWorkspace } from "@/lib/tenancy";

const schema = z.object({
  title: z.string().trim().max(160),
  companyId: z.string().optional(),
  platform: z.string().optional(),
  campaignId: z.string().optional(),
  rangeMode: z.enum(["rolling", "fixed"]),
  days: z.coerce.number().int().min(1).max(1100).default(30),
  from: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional().or(z.literal("")),
  to: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional().or(z.literal("")),
  password: z.string().max(100).optional(),
  expiresDays: z.coerce.number().int().min(0).max(3650).default(0),
});

export async function createShareAction(form: FormData) {
  const v = await requireWorkspace();
  if (!canEdit(v)) throw new Error("Read-only user");
  const d = schema.parse(Object.fromEntries(form));
  const companyId = d.companyId && (await db.company.findFirst({ where: { id: d.companyId, clientId: v.clientId } })) ? d.companyId : null;
  const campaignId = d.campaignId && (await db.campaign.findFirst({ where: { id: d.campaignId, account: { connection: { clientId: v.clientId } } } })) ? d.campaignId : null;
  const platform = d.platform ? platformFromSlug(d.platform) : null;
  if (d.rangeMode === "fixed" && (!d.from || !d.to || d.from > d.to)) throw new Error("Pick a valid date range");

  await db.shareLink.create({
    data: {
      token: randomToken(24), clientId: v.clientId, createdById: v.id, title: d.title,
      scope: campaignId ? "CAMPAIGN" : platform ? "PLATFORM" : companyId ? "COMPANY" : "CLIENT",
      companyId, platform, campaignId,
      rangeDays: d.rangeMode === "rolling" ? d.days : null,
      fromDate: d.rangeMode === "fixed" ? new Date(d.from + "T00:00:00Z") : null,
      toDate: d.rangeMode === "fixed" ? new Date(d.to + "T00:00:00Z") : null,
      passwordHash: d.password ? await bcrypt.hash(d.password, 10) : null,
      expiresAt: d.expiresDays ? new Date(Date.now() + d.expiresDays * 86_400_000) : null,
    },
  });
  revalidatePath("/reports");
}

export async function revokeShareAction(form: FormData) {
  const v = await requireWorkspace();
  if (!canEdit(v)) throw new Error("Read-only user");
  await db.shareLink.deleteMany({ where: { id: String(form.get("id")), clientId: v.clientId } });
  revalidatePath("/reports");
}
