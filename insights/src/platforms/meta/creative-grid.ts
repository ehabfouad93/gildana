import { act, metaGet, metaPost } from "./write";

/**
 * Creative split-test grid: one ad per combination of headlines, texts,
 * images and buttons, each a copy of a source ad's creative with only those
 * elements changed. The builders are pure and unit-tested.
 */

export type Variant = { headline?: string; text?: string; imageHash?: string; cta?: string };

export const CTAS = ["LEARN_MORE", "SIGN_UP", "GET_QUOTE", "CONTACT_US", "APPLY_NOW", "BOOK_NOW", "DOWNLOAD", "SHOP_NOW", "SUBSCRIBE", "GET_OFFER", "SEND_MESSAGE", "WHATSAPP_MESSAGE", "CALL_NOW"] as const;
export const MAX_ADS = 30;

/** Every combination of the given elements; an empty list means "keep the source's". */
export function permutations(headlines: string[], texts: string[], images: string[], ctas: string[]): Variant[] {
  const or = <T,>(l: T[]): (T | undefined)[] => (l.length ? l : [undefined]);
  const out: Variant[] = [];
  for (const headline of or(headlines)) for (const text of or(texts)) for (const imageHash of or(images)) for (const cta of or(ctas)) out.push({ headline, text, imageHash, cta });
  return out;
}

/** Short ad name saying what this variant changes. */
export function variantName(base: string, v: Variant, n: number): string {
  const parts = [v.headline && `H: ${v.headline.slice(0, 30)}`, v.text && `T: ${v.text.slice(0, 20)}`, v.imageHash && `Img ${v.imageHash.slice(0, 6)}`, v.cta && v.cta.replace(/_/g, " ").toLowerCase()].filter(Boolean);
  return `${base} · v${n}${parts.length ? ` · ${parts.join(" · ")}` : ""}`.slice(0, 250);
}

/**
 * The source creative's story with a variant's elements swapped in. Works for
 * link (image) and video ads; returns null for formats that can't be varied
 * this way (carousels, existing posts, dynamic creative).
 */
export function variantStory(story: Record<string, any> | null | undefined, v: Variant): Record<string, any> | null {
  if (!story) return null;
  const s = JSON.parse(JSON.stringify(story));
  const setCta = (holder: Record<string, any>) => { if (v.cta) holder.call_to_action = { ...(holder.call_to_action ?? {}), type: v.cta }; };
  if (s.link_data && !s.link_data.child_attachments) {
    const d = s.link_data;
    if (v.headline) d.name = v.headline;
    if (v.text) d.message = v.text;
    if (v.imageHash) { d.image_hash = v.imageHash; delete d.picture; }
    setCta(d);
    return s;
  }
  if (s.video_data) {
    const d = s.video_data;
    if (v.headline) d.title = v.headline;
    if (v.text) d.message = v.text;
    if (v.imageHash) { d.image_hash = v.imageHash; delete d.image_url; }
    setCta(d);
    return s;
  }
  return null;
}

export async function sourceCreative(token: string, adExternalId: string): Promise<{ name: string; story: Record<string, any> | null }> {
  const r: { name?: string; creative?: { object_story_spec?: Record<string, any> } } = await metaGet(adExternalId, token, { fields: "name,creative{object_story_spec}" });
  return { name: r.name ?? "Ad", story: r.creative?.object_story_spec ?? null };
}

export async function listImages(token: string, accountExternalId: string): Promise<{ hash: string; url: string; name: string }[]> {
  const r: { data?: { hash: string; url?: string; url_128?: string; name?: string }[] } = await metaGet(`${act(accountExternalId)}/adimages`, token, { fields: "hash,url_128,url,name", limit: 60 });
  return (r.data ?? []).map((x) => ({ hash: x.hash, url: x.url_128 ?? x.url ?? "", name: x.name ?? x.hash.slice(0, 8) }));
}

/** Creates one creative + ad per variant in the ad set. Paused unless `startNow`. */
export async function createGrid(token: string, accountExternalId: string, adSetExternalId: string, sourceAdExternalId: string, variants: Variant[], startNow: boolean) {
  const a = act(accountExternalId);
  const src = await sourceCreative(token, sourceAdExternalId);
  const created: string[] = [];
  const failed: string[] = [];
  for (const [i, v] of variants.entries()) {
    const story = variantStory(src.story, v);
    if (!story) throw new Error("This ad's format can't be varied here (carousel, existing post or dynamic creative). Choose a single image or video ad.");
    const name = variantName(src.name, v, i + 1);
    try {
      const creative = await metaPost(`${a}/adcreatives`, token, { name, object_story_spec: story });
      const ad = await metaPost(`${a}/ads`, token, { name, adset_id: adSetExternalId, creative: { creative_id: creative.id }, status: startNow ? "ACTIVE" : "PAUSED" });
      created.push(String(ad.id));
    } catch (e) {
      failed.push(`${name}: ${(e as Error).message}`);
    }
  }
  return { created, failed };
}
