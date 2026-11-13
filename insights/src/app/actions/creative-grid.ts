"use server";

import type { Prisma } from "@prisma/client";
import { revalidatePath } from "next/cache";
import { decrypt } from "@/lib/crypto";
import { db } from "@/lib/db";
import { syncNow } from "@/lib/jobs";
import { accessWhere, canEdit, canFeature, requireModule } from "@/lib/tenancy";
import { WRITE_SCOPE } from "@/platforms/meta";
import { CTAS, MAX_ADS, createGrid, listImages, permutations } from "@/platforms/meta/creative-grid";

export type GridResult = { ok: boolean; message: string };
export type GridInput = { adId: string; adSetId: string; headlines: string[]; texts: string[]; images: string[]; ctas: string[]; startNow: boolean };

async function adFor(adId: string, write: boolean) {
  const v = await requireModule("creatives");
  if (!canFeature(v, "creatives.grid")) return { error: "The creative grid is switched off for your account." } as const;
  if (write && !canEdit(v)) return { error: "Read-only users cannot create ads." } as const;
  const ad = await db.ad.findFirst({ where: { id: adId, platform: "META", account: { connection: { clientId: v.clientId }, ...accessWhere(v.access) } }, include: { account: { include: { connection: true } }, adSet: true } });
  if (!ad) return { error: "Choose a Meta ad to copy." } as const;
  const conn = ad.account.connection;
  if (write && !conn.isDemo && !conn.scopes.split(",").includes(WRITE_SCOPE)) return { error: "This Meta login can only read. Reconnect it with “Connect with split tests” on the Ad accounts page." } as const;
  return { v, ad, demo: conn.isDemo, token: conn.isDemo ? "" : decrypt(conn.tokenEnc) } as const;
}

/** The ad account's image library, to pick images for the grid. */
export async function loadGridImagesAction(adId: string): Promise<{ ok: boolean; message: string; images: { hash: string; url: string; name: string }[] }> {
  const a = await adFor(adId, false);
  if ("error" in a) return { ok: false, message: a.error!, images: [] };
  if (a.demo) return { ok: true, message: "", images: [1, 2, 3].map((n) => ({ hash: `demoimg${n}`, url: "", name: `Demo image ${n}` })) };
  try { return { ok: true, message: "", images: await listImages(a.token, a.ad.account.externalId) }; }
  catch (e) { return { ok: false, message: (e as Error).message, images: [] }; }
}

const lines = (l: unknown, max: number) => [...new Set((Array.isArray(l) ? l : []).map((x) => String(x).trim()).filter(Boolean))].slice(0, max);

/** Creates one ad per combination of the given elements, copied from the source ad. Paused unless "start now". */
export async function createGridAction(input: GridInput): Promise<GridResult> {
  const a = await adFor(String(input.adId), true);
  if ("error" in a) return { ok: false, message: a.error! };
  const variants = permutations(lines(input.headlines, 10).map((s) => s.slice(0, 255)), lines(input.texts, 10).map((s) => s.slice(0, 2000)), lines(input.images, 10), lines(input.ctas, 6).filter((c) => (CTAS as readonly string[]).includes(c)));
  if (variants.length < 2) return { ok: false, message: "Add at least two options for one element (for example two headlines)." };
  if (variants.length > MAX_ADS) return { ok: false, message: `That makes ${variants.length} ads — the limit is ${MAX_ADS}. Remove some options.` };
  const target = input.adSetId && input.adSetId !== a.ad.adSetId ? await db.adSet.findFirst({ where: { id: input.adSetId, accountId: a.ad.accountId } }) : a.ad.adSet;
  if (!target) return { ok: false, message: "Choose the ad set for the new ads." };
  let made = variants.length;
  let failed: string[] = [];
  try {
    if (!a.demo) {
      const r = await createGrid(a.token, a.ad.account.externalId, target.externalId, a.ad.externalId, variants, !!input.startNow);
      made = r.created.length; failed = r.failed;
      if (!made) return { ok: false, message: `Meta refused every ad: ${failed[0] ?? "unknown error"}` };
    }
  } catch (e) {
    return { ok: false, message: (e as Error).message };
  }
  await db.action.create({
    data: {
      clientId: a.v.clientId, accountId: a.ad.accountId, kind: "launch", level: "AD", entityId: a.ad.id, entityName: `${made} ad variations of “${a.ad.name}”`,
      reason: `Creative grid in ad set “${target.name}”: ${variants.length} combinations${input.startNow ? "" : ", created paused"}.${a.demo ? " Demo login — simulated, no ads were added." : ""}`,
      source: "user", status: "applied", payload: { variants } as unknown as Prisma.InputJsonValue, decidedById: a.v.id, decidedAt: new Date(), createdById: a.v.id,
    },
  });
  if (!a.demo) await syncNow(a.ad.accountId);
  revalidatePath("/creatives");
  return {
    ok: true,
    message: a.demo ? `Demo: ${made} ad variations would be created in “${target.name}”. Demo logins don't add ads; on a real account they are created ${input.startNow ? "live" : "paused"}.`
      : `${made} ads created ${input.startNow ? "and live" : "paused"} in “${target.name}”.${failed.length ? ` ${failed.length} failed: ${failed[0]}` : ""} Their results appear under Creatives → Headlines, texts & buttons.`,
  };
}
