"use server";

import type { Prisma } from "@prisma/client";
import { revalidatePath } from "next/cache";
import { approvalGate } from "@/lib/approvals";
import { askClaude, claudeEnabled } from "@/lib/claude";
import { decrypt } from "@/lib/crypto";
import { db } from "@/lib/db";
import { getLang } from "@/lib/i18n";
import { syncNow } from "@/lib/jobs";
import { askOpenAI, openaiEnabled } from "@/lib/openai";
import { accessWhere, canEdit, canFeature, requireModule } from "@/lib/tenancy";
import { WRITE_SCOPE } from "@/platforms/meta";
import { reachEstimate, searchTargeting } from "@/platforms/meta/audiences";
import { metaGet } from "@/platforms/meta/write";
import { listImages } from "@/platforms/meta/creative-grid";
import { GOALS, buildCampaignPlan, createCampaign, loadPage, loadSetup, targetingOf, uploadImage, uploadVideo, validate, type CampaignSpec, type Setup } from "@/platforms/meta/new-campaign";

export type Result = { ok: boolean; message: string };

/** A Meta ad account this user may create campaigns in. */
async function accountFor(accountId: string, write: boolean) {
  const v = await requireModule("campaigns");
  if (!canFeature(v, "campaigns.create")) return { error: "Creating campaigns is switched off for your account." } as const;
  if (write && !canEdit(v)) return { error: "Read-only users cannot create campaigns." } as const;
  const account = await db.adAccount.findFirst({ where: { id: accountId, platform: "META", enabled: true, connection: { clientId: v.clientId }, ...accessWhere(v.access) }, include: { connection: true } });
  if (!account) return { error: "Choose a Meta ad account." } as const;
  const conn = account.connection;
  if (write && !conn.isDemo && !conn.scopes.split(",").includes(WRITE_SCOPE)) return { error: "This Meta login can only read. Reconnect it with “Connect with split tests” on the Ad accounts page." } as const;
  return { v, account, demo: conn.isDemo, token: conn.isDemo ? "" : decrypt(conn.tokenEnc) } as const;
}

const DEMO_SETUP: Setup = { pages: [{ id: "demo-page", name: "Demo page", instagram: { id: "demo-ig", username: "demo" }, whatsapp: "+20 100 000 0000" }], pixels: [{ id: "demo-pixel", name: "Demo pixel" }] };

/** Pages, pixels, the image library and saved audiences of the account. */
export async function loadSetupAction(accountId: string) {
  const a = await accountFor(accountId, false);
  if ("error" in a) return { ok: false as const, message: a.error! };
  const saved = await db.savedAudience.findMany({ where: { accountId: a.account.id }, orderBy: { name: "asc" } });
  const audiences = saved.map((s) => ({ type: s.kind === "lookalike" ? "lookalike" : "audience", value: s.name, externalId: s.externalId }));
  if (a.demo) return { ok: true as const, setup: DEMO_SETUP, images: [1, 2, 3].map((n) => ({ hash: `demoimg${n}`, url: "", name: `Demo image ${n}` })), audiences, currency: a.account.currency };
  try {
    const [setup, images] = await Promise.all([loadSetup(a.token, a.account.externalId), listImages(a.token, a.account.externalId).catch(() => [])]);
    return { ok: true as const, setup, images, audiences, currency: a.account.currency };
  } catch (e) {
    return { ok: false as const, message: (e as Error).message };
  }
}

/** A page's lead forms and recent Facebook / Instagram posts. */
export async function loadPageAction(accountId: string, pageId: string, instagramId?: string) {
  const a = await accountFor(accountId, false);
  if ("error" in a) return { ok: false as const, message: a.error! };
  if (a.demo) return { ok: true as const, forms: [{ id: "demo-form", name: "Demo lead form" }], posts: [{ id: "demo-page_1", instagram: false, text: "Demo post: new phase launch", image: "", date: "2026-11-01" }] };
  try { return { ok: true as const, ...(await loadPage(a.token, pageId, instagramId)) }; }
  catch (e) { return { ok: false as const, message: (e as Error).message }; }
}

/** Uploads an image or a video to the account; returns what the ad needs. Files go only to Meta. */
export async function uploadMediaAction(form: FormData): Promise<{ ok: boolean; message: string; kind?: "image" | "video"; hash?: string; videoId?: string; url?: string }> {
  const a = await accountFor(String(form.get("accountId")), true);
  if ("error" in a) return { ok: false, message: a.error! };
  const file = form.get("file");
  if (!(file instanceof File) || !file.size) return { ok: false, message: "Choose a file." };
  const video = file.type.startsWith("video/");
  if (!video && !file.type.startsWith("image/")) return { ok: false, message: "Upload an image (JPG, PNG) or a video (MP4, MOV)." };
  if (!video && file.size > 30_000_000) return { ok: false, message: "Images can be up to 30 MB." };
  if (video && file.size > 1_000_000_000) return { ok: false, message: "Videos can be up to 1 GB here; upload bigger ones in Ads Manager." };
  try {
    if (a.demo) return video ? { ok: true, message: "Uploaded (demo).", kind: "video", videoId: `demo-video-${Date.now().toString(36)}` } : { ok: true, message: "Uploaded (demo).", kind: "image", hash: `demo-${Date.now().toString(36)}` };
    const bytes = Buffer.from(await file.arrayBuffer());
    if (video) return { ok: true, message: "Video uploaded — Meta finishes processing it in a few minutes.", kind: "video", videoId: await uploadVideo(a.token, a.account.externalId, bytes, file.name) };
    const img = await uploadImage(a.token, a.account.externalId, bytes, file.name);
    return { ok: true, message: "Image uploaded.", kind: "image", hash: img.hash, url: img.url };
  } catch (e) {
    return { ok: false, message: `Meta refused the file: ${(e as Error).message}` };
  }
}

export async function estimateReachAction(accountId: string, spec: CampaignSpec): Promise<Result> {
  const a = await accountFor(accountId, false);
  if ("error" in a) return { ok: false, message: a.error! };
  if (a.demo) return { ok: true, message: `Demo estimate: ${(300 + spec.groups.flat().length * 150).toLocaleString()}K – ${(900 + spec.groups.flat().length * 300).toLocaleString()}K people.` };
  try {
    const r = await reachEstimate(a.token, a.account.externalId, targetingOf(spec), GOALS[spec.goal]?.optimization ?? "REACH");
    if (!r) return { ok: false, message: "Meta gave no estimate — the audience may be too narrow." };
    const f = (n: number) => new Intl.NumberFormat("en-US", { notation: "compact", maximumFractionDigits: 1 }).format(n);
    return { ok: true, message: `Meta estimates ${f(r.low)} – ${f(r.high)} people.` };
  } catch (e) {
    return { ok: false, message: `Meta could not estimate: ${(e as Error).message}` };
  }
}

/** Headline and text ideas from Claude (or OpenAI), to edit before use. */
export async function aiCopyAction(input: { product: string; offer: string; audience: string; goal: string }): Promise<{ ok: boolean; message: string; headlines: string[]; texts: string[] }> {
  const v = await requireModule("campaigns");
  if (!canFeature(v, "campaigns.create") || !canFeature(v, "general.ai_chat")) return { ok: false, message: "AI suggestions are switched off for your account.", headlines: [], texts: [] };
  if (!claudeEnabled() && !openaiEnabled()) return { ok: false, message: "No AI is connected — a Gildana admin can add a Claude or OpenAI key in Admin → Platforms.", headlines: [], texts: [] };
  const lang = (await getLang()) === "ar" ? "Arabic (Egyptian, natural for social ads)" : "English";
  const system = `You write Meta (Facebook/Instagram) ad copy. Answer with JSON only: {"headlines": [5 strings, max 40 characters], "texts": [5 strings, 1-3 short sentences, max 250 characters]}. Language: ${lang}. No made-up prices, dates or claims beyond what is given. No emojis overload (at most one per text).`;
  const prompt = JSON.stringify({ product: input.product.slice(0, 500), offer: input.offer.slice(0, 500), audience: input.audience.slice(0, 300), goal: GOALS[input.goal as keyof typeof GOALS]?.label ?? input.goal });
  try {
    let text: string;
    if (claudeEnabled()) {
      const r = await askClaude({ system, prompt, effort: "low", maxTokens: 2000 });
      if (r.refused) return { ok: false, message: "The AI declined to write this.", headlines: [], texts: [] };
      text = r.text;
    } else text = await askOpenAI({ system, prompt });
    const j = JSON.parse(text.slice(text.indexOf("{"), text.lastIndexOf("}") + 1)) as { headlines?: unknown[]; texts?: unknown[] };
    const clean = (l: unknown[] | undefined, max: number) => (l ?? []).map((x) => String(x).trim().slice(0, max)).filter(Boolean).slice(0, 5);
    return { ok: true, message: "", headlines: clean(j.headlines, 60), texts: clean(j.texts, 400) };
  } catch (e) {
    return { ok: false, message: `The AI could not answer: ${(e as Error).message.slice(0, 200)}`, headlines: [], texts: [] };
  }
}

/**
 * Creates the campaign, its ad set and ads on Meta — paused unless "start
 * now" — and records it. With approval required, only from an approved request.
 */
export async function createCampaignAction(accountId: string, spec: CampaignSpec, approvalId?: string): Promise<Result & { campaignId?: string }> {
  const a = await accountFor(accountId, true);
  if ("error" in a) return { ok: false, message: a.error! };
  const gate = await approvalGate(a.v.clientId, approvalId, "new_campaign");
  if (gate) return { ok: false, message: gate };
  const s: CampaignSpec = { ...spec, currency: a.account.currency };
  const problems = validate(s);
  if (problems.length) return { ok: false, message: problems.join(" ") };

  let note = "";
  let campaignExt = "";
  if (a.demo) {
    // Demo: simulated next to an existing ad set (its ads stand in for the new ones); appears after the sync.
    const src = await db.adSet.findFirst({ where: { accountId: a.account.id, ads: { some: {} } }, orderBy: { updatedAt: "desc" } });
    if (!src) return { ok: false, message: "This demo account has no ad set to simulate from." };
    const tag = Date.now().toString(36);
    campaignExt = `${a.account.externalId}-n${tag}`;
    const items = s.groups.flat();
    await db.interestTest.create({
      data: {
        clientId: a.v.clientId, accountId: a.account.id, campaignId: src.campaignId, sourceAdSetId: src.id, kind: "new_campaign", name: s.name, newCampaignExternalId: campaignExt,
        arms: [{ externalId: `${src.externalId}-n${tag}`, interest: items[0] ?? { type: "interest", value: "Broad", externalId: "" }, items, name: `${s.name} – ad set`, paused: !s.startNow }] as unknown as Prisma.InputJsonValue,
        dailyBudget: s.budgetType === "daily" ? s.budget : null, status: "active", createdById: a.v.id,
      },
    });
    note = `Demo: “${s.name}” is simulated; it appears in Campaigns after the next sync.`;
  } else {
    try {
      const r = await createCampaign(a.token, a.account.externalId, buildCampaignPlan(s));
      campaignExt = r.campaignId;
      note = `“${s.name}” was created ${s.startNow ? "and starts once Meta approves the ads" : "paused — review it, then switch it on"}. ${r.ads.length} ad${r.ads.length === 1 ? "" : "s"} created.${r.failed.length ? ` ${r.failed.length} ad${r.failed.length > 1 ? "s" : ""} failed: ${r.failed[0]}` : ""}`;
    } catch (e) {
      return { ok: false, message: `Meta refused: ${(e as Error).message}` };
    }
  }
  await db.action.create({
    data: {
      clientId: a.v.clientId, accountId: a.account.id, kind: "launch", level: "CAMPAIGN", entityId: campaignExt, entityName: s.name,
      reason: `New campaign — ${GOALS[s.goal].label}, ${s.ads.length} ad${s.ads.length === 1 ? "" : "s"}${s.startNow ? "" : ", created paused"}.`,
      source: "user", status: "applied", payload: { externalId: campaignExt, spec: s } as unknown as Prisma.InputJsonValue, decidedById: a.v.id, decidedAt: new Date(), createdById: a.v.id,
    },
  });
  await syncNow(a.account.id);
  revalidatePath("/campaigns");
  return { ok: true, message: note };
}

/** Finds the dashboard's id of a campaign created here, once it has synced. */
export async function findCreatedAction(accountId: string, externalId: string): Promise<string | null> {
  const v = await requireModule("campaigns");
  const c = await db.campaign.findFirst({ where: { accountId, externalId, account: { connection: { clientId: v.clientId } } }, select: { id: true } });
  return c?.id ?? null;
}

/** Interests and behaviours on Meta for the audience step. */
export async function searchInterestsAction(accountId: string, q: string) {
  const a = await accountFor(accountId, false);
  if ("error" in a) return { ok: false, message: a.error!, items: [] as { type: string; value: string; externalId: string; note?: string }[] };
  const query = String(q ?? "").trim().slice(0, 80);
  if (query.length < 2) return { ok: false, message: "Type at least two letters.", items: [] };
  try {
    if (a.demo) {
      const rows = await db.targetingItem.findMany({ where: { type: { in: ["interest", "behavior"] }, value: { contains: query, mode: "insensitive" }, adSet: { account: { connection: { clientId: a.v.clientId } } } }, distinct: ["value"], take: 20, select: { type: true, value: true, externalId: true } });
      return { ok: true, message: "", items: rows.map((r) => ({ type: r.type, value: r.value, externalId: r.externalId || `demo-${r.value}` })) };
    }
    const items = await searchTargeting(a.token, a.account.externalId, query);
    return { ok: true, message: "", items: items.map((i) => ({ type: i.type, value: i.value, externalId: i.externalId ?? "", note: [i.size, i.path].filter(Boolean).join(" · ") || undefined })) };
  } catch (e) {
    return { ok: false, message: (e as Error).message, items: [] };
  }
}

/** Countries, regions and cities on Meta for the location step. */
export async function searchLocationsAction(accountId: string, q: string): Promise<{ ok: boolean; message: string; items: { type: "country" | "region" | "city"; key: string; name: string }[] }> {
  const a = await accountFor(accountId, false);
  if ("error" in a) return { ok: false, message: a.error!, items: [] };
  const query = String(q ?? "").trim().slice(0, 80);
  if (query.length < 2) return { ok: false, message: "Type at least two letters.", items: [] };
  type Loc = { type: "country" | "region" | "city"; key: string; name: string };
  const demo: Loc[] = [{ type: "city", key: "demo-cairo", name: "Cairo, Egypt" }, { type: "region", key: "demo-giza", name: "Giza, Egypt" }, { type: "country", key: "EG", name: "Egypt" }];
  if (a.demo) return { ok: true, message: "", items: demo.filter((x) => x.name.toLowerCase().includes(query.toLowerCase())) };
  try {
    const r: { data?: { key: string; name: string; type: string; country_code?: string; region?: string; country_name?: string }[] } =
      await metaGet("search", a.token, { type: "adgeolocation", q: query, location_types: JSON.stringify(["country", "region", "city"]), limit: 15 });
    return { ok: true, message: "", items: (r.data ?? []).flatMap((x): Loc[] => (x.type === "country" || x.type === "region" || x.type === "city" ? [{ type: x.type, key: x.type === "country" ? (x.country_code ?? x.key) : String(x.key), name: [x.name, x.type !== "country" ? x.region : "", x.type !== "country" ? x.country_name : ""].filter(Boolean).join(", ") }] : [])) };
  } catch (e) {
    return { ok: false, message: (e as Error).message, items: [] };
  }
}
