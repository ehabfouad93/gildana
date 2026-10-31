import type { Platform } from "@prisma/client";
import { eachDay } from "@/lib/dates";
import { FALLBACK } from "@/lib/fx-rates";
import { SEGMENT_TYPES } from "@/lib/segments";
import type {
  Connector, Metrics, RemoteAd, RemoteAdSet, RemoteBreakdown, RemoteCampaign, RemoteKeyword, RemoteLead,
  RemotePlacement, RemoteSearchTerm, RemoteStat, RemoteTargetingItem, StatsBundle, SyncContext,
} from "./types";

/**
 * Demo mode: a deterministic, realistic-looking account for every platform,
 * so the whole product can be used before any API is approved. The same
 * account id and day always produce the same numbers, which keeps re-syncs
 * idempotent and tests stable.
 */

// ───────── deterministic randomness ─────────

function hash(s: string): number {
  let h = 2166136261;
  for (let i = 0; i < s.length; i++) h = Math.imul(h ^ s.charCodeAt(i), 16777619);
  return h >>> 0;
}

function rng(seed: string) {
  let a = hash(seed);
  return () => {
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

const pick = <T,>(r: () => number, xs: readonly T[]): T => xs[Math.floor(r() * xs.length)];
const between = (r: () => number, lo: number, hi: number) => lo + (hi - lo) * r();

/** Splits an integer total across weights so the parts add back up exactly. */
export function splitInt(total: number, weights: number[]): number[] {
  const sum = weights.reduce((a, b) => a + b, 0) || 1;
  const raw = weights.map((w) => (total * w) / sum);
  const out = raw.map(Math.floor);
  let rest = total - out.reduce((a, b) => a + b, 0);
  const order = raw.map((v, i) => [v - Math.floor(v), i] as const).sort((a, b) => b[0] - a[0]);
  for (let k = 0; rest > 0 && k < order.length; k++, rest--) out[order[k][1]]++;
  return out;
}

const round2 = (n: number) => Math.round(n * 100) / 100;

// ───────── platform flavour ─────────

type Flavour = {
  objectives: string[];
  adSetNames: string[];
  targeting: (r: () => number) => RemoteTargetingItem[];
  dims: Record<string, string[]>;
  formats: string[];
  cpm: [number, number];
  ctr: [number, number]; // percent
  cvr: [number, number]; // percent of clicks
  leadGen: boolean;
};

const INTERESTS = [
  "Real estate investing", "Luxury goods", "Interior design", "Travel", "Online shopping", "Fitness and wellness",
  "Small business owners", "Entrepreneurship", "Parenting", "Cars", "Fashion", "Technology", "Cooking", "Football",
];
const BEHAVIOURS = ["Engaged shoppers", "Frequent travellers", "Facebook page admins", "Expats"];
const KEYWORDS = [
  "apartments for sale cairo", "new capital villas", "buy chalet sahel", "real estate egypt", "installment apartments",
  "compound new cairo", "marketing agency cairo", "digital marketing services", "social media agency", "seo company egypt",
  "best crm software", "online accounting", "gym near me", "dental clinic cairo",
];
const JOB_TITLES = ["Marketing Manager", "CEO", "Founder", "Sales Director", "HR Manager", "Software Engineer", "Finance Manager", "Operations Manager"];
const COUNTRIES = ["EG", "SA", "AE", "KW", "QA"];
const REGIONS = ["Cairo", "Giza", "Alexandria", "Riyadh", "Dubai", "Jeddah", "Kuwait City", "Doha"];

const FLAVOURS: Record<Platform, Flavour> = {
  META: {
    objectives: ["OUTCOME_LEADS", "OUTCOME_SALES", "OUTCOME_TRAFFIC", "OUTCOME_AWARENESS", "OUTCOME_ENGAGEMENT"],
    adSetNames: ["Interests – Investors", "Lookalike 1% – Buyers", "Broad 25-45", "Retargeting – Site visitors", "Interests – Lifestyle"],
    targeting: (r) => {
      const items: RemoteTargetingItem[] = [];
      // About a third of ad sets target one interest only — the way to measure an interest on Meta.
      const single = r() < 0.35;
      const n = single ? 1 : 2 + Math.floor(r() * 3);
      const seen = new Set<string>();
      for (let i = 0; i < n; i++) {
        const v = pick(r, INTERESTS);
        if (!seen.has(v)) { seen.add(v); items.push({ type: "interest", value: v, externalId: String(6000000000000 + hash(v) % 1e9) }); }
      }
      if (!single && r() > 0.5) items.push({ type: "behavior", value: pick(r, BEHAVIOURS) });
      if (!single && r() > 0.6) items.push({ type: "audience", value: "Website visitors 30d" });
      if (!single && r() > 0.7) items.push({ type: "lookalike", value: "Lookalike (EG, 1%) – Leads" });
      if (r() > 0.8) items.push({ type: "audience", value: "Existing customers", exclude: true });
      const minAge = pick(r, [18, 21, 25]);
      items.push({ type: "age", value: `${minAge}-${pick(r, [45, 55, 65])}` });
      items.push({ type: "gender", value: pick(r, ["All", "All", "Male", "Female"]) });
      items.push({ type: "geo", value: pick(r, ["Egypt", "Cairo +40km", "Egypt, Saudi Arabia", "UAE"]) });
      items.push({ type: "placement", value: pick(r, ["Advantage+ placements", "Instagram only", "Facebook & Instagram feeds"]) });
      return items;
    },
    dims: {
      age: ["18-24", "25-34", "35-44", "45-54", "55-64", "65+"],
      gender: ["male", "female", "unknown"],
      country: COUNTRIES,
      region: REGIONS,
      device: ["mobile_app", "mobile_web", "desktop"],
      customer_segment: ["New audience", "Engaged audience", "Existing customers"],
      frequency: ["1", "2", "3", "4-5", "6-10", "11+"],
      placement: ["facebook_feed", "instagram_feed", "instagram_stories", "instagram_reels", "facebook_reels", "audience_network", "messenger_inbox"],
      hour: Array.from({ length: 24 }, (_, h) => String(h).padStart(2, "0")),
    },
    formats: ["image", "video", "carousel"],
    cpm: [2.5, 7], ctr: [0.8, 2.2], cvr: [4, 14], leadGen: true,
  },
  GOOGLE: {
    objectives: ["SEARCH", "DISPLAY", "VIDEO", "PERFORMANCE_MAX"],
    adSetNames: ["Brand", "Generic – Apartments", "Competitors", "In-market – Real estate", "Remarketing"],
    targeting: (r) => {
      const items: RemoteTargetingItem[] = [];
      const n = 3 + Math.floor(r() * 4);
      const seen = new Set<string>();
      for (let i = 0; i < n; i++) {
        const v = pick(r, KEYWORDS);
        if (!seen.has(v)) { seen.add(v); items.push({ type: "keyword", value: v }); }
      }
      items.push({ type: "geo", value: pick(r, ["Egypt", "Cairo", "Saudi Arabia"]) });
      items.push({ type: "language", value: pick(r, ["Arabic, English", "Arabic"]) });
      if (r() > 0.5) items.push({ type: "audience", value: pick(r, ["In-market: Residential properties", "Affinity: Luxury shoppers", "Customer list"]) });
      return items;
    },
    dims: {
      age: ["18-24", "25-34", "35-44", "45-54", "55-64", "65+", "undetermined"],
      gender: ["male", "female", "undetermined"],
      country: COUNTRIES,
      region: REGIONS,
      device: ["MOBILE", "DESKTOP", "TABLET"],
      network: ["SEARCH", "SEARCH_PARTNERS", "CONTENT", "YOUTUBE_WATCH"],
      hour: Array.from({ length: 24 }, (_, h) => String(h).padStart(2, "0")),
    },
    formats: ["responsive_search", "responsive_display", "video"],
    cpm: [3, 12], ctr: [1.5, 6], cvr: [3, 10], leadGen: false,
  },
  LINKEDIN: {
    objectives: ["LEAD_GENERATION", "WEBSITE_VISIT", "BRAND_AWARENESS", "ENGAGEMENT"],
    adSetNames: ["Decision makers – MENA", "Marketing leaders", "SMB founders", "HR & People", "Retargeting – Engagers"],
    targeting: (r) => {
      const items: RemoteTargetingItem[] = [];
      for (let i = 0; i < 3; i++) items.push({ type: "job_title", value: pick(r, JOB_TITLES) });
      items.push({ type: "seniority", value: pick(r, ["Director", "VP", "Owner", "Manager", "CXO"]) });
      items.push({ type: "industry", value: pick(r, ["Real Estate", "Marketing Services", "IT Services", "Financial Services", "Retail"]) });
      items.push({ type: "company_size", value: pick(r, ["11-50", "51-200", "201-500", "501-1000"]) });
      items.push({ type: "geo", value: pick(r, ["Egypt", "UAE", "Saudi Arabia"]) });
      return dedupe(items);
    },
    dims: {
      job_title: JOB_TITLES,
      seniority: ["Entry", "Senior", "Manager", "Director", "VP", "CXO", "Owner"],
      industry: ["Real Estate", "Marketing Services", "IT Services", "Financial Services", "Retail", "Construction"],
      company_size: ["1-10", "11-50", "51-200", "201-500", "501-1000", "1001+"],
      country: COUNTRIES,
      device: ["mobile", "desktop"],
    },
    formats: ["single_image", "video", "document", "lead_gen_form"],
    cpm: [18, 45], ctr: [0.4, 1.1], cvr: [6, 18], leadGen: true,
  },
  SNAPCHAT: {
    objectives: ["AWARENESS", "TRAFFIC", "APP_INSTALLS", "LEADS"],
    adSetNames: ["Gen Z – KSA", "Lifestyle 18-34", "Lookalike – Purchasers", "Sports fans", "Retargeting"],
    targeting: (r) => dedupe([
      { type: "age", value: pick(r, ["18-24", "18-34", "21-35"]) },
      { type: "gender", value: pick(r, ["All", "Female", "Male"]) },
      { type: "geo", value: pick(r, ["Saudi Arabia", "Egypt", "UAE", "Kuwait"]) },
      { type: "interest", value: pick(r, ["Fashion & Style Gurus", "Foodies", "Sports Fans", "Travel Enthusiasts", "Beauty Mavens"]) },
      { type: "interest", value: pick(r, ["Shoppers", "Gamers", "Movie Lovers", "Fitness Enthusiasts"]) },
      { type: "device", value: pick(r, ["iOS & Android", "iOS"]) },
    ]),
    dims: {
      age: ["13-17", "18-20", "21-24", "25-34", "35+"],
      gender: ["male", "female", "unknown"],
      country: COUNTRIES,
      os: ["iOS", "Android"],
    },
    formats: ["snap_ad", "story_ad", "collection"],
    cpm: [1.5, 4.5], ctr: [0.4, 1.2], cvr: [2, 8], leadGen: false,
  },
  TIKTOK: {
    objectives: ["REACH", "TRAFFIC", "VIDEO_VIEWS", "LEAD_GENERATION", "CONVERSIONS"],
    adSetNames: ["Interest – Beauty", "Broad 18-34", "Creators lookalike", "Hashtag – #realestate", "Spark ads – UGC"],
    targeting: (r) => dedupe([
      { type: "age", value: pick(r, ["18-24", "25-34", "18-34", "25-44"]) },
      { type: "gender", value: pick(r, ["All", "Female", "Male"]) },
      { type: "geo", value: pick(r, ["Egypt", "Saudi Arabia", "UAE"]) },
      { type: "interest", value: pick(r, ["Beauty & Personal Care", "Home Improvement", "Food & Beverage", "Travel", "Apparel & Accessories"]) },
      { type: "interest", value: pick(r, ["Games", "Tech & Electronics", "Sports & Outdoor", "Financial Services"]) },
      { type: "behavior", value: pick(r, ["Video interactions: Beauty", "Creator interactions: Followed", "Hashtag: #realestate"]) },
    ]),
    dims: {
      age: ["18-24", "25-34", "35-44", "45-54", "55+"],
      gender: ["male", "female", "unknown"],
      country: COUNTRIES,
      platform: ["ANDROID", "IOS", "PC"],
      interest_category: ["Beauty & Personal Care", "Home Improvement", "Food & Beverage", "Travel", "Apparel & Accessories", "Games"],
    },
    formats: ["in_feed_video", "spark_ad", "topview"],
    cpm: [1.8, 5], ctr: [0.6, 1.8], cvr: [2, 9], leadGen: true,
  },
};

function dedupe(items: RemoteTargetingItem[]): RemoteTargetingItem[] {
  const seen = new Set<string>();
  return items.filter((i) => {
    const k = `${i.type}|${i.value}|${i.exclude ? 1 : 0}`;
    if (seen.has(k)) return false;
    seen.add(k);
    return true;
  });
}

const HEADLINES = [
  "Own your home with 10% down", "Book a free consultation today", "Limited units — prices rise next month",
  "See why 2,000 families chose us", "Get the full brochure", "Your growth partner in MENA",
  "Summer offer: 0% installments", "Watch the 30-second tour",
];
const BODIES = [
  "Flexible payment plans up to 8 years. Prime location, ready to move.",
  "Talk to an expert and get a tailored plan in 24 hours.",
  "Trusted by leading brands across Egypt and the Gulf.",
  "Register now and get exclusive early-bird pricing.",
];
const CTAS = ["LEARN_MORE", "SIGN_UP", "GET_QUOTE", "CONTACT_US", "SHOP_NOW", "BOOK_NOW"];

// ───────── structure ─────────

type Built = { campaigns: RemoteCampaign[]; adSets: RemoteAdSet[]; ads: RemoteAd[] };
type SplitTests = NonNullable<SyncContext["splitTests"]>;


/** Hidden "true" strength of a segment in demo data, so reported, measured and estimated results agree with each other. */
export function segmentQuality(value: string): number {
  return 0.45 + (hash(`quality:${value}`) % 1000) / 1000 * 1.35;
}

function setQuality(s: RemoteAdSet): number {
  const seg = s.targeting.filter((t) => SEGMENT_TYPES.has(t.type) && !t.exclude);
  return seg.length ? seg.reduce((a, t) => a + segmentQuality(t.value), 0) / seg.length : 1;
}

export function buildStructure(platform: Platform, accountExternalId: string, splitTests: SplitTests = []): Built {
  const f = FLAVOURS[platform];
  const r = rng(`${platform}:${accountExternalId}:structure`);
  const campaigns: RemoteCampaign[] = [];
  const adSets: RemoteAdSet[] = [];
  const ads: RemoteAd[] = [];
  const nCamp = 3 + Math.floor(r() * 3);

  for (let c = 0; c < nCamp; c++) {
    const objective = f.objectives[c % f.objectives.length];
    const cid = `${accountExternalId}-c${c + 1}`;
    const season = pick(r, ["Q4", "Summer", "Always-on", "Launch", "Ramadan"]);
    campaigns.push({
      externalId: cid,
      name: `${season} | ${objective.replace(/^OUTCOME_/, "").replace(/_/g, " ").toLowerCase()} | ${pick(r, ["EG", "KSA", "UAE", "MENA"])}`,
      objective,
      status: r() > 0.2 ? "ACTIVE" : "PAUSED",
      dailyBudget: Math.round(between(r, 30, 250)),
    });
    const nSets = 2 + Math.floor(r() * 2);
    for (let s = 0; s < nSets; s++) {
      const sid = `${cid}-s${s + 1}`;
      const targeting = f.targeting(r);
      adSets.push({
        externalId: sid, campaignExternalId: cid, name: f.adSetNames[(c + s) % f.adSetNames.length],
        status: r() > 0.15 ? "ACTIVE" : "PAUSED",
        optimizationGoal: f.leadGen ? "LEAD_GENERATION" : "LINK_CLICKS",
        bidStrategy: pick(r, ["LOWEST_COST", "COST_CAP", "MAXIMIZE_CONVERSIONS", "TARGET_CPA"]),
        targetingRaw: { demo: true, items: targeting }, targeting,
      });
      const nAds = 2 + Math.floor(r() * 2);
      for (let a = 0; a < nAds; a++) {
        const format = pick(r, f.formats);
        ads.push({
          externalId: `${sid}-a${a + 1}`, adSetExternalId: sid, campaignExternalId: cid,
          name: `${format.replace(/_/g, " ")} – v${a + 1}`, status: r() > 0.2 ? "ACTIVE" : "PAUSED", format,
          headline: pick(r, HEADLINES), body: pick(r, BODIES), cta: pick(r, CTAS),
          landingUrl: "https://example.com/offer",
        });
      }
    }
  }

  // Split tests made from the dashboard: one active ad set per interest, same ads; the source is paused.
  for (const t of splitTests) {
    const src = adSets.find((x) => x.externalId === t.sourceAdSet);
    if (!src) continue;
    src.status = "PAUSED";
    const srcAds = ads.filter((a) => a.adSetExternalId === src.externalId);
    srcAds.forEach((a) => (a.status = "PAUSED"));
    for (const arm of t.arms) {
      const targeting = [...src.targeting.filter((x) => !SEGMENT_TYPES.has(x.type)), arm.interest];
      adSets.push({ ...src, externalId: arm.externalId, name: `${src.name} – ${arm.interest.value}`, status: "ACTIVE", targeting, targetingRaw: { demo: true, items: targeting, splitOf: src.externalId } });
      srcAds.forEach((a, k) => ads.push({ ...a, externalId: `${arm.externalId}-a${k + 1}`, adSetExternalId: arm.externalId, status: "ACTIVE" }));
    }
  }
  return { campaigns, adSets, ads };
}

// ───────── metrics ─────────

type AdDay = Required<Pick<Metrics, "spend" | "impressions" | "reach" | "clicks" | "linkClicks" | "conversions" | "leads" | "purchases" | "value" | "videoViews" | "engagements">>;

function adDay(platform: Platform, ad: RemoteAd, day: string, currency = "USD", quality = 1): AdDay {
  const f = FLAVOURS[platform];
  const base = rng(`${ad.externalId}:base`);
  const budget = between(base, 4, 40);
  const cpm = between(base, f.cpm[0], f.cpm[1]);
  const ctr = between(base, f.ctr[0], f.ctr[1]) / 100;
  const cvr = (between(base, f.cvr[0], f.cvr[1]) / 100) * quality;
  const aov = between(base, 40, 400);

  const r = rng(`${ad.externalId}:${day}`);
  const dow = new Date(day + "T00:00:00Z").getUTCDay();
  const weekday = dow === 5 ? 0.75 : dow === 4 ? 1.1 : 1; // Friday dip, Thursday peak (MENA week)
  if (ad.status === "PAUSED" && r() < 0.7) return { spend: 0, impressions: 0, reach: 0, clicks: 0, linkClicks: 0, conversions: 0, leads: 0, purchases: 0, value: 0, videoViews: 0, engagements: 0 };

  const usd = budget * weekday * between(r, 0.7, 1.3);
  const fx = FALLBACK[currency] ?? 1; // generate in USD, bill in the account currency
  const spend = round2(usd * fx);
  const impressions = Math.round((usd / cpm) * 1000);
  const reach = Math.round(impressions / between(r, 1.1, 1.9));
  const clicks = Math.round(impressions * ctr * between(r, 0.8, 1.2));
  const linkClicks = Math.round(clicks * between(r, 0.6, 0.9));
  const conv = Math.round(clicks * cvr * between(r, 0.6, 1.4));
  const isLeads = f.leadGen || /LEAD/.test(ad.campaignExternalId);
  const purchases = isLeads ? 0 : Math.round(conv * 0.4);
  return {
    spend, impressions, reach, clicks, linkClicks,
    conversions: conv, leads: isLeads ? conv : Math.round(conv * 0.6), purchases,
    value: round2(purchases * aov * fx),
    videoViews: /video|reel|story|snap|topview|spark/.test(ad.format ?? "") ? Math.round(impressions * between(r, 0.2, 0.45)) : 0,
    engagements: Math.round(clicks * between(r, 1.2, 2.5)),
  };
}

function sum(into: AdDay, x: AdDay) {
  for (const k of Object.keys(x) as (keyof AdDay)[]) into[k] += x[k];
}

const zero = (): AdDay => ({ spend: 0, impressions: 0, reach: 0, clicks: 0, linkClicks: 0, conversions: 0, leads: 0, purchases: 0, value: 0, videoViews: 0, engagements: 0 });

/** Stable, dimension-specific weights so e.g. 25-34 is always the biggest age bucket. */
function weights(dim: string, values: string[], seed: string, day: string): number[] {
  const shape: Record<string, number[]> = {
    age: [0.9, 2.6, 2.1, 1.2, 0.6, 0.3, 0.1],
    gender: [1.6, 1.3, 0.1],
    hour: Array.from({ length: 24 }, (_, h) => (h < 7 ? 0.25 : h < 12 ? 0.8 : h < 18 ? 1 : h < 23 ? 1.5 : 0.6)),
  };
  const s = rng(`${seed}:${dim}`);
  const d = rng(`${seed}:${dim}:${day}`);
  return values.map((_, i) => (shape[dim]?.[i] ?? between(s, 0.3, 2)) * between(d, 0.85, 1.15));
}

function splitMetrics(m: AdDay, w: number[]): AdDay[] {
  const parts = w.map(() => zero());
  const ints: (keyof AdDay)[] = ["impressions", "reach", "clicks", "linkClicks", "conversions", "leads", "purchases", "videoViews", "engagements"];
  for (const k of ints) splitInt(m[k], w).forEach((v, i) => (parts[i][k] = v));
  const cents = splitInt(Math.round(m.spend * 100), w);
  const val = splitInt(Math.round(m.value * 100), w);
  parts.forEach((p, i) => { p.spend = cents[i] / 100; p.value = val[i] / 100; });
  return parts;
}

/** Which ad-set targeting types each platform reports results for, and under which dimension. */
const REPORTED_SEGMENTS: Partial<Record<Platform, Record<string, string>>> = {
  GOOGLE: { audience: "seg_audience", interest: "seg_interest" },
  TIKTOK: { interest: "seg_interest" },
  SNAPCHAT: { interest: "seg_interest" },
  LINKEDIN: { job_title: "seg_job_title", seniority: "seg_seniority", industry: "seg_industry", company_size: "seg_company_size" },
};

export function buildStats(platform: Platform, accountExternalId: string, from: string, to: string, currency = "USD", splitTests: SplitTests = []): StatsBundle {
  const { campaigns, adSets, ads } = buildStructure(platform, accountExternalId, splitTests);
  const quality = new Map(adSets.map((x) => [x.externalId, setQuality(x)]));
  const f = FLAVOURS[platform];
  const stats: RemoteStat[] = [];
  const breakdowns: RemoteBreakdown[] = [];
  const keywords: RemoteKeyword[] = [];
  const searchTerms: RemoteSearchTerm[] = [];
  const placements: RemotePlacement[] = [];

  for (const day of eachDay({ from, to })) {
    const acc = zero();
    const byCamp = new Map<string, AdDay>();
    const bySet = new Map<string, AdDay>();
    for (const ad of ads) {
      const m = adDay(platform, ad, day, currency, quality.get(ad.adSetExternalId) ?? 1);
      stats.push({ level: "AD", externalId: ad.externalId, date: day, ...m });
      sum(acc, m);
      if (!byCamp.has(ad.campaignExternalId)) byCamp.set(ad.campaignExternalId, zero());
      if (!bySet.has(ad.adSetExternalId)) bySet.set(ad.adSetExternalId, zero());
      sum(byCamp.get(ad.campaignExternalId)!, m);
      sum(bySet.get(ad.adSetExternalId)!, m);
    }
    stats.push({ level: "ACCOUNT", externalId: accountExternalId, date: day, ...acc });
    for (const [id, m] of bySet) stats.push({ level: "ADSET", externalId: id, date: day, ...m });

    // Platforms that report per segment: split each ad set's day across its targeted segments,
    // delivery evenly-ish, results by each segment's strength.
    const reported = REPORTED_SEGMENTS[platform];
    if (reported) {
      for (const set of adSets) {
        const m = bySet.get(set.externalId);
        if (!m || !m.impressions) continue;
        for (const [type, dimension] of Object.entries(reported)) {
          const segs = set.targeting.filter((t) => t.type === type && !t.exclude);
          if (!segs.length) continue;
          const parts = splitMetrics(m, weights(dimension, segs.map((x) => x.value), set.externalId, day));
          const q = segs.map((x, i) => segmentQuality(x.value) * (parts[i].clicks + 1));
          const conv = splitInt(m.conversions, q), leads = splitInt(m.leads, q);
          segs.forEach((x, i) => breakdowns.push({ level: "ADSET", externalId: set.externalId, date: day, dimension, bucket: x.value, ...parts[i], conversions: conv[i], leads: leads[i] }));
        }
      }
    }

    for (const [cid, m] of byCamp) {
      stats.push({ level: "CAMPAIGN", externalId: cid, date: day, ...m });
      const dims: Record<string, string[]> = { ...f.dims };
      if (platform === "META") {
        const campAds = ads.filter((a) => a.campaignExternalId === cid);
        dims.asset_title = [...new Set(campAds.map((a) => a.headline ?? ""))].filter(Boolean);
        dims.asset_body = [...new Set(campAds.map((a) => a.body ?? ""))].filter(Boolean);
      }
      if (dims.age && dims.gender) {
        // age × gender as one combined dimension for the heat grid
        dims.age_gender = dims.age.flatMap((a) => dims.gender.filter((g) => g !== "unknown" && g !== "undetermined").map((g) => `${a}|${g}`));
      }
      for (const [dim, values] of Object.entries(dims)) {
        const parts = splitMetrics(m, weights(dim, values, cid, day));
        values.forEach((v, i) => {
          const p = parts[i];
          if (p.impressions === 0 && p.spend === 0) return;
          breakdowns.push({ level: "CAMPAIGN", externalId: cid, date: day, dimension: dim, bucket: v, ...p });
        });
      }

      if (platform === "GOOGLE") {
        const camp = campaigns.find((c) => c.externalId === cid)!;
        const sets = adSets.filter((s) => s.campaignExternalId === cid);
        if (camp.objective === "SEARCH" || camp.objective === "PERFORMANCE_MAX") {
          for (const s of sets) {
            const sm = bySet.get(s.externalId) ?? zero();
            const kws = s.targeting.filter((t) => t.type === "keyword");
            const parts = splitMetrics(sm, weights("kw", kws.map((k) => k.value), s.externalId, day));
            kws.forEach((k, i) => {
              const r = rng(`${s.externalId}:${k.value}`);
              const matchType = pick(r, ["EXACT", "PHRASE", "BROAD"]);
              keywords.push({
                externalId: `${s.externalId}-kw${i}`, campaignExternalId: cid, adSetExternalId: s.externalId,
                text: k.value, matchType, status: "ENABLED", qualityScore: 3 + Math.floor(r() * 8), date: day,
                ...parts[i],
              });
              const variants = [k.value, `${k.value} price`, `best ${k.value}`, `${k.value} near me`];
              const tp = splitMetrics(parts[i], weights("st", variants, `${s.externalId}:${k.value}`, day));
              variants.forEach((term, j) => {
                if (tp[j].impressions === 0) return;
                searchTerms.push({ campaignExternalId: cid, adSetExternalId: s.externalId, term, keywordText: k.value, matchType, date: day, ...tp[j] });
              });
            });
          }
        }
        if (camp.objective === "DISPLAY" || camp.objective === "VIDEO" || camp.objective === "PERFORMANCE_MAX") {
          const sites = camp.objective === "VIDEO"
            ? [["youtube_channel", "UC-demo-1", "Cairo Property TV"], ["youtube_channel", "UC-demo-2", "Tech Reviews Arabia"], ["youtube_video", "dQw-demo", "Top 10 compounds in New Cairo"], ["youtube_channel", "UC-demo-3", "Kids Songs Arabic"]]
            : [["website", "youm7.com", "youm7.com"], ["website", "masrawy.com", "masrawy.com"], ["mobile_app", "mobileapp::2-com.whatsapp", "Puzzle game app"], ["website", "elwatannews.com", "elwatannews.com"], ["website", "filgoal.com", "filgoal.com"], ["mobile_app", "mobileapp::1-123456", "Weather Live"]];
          const pp = splitMetrics(m, weights("pl", sites.map((s) => s[1]), cid, day));
          sites.forEach(([kind, target, display], i) => placements.push({ campaignExternalId: cid, kind, target, display, date: day, ...pp[i] }));
        }
      }
    }
  }
  return { stats, breakdowns, keywords, searchTerms, placements };
}

export function buildLeads(platform: Platform, accountExternalId: string, from: string, to: string, currency = "USD", splitTests: SplitTests = []): RemoteLead[] {
  if (!FLAVOURS[platform].leadGen) return [];
  const { ads, adSets } = buildStructure(platform, accountExternalId, splitTests);
  const quality = new Map(adSets.map((x) => [x.externalId, setQuality(x)]));
  const out: RemoteLead[] = [];
  const first = ["Ahmed", "Mona", "Omar", "Sara", "Youssef", "Nour", "Karim", "Laila", "Mostafa", "Hana"];
  const last = ["Hassan", "Ali", "Mahmoud", "Ibrahim", "Fathy", "Saeed", "Adel", "Kamal"];
  for (const day of eachDay({ from, to })) {
    for (const ad of ads) {
      const n = Math.min(adDay(platform, ad, day, currency, quality.get(ad.adSetExternalId) ?? 1).leads, 6); // a sample, not every lead
      for (let i = 0; i < n; i++) {
        const r = rng(`${ad.externalId}:${day}:lead${i}`);
        const fn = pick(r, first), ln = pick(r, last);
        out.push({
          externalId: `${ad.externalId}-${day}-${i}`, campaignExternalId: ad.campaignExternalId,
          adSetExternalId: ad.adSetExternalId, adExternalId: ad.externalId, formName: "Lead form – Brochure",
          createdAt: `${day}T${String(8 + Math.floor(r() * 14)).padStart(2, "0")}:${String(Math.floor(r() * 60)).padStart(2, "0")}:00Z`,
          fields: {
            full_name: `${fn} ${ln}`, phone_number: `+2010${Math.floor(10000000 + r() * 89999999)}`,
            email: `${fn.toLowerCase()}.${ln.toLowerCase()}@example.com`, city: pick(r, REGIONS),
            budget: pick(r, ["< 2M", "2M – 5M", "5M – 10M", "> 10M"]),
          },
        });
      }
    }
  }
  return out;
}

export function demoAccounts(platform: Platform, seed: string) {
  const r = rng(`${platform}:${seed}:accounts`);
  const n = 2 + Math.floor(r() * 2);
  const currency = platform === "LINKEDIN" ? "USD" : pick(r, ["USD", "EGP", "SAR"]);
  return Array.from({ length: n }, (_, i) => ({
    externalId: `demo-${platform.toLowerCase()}-${hash(seed + i) % 100000}`,
    name: `${pick(r, ["Main", "Brand", "Performance", "Real Estate", "E-commerce"])} account ${i + 1}`,
    currency, timezone: "Africa/Cairo",
  }));
}

export function demoConnector(platform: Platform): Connector {
  return {
    platform,
    configured: () => true,
    authUrl: (state) => `/api/oauth/${platform.toLowerCase()}/callback?demo=1&state=${encodeURIComponent(state)}&code=demo`,
    exchange: async (code) => ({ accessToken: `demo-token-${platform}`, externalUser: `demo-${code}`, label: `${platform} demo login` }),
    listAccounts: async (token) => demoAccounts(platform, token),
    fetchStructure: async (ctx: SyncContext) => buildStructure(platform, ctx.account.externalId, ctx.splitTests),
    fetchStats: async (ctx, from, to) => buildStats(platform, ctx.account.externalId, from, to, ctx.account.currency, ctx.splitTests),
    fetchLeads: async (ctx, from, to) => buildLeads(platform, ctx.account.externalId, from, to, ctx.account.currency, ctx.splitTests),
  };
}
