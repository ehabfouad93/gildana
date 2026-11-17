import { mixTargeting, type MixItem } from "./audiences";
import { act, metaGet, metaPost, toMinor } from "./write";

/**
 * Creating a new Meta campaign from the dashboard: one campaign, one ad set
 * and one or more ads. `buildCampaignPlan` turns the form into Meta's request
 * bodies and is pure and unit-tested; `createCampaign` sends them, all paused
 * unless "start now".
 */

export const GOALS = {
  leads_form: { label: "Leads — instant form", objective: "OUTCOME_LEADS", optimization: "LEAD_GENERATION", needs: "a Facebook page and one of its lead forms" },
  whatsapp: { label: "Messages — WhatsApp", objective: "OUTCOME_ENGAGEMENT", optimization: "CONVERSATIONS", needs: "a Facebook page with a linked WhatsApp number" },
  messenger: { label: "Messages — Messenger", objective: "OUTCOME_ENGAGEMENT", optimization: "CONVERSATIONS", needs: "a Facebook page" },
  traffic: { label: "Traffic — website visits", objective: "OUTCOME_TRAFFIC", optimization: "LANDING_PAGE_VIEWS", needs: "a website address" },
  website_leads: { label: "Leads — on your website", objective: "OUTCOME_LEADS", optimization: "OFFSITE_CONVERSIONS", needs: "a website address and a Meta pixel that records leads" },
  awareness: { label: "Awareness — reach", objective: "OUTCOME_AWARENESS", optimization: "REACH", needs: "a Facebook page" },
  video_views: { label: "Engagement — video views", objective: "OUTCOME_ENGAGEMENT", optimization: "THRUPLAY", needs: "a Facebook page and a video ad" },
  post_engagement: { label: "Engagement — post engagement", objective: "OUTCOME_ENGAGEMENT", optimization: "POST_ENGAGEMENT", needs: "a Facebook page" },
} as const;
export type Goal = keyof typeof GOALS;

export const SPECIAL = ["", "HOUSING", "CREDIT", "EMPLOYMENT", "ISSUES_ELECTIONS_POLITICS"] as const;

export const POSITIONS = {
  facebook: ["feed", "story", "facebook_reels", "marketplace", "video_feeds", "search", "instream_video"],
  instagram: ["stream", "story", "reels", "explore", "explore_home"],
  messenger: ["story"],
  audience_network: ["classic", "rewarded_video"],
} as const;

export type AdSpec = {
  name: string;
  /** new: build from fields · post: promote an existing post · copy: reuse an existing ad's creative */
  source: "new" | "post" | "copy";
  headline?: string; text?: string; description?: string; url?: string; cta?: string;
  imageHash?: string; videoId?: string; thumbnailUrl?: string;
  /** "<pageId>_<postId>" for a Facebook post; Instagram media id with `instagram: true`. */
  postId?: string; instagram?: boolean;
  /** Creative id of an existing ad to reuse as it is. */
  creativeId?: string;
};

export type CampaignSpec = {
  name: string;
  goal: Goal;
  pageId?: string; instagramId?: string; formId?: string; pixelId?: string; whatsappNumber?: string;
  url?: string;
  special: (typeof SPECIAL)[number];
  /** Budget in the account's currency. */
  budget: number; budgetType: "daily" | "lifetime";
  /** Advantage+ campaign budget: the budget sits on the campaign instead of the ad set. */
  campaignBudget: boolean;
  start?: string; end?: string;
  countries: string[]; regions?: { key: string; name: string }[]; cities?: { key: string; name: string; radius?: number }[];
  ageMin: number; ageMax: number; gender: "all" | "male" | "female"; locales?: number[];
  groups: MixItem[][]; exclusions: MixItem[];
  advantageAudience: boolean;
  placements: "auto" | { facebook?: string[]; instagram?: string[]; messenger?: string[]; audience_network?: string[] };
  ads: AdSpec[];
  startNow: boolean;
  /** The ad account's currency, for the budget's minor units. */
  currency?: string;
};

/** What is missing or wrong, in words — empty when the campaign can be created. Pure. */
export function validate(s: CampaignSpec): string[] {
  const p: string[] = [];
  if (!s.name.trim()) p.push("Give the campaign a name.");
  if (!GOALS[s.goal]) p.push("Choose a goal.");
  const needPage = s.goal !== "traffic" && s.goal !== "website_leads" || s.ads.some((a) => a.source !== "copy");
  if (needPage && !s.pageId) p.push("Choose the Facebook page the ads run from.");
  if (s.goal === "leads_form" && !s.formId) p.push("Choose the lead form.");
  if (s.goal === "website_leads" && !s.pixelId) p.push("Choose the pixel that records leads on the website.");
  if ((s.goal === "traffic" || s.goal === "website_leads") && !/^https?:\/\/\S+\.\S+/.test(s.url ?? "")) p.push("Enter the website address (https://…).");
  if (!(s.budget > 0)) p.push("Enter a budget.");
  if (s.budgetType === "lifetime" && !s.end) p.push("A lifetime budget needs an end date.");
  if (s.start && s.end && s.end <= s.start) p.push("The end date must be after the start date.");
  if (!s.countries.length && !s.regions?.length && !s.cities?.length) p.push("Choose at least one location.");
  if (s.ageMin < 13 || s.ageMax > 65 || s.ageMin > s.ageMax) p.push("Ages must be between 13 and 65.");
  if (s.special && (s.ageMin !== 18 || s.ageMax !== 65 || s.gender !== "all")) p.push("With a special ad category (housing, credit, employment…) Meta requires ages 18–65 and all genders.");
  if (!s.ads.length) p.push("Add at least one ad.");
  s.ads.forEach((a, i) => {
    const n = `Ad ${i + 1}`;
    if (a.source === "post" && !a.postId) p.push(`${n}: choose the post to promote.`);
    if (a.source === "copy" && !a.creativeId) p.push(`${n}: choose the ad to reuse.`);
    if (a.source === "new") {
      if (!a.imageHash && !a.videoId) p.push(`${n}: add an image or a video.`);
      if (!a.text?.trim()) p.push(`${n}: write the main text.`);
      if (s.goal === "video_views" && !a.videoId) p.push(`${n}: a video-views campaign needs a video.`);
    }
  });
  return p;
}

const CTA_DEFAULT: Record<Goal, string> = {
  leads_form: "SIGN_UP", whatsapp: "WHATSAPP_MESSAGE", messenger: "SEND_MESSAGE", traffic: "LEARN_MORE", website_leads: "LEARN_MORE",
  awareness: "LEARN_MORE", video_views: "LEARN_MORE", post_engagement: "LEARN_MORE",
};

/** The button and where it leads, for an ad built from fields. */
function callToAction(s: CampaignSpec, a: AdSpec) {
  const type = a.cta || CTA_DEFAULT[s.goal];
  if (s.goal === "leads_form") return { type, value: { lead_gen_form_id: s.formId, link: "http://fb.me/" } };
  if (s.goal === "whatsapp") return { type: "WHATSAPP_MESSAGE", value: { app_destination: "WHATSAPP" } };
  if (s.goal === "messenger") return { type: "MESSAGE_PAGE", value: { app_destination: "MESSENGER" } };
  return { type, value: { link: a.url || s.url } };
}

/** The ad's creative body. */
export function creativeBody(s: CampaignSpec, a: AdSpec): Record<string, unknown> {
  if (a.source === "copy") return { creative_id: a.creativeId };
  if (a.source === "post") {
    return a.instagram
      ? { name: a.name, object_id: s.pageId, instagram_user_id: s.instagramId, source_instagram_media_id: a.postId }
      : { name: a.name, object_story_id: a.postId };
  }
  const link = a.url || s.url || (s.pageId ? `https://www.facebook.com/${s.pageId}` : undefined);
  const cta = callToAction(s, a);
  const story: Record<string, unknown> = { page_id: s.pageId, ...(s.instagramId ? { instagram_user_id: s.instagramId } : {}) };
  if (a.videoId) {
    story.video_data = { video_id: a.videoId, title: a.headline || undefined, message: a.text, link_description: a.description || undefined, call_to_action: cta, ...(a.imageHash ? { image_hash: a.imageHash } : a.thumbnailUrl ? { image_url: a.thumbnailUrl } : {}) };
  } else {
    story.link_data = { image_hash: a.imageHash, link, name: a.headline || undefined, message: a.text, description: a.description || undefined, call_to_action: cta };
  }
  return { name: a.name, object_story_spec: story };
}

/** Location, age, gender, language, audience and placements. */
export function targetingOf(s: CampaignSpec): Record<string, any> {
  const base: Record<string, any> = {
    geo_locations: {
      ...(s.countries.length ? { countries: s.countries } : {}),
      ...(s.regions?.length ? { regions: s.regions.map((r) => ({ key: r.key })) } : {}),
      ...(s.cities?.length ? { cities: s.cities.map((c) => ({ key: c.key, radius: c.radius ?? 10, distance_unit: "kilometer" })) } : {}),
    },
    age_min: s.ageMin, age_max: s.ageMax,
    ...(s.gender === "male" ? { genders: [1] } : s.gender === "female" ? { genders: [2] } : {}),
    ...(s.locales?.length ? { locales: s.locales } : {}),
  };
  if (s.placements !== "auto") {
    const pl = s.placements;
    const pubs = (["facebook", "instagram", "messenger", "audience_network"] as const).filter((k) => pl[k]?.length);
    base.publisher_platforms = pubs;
    if (pl.facebook?.length) base.facebook_positions = pl.facebook;
    if (pl.instagram?.length) base.instagram_positions = pl.instagram;
    if (pl.messenger?.length) base.messenger_positions = pl.messenger;
    if (pl.audience_network?.length) base.audience_network_positions = pl.audience_network;
  }
  const t = mixTargeting(base, s.groups, s.exclusions);
  t.targeting_automation = { advantage_audience: s.advantageAudience && !s.special ? 1 : 0 };
  return t;
}

/** Campaign, ad set and ads as Meta request bodies. Pure; unit-tested. */
export function buildCampaignPlan(s: CampaignSpec) {
  const g = GOALS[s.goal];
  const status = s.startNow ? "ACTIVE" : "PAUSED";
  const money = (n: number) => toMinor(n, s.currency ?? "USD");
  const budget = s.budgetType === "daily" ? { daily_budget: money(s.budget) } : { lifetime_budget: money(s.budget) };
  const iso = (d?: string) => (d ? new Date(`${d}T00:00:00Z`).toISOString() : undefined);

  const campaign: Record<string, unknown> = {
    name: s.name.slice(0, 250), objective: g.objective, status, buying_type: "AUCTION",
    special_ad_categories: s.special ? [s.special] : [],
    ...(s.special ? { special_ad_category_country: s.countries.length ? s.countries : undefined } : {}),
    ...(s.campaignBudget ? { ...budget, bid_strategy: "LOWEST_COST_WITHOUT_CAP" } : { is_adset_budget_sharing_enabled: false }),
  };

  const promoted: Record<string, unknown> | undefined =
    s.goal === "website_leads" ? { pixel_id: s.pixelId, custom_event_type: "LEAD" }
      : s.goal === "traffic" ? undefined
      : { page_id: s.pageId, ...(s.goal === "whatsapp" && s.whatsappNumber ? { whatsapp_phone_number: s.whatsappNumber } : {}) };
  const destination = s.goal === "leads_form" ? "ON_AD" : s.goal === "whatsapp" ? "WHATSAPP" : s.goal === "messenger" ? "MESSENGER" : s.goal === "traffic" || s.goal === "website_leads" ? "WEBSITE" : undefined;

  const adSet: Record<string, unknown> = {
    name: `${s.name} – ad set`.slice(0, 250), status, optimization_goal: g.optimization,
    billing_event: "IMPRESSIONS",
    ...(s.campaignBudget ? {} : { ...budget, bid_strategy: "LOWEST_COST_WITHOUT_CAP" }),
    ...(promoted ? { promoted_object: promoted } : {}),
    ...(destination ? { destination_type: destination } : {}),
    start_time: iso(s.start), end_time: iso(s.end),
    targeting: targetingOf(s),
  };

  const ads = s.ads.map((a) => ({ name: a.name.slice(0, 250), status, creative: creativeBody(s, a) }));
  return { campaign, adSet, ads };
}

/** One readable summary line per part — for the review step and the approval page. Pure. */
export function describe(s: CampaignSpec, accountName: string, fmt: (n: number) => string): string[] {
  const where = [...s.countries, ...(s.regions ?? []).map((r) => r.name), ...(s.cities ?? []).map((c) => `${c.name} +${c.radius ?? 10} km`)].join(", ");
  const groups = s.groups.filter((g) => g.length).map((g) => g.map((i) => i.value).join(" or ")).join(" AND ");
  const days = s.start && s.end ? Math.round((Date.parse(s.end) - Date.parse(s.start)) / 86_400_000) : null;
  return [
    `New campaign “${s.name}” in ad account ${accountName} — goal: ${GOALS[s.goal].label}${s.special ? ` (special ad category: ${s.special.toLowerCase()})` : ""}`,
    `Who: ${where || "—"}, ages ${s.ageMin}–${s.ageMax}${s.gender !== "all" ? `, ${s.gender}` : ""}${groups ? `, interested in ${groups}` : ", broad"}${s.exclusions.length ? `, excluding ${s.exclusions.map((i) => i.value).join(", ")}` : ""}${s.advantageAudience && !s.special ? " (Advantage+ audience on)" : ""}`,
    `Budget: ${fmt(s.budget)} ${s.budgetType === "daily" ? "per day" : "in total"}${s.budgetType === "daily" && days ? ` for ${days} days — up to ${fmt(s.budget * days)}` : ""}${s.start ? `, from ${s.start}` : ""}${s.end ? ` to ${s.end}` : ""}`,
    `Placements: ${s.placements === "auto" ? "Advantage+ (automatic)" : Object.entries(s.placements).filter(([, v]) => v?.length).map(([k]) => k.replace("_", " ")).join(", ")}`,
    `${s.ads.length} ad${s.ads.length > 1 ? "s" : ""}: ${s.ads.map((a) => `${a.name}${a.source === "post" ? " (existing post)" : a.source === "copy" ? " (reused ad)" : a.headline ? ` — “${a.headline}”` : ""}`).join("; ")}`,
    s.startNow ? "Starts as soon as Meta approves the ads" : "Created paused — switched on in Ads Manager or from here",
  ];
}

// ───────── Meta calls ─────────

export type Setup = {
  pages: { id: string; name: string; instagram?: { id: string; username: string }; whatsapp?: string }[];
  pixels: { id: string; name: string }[];
};

/** The account's pages (with Instagram and WhatsApp) and pixels. */
export async function loadSetup(token: string, accountExternalId: string): Promise<Setup> {
  const a = act(accountExternalId);
  const safe = async <T>(p: Promise<{ data?: T[] }>) => { try { return (await p).data ?? []; } catch { return [] as T[]; } };
  const [pages, pixels] = await Promise.all([
    safe<{ id: string; name: string; instagram_business_account?: { id: string; username?: string }; whatsapp_number?: string }>(metaGet(`${a}/promote_pages`, token, { fields: "id,name,instagram_business_account{id,username},whatsapp_number", limit: 50 })),
    safe<{ id: string; name: string }>(metaGet(`${a}/adspixels`, token, { fields: "id,name", limit: 50 })),
  ]);
  return {
    pages: pages.map((p) => ({ id: String(p.id), name: p.name, instagram: p.instagram_business_account ? { id: String(p.instagram_business_account.id), username: p.instagram_business_account.username ?? "" } : undefined, whatsapp: p.whatsapp_number || undefined })),
    pixels: pixels.map((p) => ({ id: String(p.id), name: p.name })),
  };
}

/** A page's lead forms and recent posts, and its Instagram account's recent posts. Uses a page token when Meta gives one. */
export async function loadPage(token: string, pageId: string, instagramId?: string) {
  let pageToken = token;
  try { const r: { access_token?: string } = await metaGet(pageId, token, { fields: "access_token" }); if (r.access_token) pageToken = r.access_token; } catch { /* the user token may do */ }
  const safe = async <T>(p: Promise<{ data?: T[] }>) => { try { return (await p).data ?? []; } catch { return [] as T[]; } };
  const [forms, posts, ig] = await Promise.all([
    safe<{ id: string; name: string; status?: string }>(metaGet(`${pageId}/leadgen_forms`, pageToken, { fields: "id,name,status", limit: 100 })),
    safe<{ id: string; message?: string; full_picture?: string; created_time: string }>(metaGet(`${pageId}/posts`, pageToken, { fields: "id,message,full_picture,created_time", limit: 25 })),
    instagramId ? safe<{ id: string; caption?: string; media_url?: string; thumbnail_url?: string; timestamp: string }>(metaGet(`${instagramId}/media`, token, { fields: "id,caption,media_url,thumbnail_url,timestamp", limit: 25 })) : Promise.resolve([]),
  ]);
  return {
    forms: forms.filter((f) => !f.status || f.status === "ACTIVE").map((f) => ({ id: String(f.id), name: f.name })),
    posts: [
      ...posts.map((p) => ({ id: String(p.id), instagram: false, text: (p.message ?? "").slice(0, 140), image: p.full_picture ?? "", date: p.created_time.slice(0, 10) })),
      ...ig.map((p) => ({ id: String(p.id), instagram: true, text: (p.caption ?? "").slice(0, 140), image: p.thumbnail_url ?? p.media_url ?? "", date: p.timestamp.slice(0, 10) })),
    ],
  };
}

/** Uploads an image to the account's library; returns its hash. */
export async function uploadImage(token: string, accountExternalId: string, bytes: Buffer, filename: string): Promise<{ hash: string; url: string }> {
  const r: { images?: Record<string, { hash: string; url?: string }> } = await metaPost(`${act(accountExternalId)}/adimages`, token, { bytes: bytes.toString("base64"), name: filename.slice(0, 100) });
  const img = Object.values(r.images ?? {})[0];
  if (!img?.hash) throw new Error("Meta did not return the uploaded image.");
  return { hash: img.hash, url: img.url ?? "" };
}

/** Uploads a video (up to ~1 GB in one request) and returns its id; Meta finishes processing it in the background. */
export async function uploadVideo(token: string, accountExternalId: string, bytes: Buffer, filename: string): Promise<string> {
  const { G } = await import("./write");
  const form = new FormData();
  form.set("access_token", token);
  form.set("name", filename.slice(0, 100));
  form.set("source", new Blob([new Uint8Array(bytes)]), filename);
  const res = await fetch(`${G()}/${act(accountExternalId)}/advideos`, { method: "POST", body: form });
  const data = (await res.json().catch(() => ({}))) as { id?: string; error?: { message?: string } };
  if (!res.ok || !data.id) throw new Error(data.error?.message ?? `Meta refused the video (HTTP ${res.status}).`);
  return String(data.id);
}

/**
 * Creates the campaign, the ad set and the ads. If something fails after the
 * campaign exists, the error says exactly what was created, so it can be
 * finished or deleted in Ads Manager.
 */
export async function createCampaign(token: string, accountExternalId: string, plan: ReturnType<typeof buildCampaignPlan>) {
  const a = act(accountExternalId);
  let campaign: { id?: string };
  try {
    campaign = await metaPost(`${a}/campaigns`, token, plan.campaign);
  } catch (e) {
    if (!/is_adset_budget_sharing_enabled/i.test((e as Error).message)) throw e;
    const { is_adset_budget_sharing_enabled: _drop, ...rest } = plan.campaign;
    campaign = await metaPost(`${a}/campaigns`, token, rest);
  }
  const campaignId = String(campaign.id);
  let adSetId = "";
  const ads: string[] = [];
  const failed: string[] = [];
  try {
    adSetId = String((await metaPost(`${a}/adsets`, token, { ...plan.adSet, campaign_id: campaignId })).id);
  } catch (e) {
    throw new Error(`The campaign was created (id ${campaignId}) but Meta refused the ad set: ${(e as Error).message}. Fix it in Ads Manager or delete the campaign there.`);
  }
  for (const ad of plan.ads) {
    try {
      const c = ad.creative as Record<string, unknown>;
      const creativeId = c.creative_id ? String(c.creative_id) : String((await metaPost(`${a}/adcreatives`, token, c)).id);
      ads.push(String((await metaPost(`${a}/ads`, token, { name: ad.name, adset_id: adSetId, status: ad.status, creative: { creative_id: creativeId } })).id));
    } catch (e) {
      failed.push(`${ad.name}: ${(e as Error).message}`);
    }
  }
  return { campaignId, adSetId, ads, failed };
}

// ───────── copy an existing campaign ─────────

type Existing = {
  name: string; objective: string; dailyBudget: number | null; totalBudget: number | null;
  adSet?: { optimizationGoal: string; dailyBudget: number | null; targetingRaw: Record<string, any> | null; promoted?: Record<string, any> | null; destination?: string } | null;
  ads: { name: string; creativeExternalId: string; headline: string }[];
};

/** Which of our goals an existing campaign matches. Pure. */
export function goalOf(objective: string, optimization = "", destination = ""): Goal {
  const o = objective.replace(/^OUTCOME_/, "");
  if (o === "LEADS" || objective === "LEAD_GENERATION") return optimization === "OFFSITE_CONVERSIONS" ? "website_leads" : "leads_form";
  if (o === "TRAFFIC" || objective === "LINK_CLICKS") return "traffic";
  if (o === "AWARENESS" || objective === "REACH" || objective === "BRAND_AWARENESS") return "awareness";
  if (objective === "MESSAGES" || optimization === "CONVERSATIONS") return destination === "MESSENGER" ? "messenger" : "whatsapp";
  if (objective === "VIDEO_VIEWS" || optimization === "THRUPLAY") return "video_views";
  return o === "ENGAGEMENT" || objective === "POST_ENGAGEMENT" ? "post_engagement" : "leads_form";
}

/**
 * A form pre-filled from an existing campaign: same goal, budget, location,
 * age, audience, placements, and its ads reused as they are. Dates and the
 * name are left for the person to set. Pure; unit-tested.
 */
export function specFromExisting(e: Existing): Partial<CampaignSpec> {
  const t = e.adSet?.targetingRaw ?? {};
  const geo = t.geo_locations ?? {};
  const facet = (o: Record<string, { id: string; name?: string }[]>) =>
    [...(o.interests ?? []).map((i) => ({ type: "interest", value: i.name ?? i.id, externalId: String(i.id) })),
      ...(o.behaviors ?? []).map((i) => ({ type: "behavior", value: i.name ?? i.id, externalId: String(i.id) })),
      ...(o.custom_audiences ?? []).map((i) => ({ type: "audience", value: i.name ?? i.id, externalId: String(i.id) }))];
  const groups = ((t.flexible_spec ?? []) as Record<string, { id: string; name?: string }[]>[]).map(facet).filter((g) => g.length);
  const loose = facet({ interests: t.interests ?? [], behaviors: t.behaviors ?? [], custom_audiences: t.custom_audiences ?? [] });
  if (loose.length) groups.push(loose);
  const exclusions = [...facet(t.exclusions ?? {}), ...((t.excluded_custom_audiences ?? []) as { id: string; name?: string }[]).map((i) => ({ type: "audience", value: i.name ?? i.id, externalId: String(i.id) }))];
  const pubs = t.publisher_platforms as string[] | undefined;
  const g = (t.genders ?? []) as number[];
  const daily = e.adSet?.dailyBudget ?? e.dailyBudget;
  return {
    name: `${e.name} – copy`,
    goal: goalOf(e.objective, e.adSet?.optimizationGoal, e.adSet?.destination),
    pageId: e.adSet?.promoted?.page_id ? String(e.adSet.promoted.page_id) : undefined,
    pixelId: e.adSet?.promoted?.pixel_id ? String(e.adSet.promoted.pixel_id) : undefined,
    budget: daily ?? e.totalBudget ?? 0, budgetType: daily ? "daily" : e.totalBudget ? "lifetime" : "daily",
    campaignBudget: !e.adSet?.dailyBudget && !!(e.dailyBudget || e.totalBudget),
    countries: geo.countries ?? [],
    regions: (geo.regions ?? []).map((r: { key: string; name?: string }) => ({ key: String(r.key), name: r.name ?? r.key })),
    cities: (geo.cities ?? []).map((c: { key: string; name?: string; radius?: number }) => ({ key: String(c.key), name: c.name ?? c.key, radius: c.radius })),
    ageMin: t.age_min ?? 18, ageMax: t.age_max ?? 65, gender: g.length === 1 ? (g[0] === 1 ? "male" : "female") : "all", locales: t.locales ?? [],
    groups, exclusions, advantageAudience: Number(t.targeting_automation?.advantage_audience) === 1,
    placements: !pubs ? "auto" : {
      ...(pubs.includes("facebook") ? { facebook: t.facebook_positions ?? [...POSITIONS.facebook] } : {}),
      ...(pubs.includes("instagram") ? { instagram: t.instagram_positions ?? [...POSITIONS.instagram] } : {}),
      ...(pubs.includes("messenger") ? { messenger: t.messenger_positions ?? [...POSITIONS.messenger] } : {}),
      ...(pubs.includes("audience_network") ? { audience_network: t.audience_network_positions ?? [...POSITIONS.audience_network] } : {}),
    },
    ads: e.ads.filter((a) => a.creativeExternalId).slice(0, 10).map((a) => ({ name: a.name, source: "copy" as const, creativeId: a.creativeExternalId, headline: a.headline })),
  };
}
