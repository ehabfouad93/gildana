"use server";

import type { Prisma } from "@prisma/client";
import { revalidatePath } from "next/cache";
import { decrypt } from "@/lib/crypto";
import { db } from "@/lib/db";
import { STAGES } from "@/lib/funnel";
import { syncNow } from "@/lib/jobs";
import { accessWhere, canEdit, canFeature, requireModule, type Viewer } from "@/lib/tenancy";
import { WRITE_SCOPE } from "@/platforms/meta";
import { PRESETS, createAudience, launchAdSets, listAudiences, listSources, lookalikeAudience, mixName, mixTargeting, presetAudience, reachEstimate, searchTargeting, type MixItem } from "@/platforms/meta/audiences";
import type { FeatureKey } from "@/lib/modules";

export type ToolResult = { ok: boolean; message: string };

/** A Meta ad account the user may work on, with its login. */
async function accountFor(feature: FeatureKey, accountId: string, write: boolean) {
  const v = await requireModule("audiences");
  if (!canFeature(v, feature)) return { error: "This feature is switched off for your account." } as const;
  if (write && !canEdit(v)) return { error: "Read-only users cannot create audiences or ad sets." } as const;
  const account = await db.adAccount.findFirst({ where: { id: accountId, platform: "META", connection: { clientId: v.clientId }, ...accessWhere(v.access) }, include: { connection: true } });
  if (!account) return { error: "Choose a Meta ad account." } as const;
  const conn = account.connection;
  if (write && !conn.isDemo && !conn.scopes.split(",").includes(WRITE_SCOPE)) return { error: "This Meta login can only read. Reconnect it with “Connect with split tests” on the Ad accounts page." } as const;
  return { v, account, demo: conn.isDemo, token: conn.isDemo ? "" : decrypt(conn.tokenEnc) } as const;
}

const clean = (list: unknown): MixItem[] =>
  (Array.isArray(list) ? list : []).slice(0, 40).flatMap((x) => {
    const i = x as Partial<MixItem>;
    return i && typeof i.value === "string" && typeof i.externalId === "string" && i.externalId && ["interest", "behavior", "audience", "lookalike"].includes(String(i.type)) ? [{ type: String(i.type), value: i.value.slice(0, 200), externalId: i.externalId }] : [];
  });

type Mix = { accountId: string; sourceAdSetId: string; groups: unknown[]; exclusions: unknown };

async function sourceFor(v: Viewer & { clientId: string }, accountId: string, adSetId: string) {
  return db.adSet.findFirst({ where: { id: adSetId, accountId, account: { connection: { clientId: v.clientId } } }, include: { campaign: { select: { name: true } } } });
}

/** Meta's estimate of how many people the mix can reach, in the source ad set's location and age. */
export async function mixerReachAction(mix: Mix): Promise<ToolResult> {
  const a = await accountFor("audiences.mixer", mix.accountId, false);
  if ("error" in a) return { ok: false, message: a.error! };
  const src = await sourceFor(a.v, a.account.id, mix.sourceAdSetId);
  if (!src) return { ok: false, message: "Choose the ad set to copy settings from." };
  const groups = (Array.isArray(mix.groups) ? mix.groups : []).map(clean).filter((g) => g.length);
  if (!groups.length) return { ok: false, message: "Add at least one interest or audience." };
  if (a.demo) return { ok: true, message: `Demo estimate: ${(180 + groups.flat().length * 240).toLocaleString()}K – ${(420 + groups.flat().length * 510).toLocaleString()}K people.` };
  try {
    const r = await reachEstimate(a.token, a.account.externalId, mixTargeting(src.targetingRaw as Record<string, unknown> | null, groups, clean(mix.exclusions)), src.optimizationGoal || "REACH");
    if (!r) return { ok: false, message: "Meta gave no estimate for this mix — it may be too narrow." };
    const f = (n: number) => new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(n);
    return { ok: true, message: `Meta estimates ${f(r.low)} – ${f(r.high)} people.${r.high < 50_000 ? " That is small: loosen the AND groups or remove exclusions." : ""}` };
  } catch (e) {
    return { ok: false, message: `Meta could not estimate: ${(e as Error).message}` };
  }
}

/** Interests and behaviours on Meta matching a search, to add to the mix. */
export async function mixerSearchAction(accountId: string, q: string): Promise<{ ok: boolean; message: string; items: (MixItem & { size?: string; path?: string })[] }> {
  const a = await accountFor("audiences.mixer", accountId, false);
  if ("error" in a) return { ok: false, message: a.error!, items: [] };
  const query = String(q ?? "").trim().slice(0, 80);
  if (query.length < 2) return { ok: false, message: "Type at least two letters.", items: [] };
  try {
    if (a.demo) {
      const rows = await db.targetingItem.findMany({ where: { type: { in: ["interest", "behavior"] }, value: { contains: query, mode: "insensitive" }, adSet: { account: { connection: { clientId: a.v.clientId } } } }, distinct: ["value"], take: 20, select: { type: true, value: true, externalId: true } });
      return { ok: true, message: "", items: rows.map((r) => ({ type: r.type, value: r.value, externalId: r.externalId || `demo-${r.value}` })) };
    }
    return { ok: true, message: "", items: await searchTargeting(a.token, a.account.externalId, query) };
  } catch (e) {
    return { ok: false, message: (e as Error).message, items: [] };
  }
}

/** Creates one ad set for the mix, copied from the source ad set. Paused unless "start now". */
export async function mixerLaunchAction(mix: Mix & { name: string; dailyBudget: number; days: number; startNow: boolean; newCampaign: boolean }): Promise<ToolResult & { campaignId?: string }> {
  const a = await accountFor("audiences.mixer", mix.accountId, true);
  if ("error" in a) return { ok: false, message: a.error! };
  const src = await sourceFor(a.v, a.account.id, mix.sourceAdSetId);
  if (!src) return { ok: false, message: "Choose the ad set whose ads and settings should be copied." };
  const groups = (Array.isArray(mix.groups) ? mix.groups : []).map(clean).filter((g) => g.length);
  const exclusions = clean(mix.exclusions);
  if (!groups.length) return { ok: false, message: "Add at least one interest or audience." };
  const dailyBudget = Number(mix.dailyBudget);
  if (!Number.isFinite(dailyBudget) || dailyBudget <= 0) return { ok: false, message: "Enter a daily budget." };
  const days = Math.min(90, Math.max(0, Math.round(Number(mix.days) || 0)));
  const name = String(mix.name || mixName(groups, exclusions)).trim().slice(0, 200);
  const newCampaignName = mix.newCampaign ? `Audiences – ${src.campaign.name}`.slice(0, 200) : undefined;
  let created: string, campaignExt = "";
  try {
    if (a.demo) {
      const tag = Date.now().toString(36);
      created = `${src.externalId}-m${tag}`;
      if (newCampaignName) campaignExt = `${a.account.externalId}-m${tag}`;
      await db.interestTest.create({
        data: {
          clientId: a.v.clientId, accountId: a.account.id, campaignId: src.campaignId, sourceAdSetId: src.id, kind: "audience", name: newCampaignName ?? name, newCampaignExternalId: campaignExt,
          arms: [{ externalId: created, interest: groups[0][0], items: groups.flat(), name }] as unknown as Prisma.InputJsonValue, dailyBudget, status: "active", createdById: a.v.id,
        },
      });
    } else {
      const r = await launchAdSets(a.token, a.account.externalId, src.externalId, (t) => [{ name, targeting: mixTargeting(t, groups, exclusions) }], { dailyBudget, currency: a.account.currency, days, startNow: !!mix.startNow, newCampaignName });
      created = r.adSets[0].externalId;
    }
  } catch (e) {
    return { ok: false, message: `Meta refused: ${(e as Error).message}` };
  }
  await db.action.create({
    data: { clientId: a.v.clientId, accountId: a.account.id, kind: "launch", level: "ADSET", entityId: src.id, entityName: name, reason: `New ad set for the audience “${mixName(groups, exclusions)}”, copied from “${src.name}”${mix.startNow ? "" : ", created paused"}.`, source: "user", status: "applied", payload: { externalId: created, dailyBudget } as Prisma.InputJsonValue, decidedById: a.v.id, decidedAt: new Date(), createdById: a.v.id },
  });
  await syncNow(a.account.id);
  revalidatePath("/campaigns");
  return { ok: true, message: a.demo ? `Demo: “${name}” is simulated as live; it appears after the next sync.` : mix.startNow ? `“${name}” is live.` : `“${name}” was created paused. Review it in Ads Manager and switch it on.` };
}

// ───────── ready-made audiences ─────────

/** Reads the account's custom and lookalike audiences from Meta into the workspace's list. */
export async function refreshAudiencesAction(_: ToolResult | null, form: FormData): Promise<ToolResult> {
  const a = await accountFor("audiences.launcher", String(form.get("accountId")), false);
  if ("error" in a) return { ok: false, message: a.error! };
  try {
    const list = a.demo
      ? (await db.targetingItem.findMany({ where: { type: { in: ["audience", "lookalike"] }, adSet: { accountId: a.account.id } }, distinct: ["value"], select: { type: true, value: true, externalId: true } }))
          .map((t) => ({ externalId: t.externalId || `demo-${t.value}`, name: t.value, kind: t.type === "lookalike" ? "lookalike" : /visitor|site/i.test(t.value) ? "website" : "other", sizeLow: null, sizeHigh: null }))
      : await listAudiences(a.token, a.account.externalId);
    for (const x of list) {
      const data = { name: x.name, kind: x.kind, sizeLow: x.sizeLow != null ? BigInt(x.sizeLow) : null, sizeHigh: x.sizeHigh != null ? BigInt(x.sizeHigh) : null };
      await db.savedAudience.upsert({
        where: { accountId_externalId: { accountId: a.account.id, externalId: x.externalId } },
        create: { accountId: a.account.id, externalId: x.externalId, ...data, stage: x.kind === "lookalike" ? "prospecting" : x.kind === "customer_list" ? "retention" : x.kind === "website" ? "retargeting" : "reengagement" },
        update: data,
      });
    }
    revalidatePath("/audiences/launcher");
    return { ok: true, message: `${list.length} audience${list.length === 1 ? "" : "s"} read from Meta.` };
  } catch (e) {
    return { ok: false, message: `Meta refused: ${(e as Error).message}` };
  }
}

/** Creates the ticked ready-made audiences from the chosen pixel, page and Instagram account. */
export async function createPresetsAction(_: ToolResult | null, form: FormData): Promise<ToolResult> {
  const a = await accountFor("audiences.launcher", String(form.get("accountId")), true);
  if ("error" in a) return { ok: false, message: a.error! };
  const keys = form.getAll("preset").map(String);
  const picked = PRESETS.filter((p) => keys.includes(p.key));
  if (!picked.length) return { ok: false, message: "Tick at least one audience." };
  const src = { pixelId: String(form.get("pixelId") || "") || undefined, pageId: String(form.get("pageId") || "") || undefined, instagramId: String(form.get("instagramId") || "") || undefined, videoIds: String(form.get("videoIds") || "").split(",").filter(Boolean) };
  let made = 0;
  const skipped: string[] = [];
  for (const p of picked) {
    const body = presetAudience(p, src, p.label);
    if (!body) { skipped.push(`${p.label} (no ${p.kind === "website" ? "pixel" : p.kind === "video" ? "videos" : p.kind} chosen)`); continue; }
    try {
      const externalId = a.demo ? `demo-${p.key}-${a.account.externalId}` : await createAudience(a.token, a.account.externalId, body);
      await db.savedAudience.upsert({
        where: { accountId_externalId: { accountId: a.account.id, externalId } },
        create: { accountId: a.account.id, externalId, name: p.label, kind: p.kind, stage: p.stage, createdHere: true },
        update: { name: p.label },
      });
      made++;
    } catch (e) {
      skipped.push(`${p.label}: ${(e as Error).message}`);
    }
  }
  revalidatePath("/audiences/launcher");
  return { ok: made > 0, message: `${made} audience${made === 1 ? "" : "s"} created${a.demo ? " (demo)" : ""}.${skipped.length ? ` Not created — ${skipped.join("; ")}` : ""} Meta needs a little time to fill a new audience.` };
}

/** Creates lookalikes of one audience for a country at the ticked sizes. */
export async function createLookalikesAction(_: ToolResult | null, form: FormData): Promise<ToolResult> {
  const a = await accountFor("audiences.launcher", String(form.get("accountId")), true);
  if ("error" in a) return { ok: false, message: a.error! };
  const origin = await db.savedAudience.findFirst({ where: { id: String(form.get("audienceId")), accountId: a.account.id } });
  if (!origin) return { ok: false, message: "Choose the audience to model on." };
  const country = String(form.get("country") ?? "").trim().toUpperCase();
  if (!/^[A-Z]{2}$/.test(country)) return { ok: false, message: "Enter a 2-letter country code, e.g. EG, SA, AE." };
  const percents = form.getAll("percent").map(Number).filter((n) => n >= 1 && n <= 10);
  if (!percents.length) return { ok: false, message: "Tick at least one size." };
  let made = 0;
  const failed: string[] = [];
  for (const pct of percents) {
    const body = lookalikeAudience(origin.externalId, origin.name, country, pct);
    try {
      const externalId = a.demo ? `demo-lal-${pct}-${country}-${origin.externalId}` : await createAudience(a.token, a.account.externalId, body);
      await db.savedAudience.upsert({ where: { accountId_externalId: { accountId: a.account.id, externalId } }, create: { accountId: a.account.id, externalId, name: String(body.name), kind: "lookalike", stage: "prospecting", createdHere: true }, update: {} });
      made++;
    } catch (e) {
      failed.push(`${pct}%: ${(e as Error).message}`);
    }
  }
  revalidatePath("/audiences/launcher");
  return { ok: made > 0, message: `${made} lookalike${made === 1 ? "" : "s"} created${a.demo ? " (demo)" : ""}.${failed.length ? ` Failed — ${failed.join("; ")}` : ""}` };
}

/** An audience's funnel stage and the stages whose new ad sets exclude it by default. */
export async function setAudienceDefaultsAction(form: FormData) {
  const a = await accountFor("audiences.launcher", String(form.get("accountId")), true);
  if ("error" in a) throw new Error(a.error);
  const stage = String(form.get("stage"));
  await db.savedAudience.updateMany({
    where: { id: String(form.get("audienceId")), accountId: a.account.id },
    data: { stage: (STAGES as readonly string[]).includes(stage) ? stage : "retargeting", excludeFrom: form.getAll("excludeFrom").map(String).filter((s) => (STAGES as readonly string[]).includes(s)) },
  });
  revalidatePath("/audiences/launcher");
}

/** The account's pixels, pages, Instagram accounts and videos, for the ready-made audiences form. */
export async function loadSourcesAction(accountId: string) {
  const a = await accountFor("audiences.launcher", accountId, false);
  if ("error" in a) return { ok: false as const, message: a.error! };
  if (a.demo) return { ok: true as const, sources: { pixels: [{ id: "demo-pixel", name: "Demo pixel" }], pages: [{ id: "demo-page", name: "Demo page" }], instagram: [{ id: "demo-ig", name: "@demo" }], videos: ["demo-v1", "demo-v2"] } };
  try { return { ok: true as const, sources: await listSources(a.token, a.account.externalId) }; }
  catch (e) { return { ok: false as const, message: (e as Error).message }; }
}
