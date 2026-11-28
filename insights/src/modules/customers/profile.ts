/**
 * Customer profile rules — pure, unit-tested: funnel stage, the transparent
 * scores (each with the reasons behind it), project detection, budgets and
 * reading contact details out of lead forms.
 */

// ───────── vocabulary ─────────

/** Events the website SDK, ad leads, CRM and WhatsApp feed into the timeline. */
export const EVENT_LABEL: Record<string, string> = {
  lead: "Ad lead form", project_view: "Viewed a project", unit_view: "Viewed a unit", price_view: "Viewed prices",
  payment_plan_view: "Viewed the payment plan", brochure_download: "Downloaded a brochure", video_play: "Played a video", video_complete: "Watched a video to the end",
  form_submit: "Sent a website form", whatsapp_click: "Clicked WhatsApp", call_click: "Clicked to call", meeting_booking: "Booked a visit online",
  qualified: "Qualified by sales", meeting_booked: "Meeting booked", meeting_attended: "Meeting attended", meeting_no_show: "Missed the meeting",
  reservation: "Reservation", purchase: "Purchase", lost: "Marked lost", crm_lead: "Added to the CRM", stage_change: "CRM stage changed",
  whatsapp_in: "WhatsApp message received", whatsapp_out: "WhatsApp message sent", consent: "Consent updated", identify: "Identified on the website", note: "Note",
};

export const STAGES = ["visitor", "engaged", "lead", "qualified", "meeting_booked", "meeting_attended", "reservation", "purchase", "owner", "lost", "cold"] as const;
export type Stage = (typeof STAGES)[number];
export const STAGE_LABEL: Record<Stage, string> = {
  visitor: "Visitor", engaged: "Engaged visitor", lead: "Lead", qualified: "Qualified", meeting_booked: "Meeting booked", meeting_attended: "Meeting attended",
  reservation: "Reservation", purchase: "Purchase", owner: "Owner", lost: "Lost", cold: "Cold",
};

/** The funnel order (lost and cold are exits, not steps). */
const RANK: Partial<Record<Stage, number>> = { visitor: 0, engaged: 1, lead: 2, qualified: 3, meeting_booked: 4, meeting_attended: 5, reservation: 6, purchase: 7, owner: 8 };

/** Which stage an event proves. */
const EVENT_STAGE: Record<string, Stage> = {
  lead: "lead", form_submit: "lead", crm_lead: "lead", identify: "lead", whatsapp_in: "lead",
  qualified: "qualified", meeting_booking: "meeting_booked", meeting_booked: "meeting_booked", meeting_attended: "meeting_attended",
  reservation: "reservation", purchase: "purchase",
};

const KEY_WEB = new Set(["project_view", "unit_view", "price_view", "payment_plan_view", "brochure_download", "video_play", "video_complete", "whatsapp_click", "call_click"]);

export type Ev = { type: string; occurredAt: Date; props?: Record<string, unknown>; projectId?: string | null; source?: string };
export type Visit = { startedAt: Date; pageviews: number };

const DAY = 86_400_000;

/** The furthest funnel step reached; lost when the last sales outcome was "lost"; cold after 60 quiet days before a reservation. */
export function stageOf(events: Ev[], visits: Visit[], now = new Date()): Stage {
  let best: Stage = "visitor";
  for (const e of events) {
    const s = EVENT_STAGE[e.type];
    if (s && (RANK[s] ?? 0) > (RANK[best] ?? 0)) best = s;
  }
  // Two purchases (or a purchase and a later reservation) make an owner coming back.
  if (events.filter((e) => e.type === "purchase").length >= 2) best = "owner";
  if (best === "visitor" && (visits.length >= 2 || events.some((e) => KEY_WEB.has(e.type)))) best = "engaged";

  const outcomes = events.filter((e) => e.type === "lost" || (EVENT_STAGE[e.type] && e.source === "crm")).sort((a, b) => +a.occurredAt - +b.occurredAt);
  if (outcomes.at(-1)?.type === "lost" && (RANK[best] ?? 0) < (RANK.purchase ?? 0)) return "lost";

  const last = Math.max(0, ...events.map((e) => +e.occurredAt), ...visits.map((v) => +v.startedAt));
  if (last && now.getTime() - last > 60 * DAY && (RANK[best] ?? 0) < (RANK.reservation ?? 0)) return "cold";
  return best;
}

// ───────── scores ─────────

export type Why = { label: string; points: number }[];

/** Intent points per event, counted once per type per day and fading over 30 days. */
const INTENT: Record<string, number> = {
  payment_plan_view: 18, price_view: 12, unit_view: 8, project_view: 4, brochure_download: 12, video_complete: 6, video_play: 3,
  whatsapp_click: 12, call_click: 14, form_submit: 15, lead: 15, meeting_booking: 25, whatsapp_in: 10, meeting_booked: 25, qualified: 15,
};

/**
 * Purchase intent 0–100 from the last 30 days: what they looked at and did, more
 * for recent and repeated actions. Each contribution is listed so the number can be checked.
 * After a reservation or purchase it is reported as 100 (already converted).
 */
export function intentScore(events: Ev[], visits: Visit[], now = new Date()): { score: number; why: Why } {
  if (events.some((e) => e.type === "reservation" || e.type === "purchase")) return { score: 100, why: [{ label: "Already reserved or bought", points: 100 }] };
  const since = now.getTime() - 30 * DAY;
  const why = new Map<string, number>();
  const seen = new Set<string>();
  for (const e of events) {
    const pts = INTENT[e.type];
    const t = +e.occurredAt;
    if (!pts || t < since) continue;
    const k = `${e.type}:${new Date(t).toISOString().slice(0, 10)}`;
    if (seen.has(k)) continue;
    seen.add(k);
    const fade = 1 - ((now.getTime() - t) / (30 * DAY)) * 0.6; // today 100%, 30 days ago 40%
    const label = EVENT_LABEL[e.type] ?? e.type;
    why.set(label, (why.get(label) ?? 0) + pts * fade);
  }
  const recent = visits.filter((v) => +v.startedAt >= now.getTime() - 7 * DAY);
  if (recent.length >= 2) why.set(`${recent.length} website visits in 7 days`, Math.min(20, recent.length * 5));
  const lost = events.some((e) => e.type === "lost" && +e.occurredAt >= since);
  if (lost) why.set("Marked lost by sales", -30);
  const list = [...why.entries()].map(([label, p]) => ({ label, points: Math.round(p) })).filter((x) => x.points !== 0).sort((a, b) => b.points - a.points);
  return { score: clamp(list.reduce((a, x) => a + x.points, 0)), why: list };
}

/**
 * Customer value 0–100: stated budget against the price of the projects they look at,
 * plus what they already bought. Without a budget or a price it says so instead of guessing.
 */
export function valueScore(p: { budget: number | null; prices: { from: number | null; to: number | null }[]; purchases: number; revenue: number }): { score: number; why: Why } {
  const why: Why = [];
  if (p.purchases > 0) why.push({ label: `${p.purchases} purchase${p.purchases > 1 ? "s" : ""}`, points: Math.min(60, 40 + 10 * (p.purchases - 1)) });
  const top = Math.max(0, ...p.prices.map((x) => x.to ?? x.from ?? 0));
  const low = Math.min(...p.prices.map((x) => x.from ?? x.to ?? Infinity));
  if (p.budget && top) {
    if (p.budget >= top) why.push({ label: "Budget covers the top of the project's price range", points: 40 });
    else if (Number.isFinite(low) && p.budget >= low) why.push({ label: "Budget inside the project's price range", points: 30 });
    else why.push({ label: "Budget below the project's prices", points: 10 });
  } else if (p.budget) why.push({ label: "Budget given (no project price to compare)", points: 20 });
  else why.push({ label: "No budget known yet", points: 0 });
  return { score: clamp(why.reduce((a, x) => a + x.points, 0)), why };
}

/** Identity confidence: the strongest identifier, plus a little for each further independent source. */
export function identityScore(ids: { strength: number; source: string; label: string }[]): { score: number; why: Why } {
  if (!ids.length) return { score: 0, why: [] };
  const best = ids.reduce((a, b) => (b.strength > a.strength ? b : a));
  const sources = new Set(ids.map((i) => i.source).filter(Boolean));
  const why: Why = [{ label: `Strongest identifier: ${best.label}`, points: best.strength }];
  if (sources.size > 1) why.push({ label: `Seen in ${sources.size} sources`, points: Math.min(10, (sources.size - 1) * 5) });
  return { score: clamp(why.reduce((a, x) => a + x.points, 0)), why };
}

const clamp = (n: number) => Math.max(0, Math.min(100, Math.round(n)));

// ───────── projects ─────────

export type ProjectRef = { id: string; name: string; slug: string; aliases: string[] };

const flat = (s: string) => s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9؀-ۿ]+/g, " ").trim();

/** Projects named in a URL, campaign name, form name or answer (by name, slug or alias, as whole words). */
export function matchProjects(text: string, projects: ProjectRef[]): string[] {
  const t = ` ${flat(text)} `;
  if (t.trim() === "") return [];
  return projects.filter((p) => [p.name, p.slug, ...p.aliases].map(flat).filter((x) => x.length >= 3).some((x) => t.includes(` ${x} `))).map((p) => p.id);
}

// ───────── budgets and lead forms ─────────

/** "2M – 5M", "3,500,000", "5 million", "< 2M", "٣ مليون" → a number (midpoint of a range), or null. */
export function parseBudget(s: string): number | null {
  const txt = String(s ?? "").replace(/[٠-٩]/g, (d) => String("٠١٢٣٤٥٦٧٨٩".indexOf(d))).toLowerCase().replace(/,/g, "");
  const nums = [...txt.matchAll(/(\d+(?:\.\d+)?)\s*(k|m|mn|million|mio|b|bn|billion|ألف|الف|مليون|مليار)?/g)].map((m) => {
    const n = Number(m[1]);
    const u = m[2] ?? "";
    const mult = /^(k|ألف|الف)$/.test(u) ? 1e3 : /^(m|mn|million|mio|مليون)$/.test(u) ? 1e6 : /^(b|bn|billion|مليار)$/.test(u) ? 1e9 : 1;
    return n * mult;
  }).filter((n) => n > 0);
  if (!nums.length) return null;
  // A unit written once applies to the whole range ("2 – 5M").
  if (nums.length === 2 && nums[0] < 1000 && nums[1] >= 1e6) nums[0] *= nums[1] >= 1e9 ? 1e9 : 1e6;
  if (nums.length >= 2) return (nums[0] + nums[1]) / 2;
  return nums[0];
}

/** Contact details and the other answers out of a lead form's fields (any platform's field names). */
export function leadContact(fields: Record<string, unknown>): { name: string; phone: string; email: string; city: string; budget: string; answers: Record<string, string> } {
  const get = (re: RegExp) => Object.entries(fields).find(([k, v]) => re.test(k) && typeof v === "string" && v.trim())?.[1] as string | undefined;
  const first = get(/^first_?name$/i), last = get(/^last_?name$/i);
  const name = get(/^(full_?name|name)$/i) ?? [first, last].filter(Boolean).join(" ");
  const phone = get(/phone|mobile|whats_?app|tel/i) ?? "";
  const email = get(/e-?mail/i) ?? "";
  const city = get(/^(city|area|location|region)$/i) ?? "";
  const budget = get(/budget|price|الميزانية/i) ?? "";
  const answers: Record<string, string> = {};
  for (const [k, v] of Object.entries(fields)) {
    if (/name|phone|mobile|whats_?app|tel|e-?mail/i.test(k)) continue; // contact details are stored encrypted, not in the timeline
    if (typeof v === "string" || typeof v === "number") answers[k] = String(v).slice(0, 200);
  }
  return { name: name.trim().slice(0, 120), phone, email, city: city.slice(0, 80), budget: budget.slice(0, 60), answers };
}

/** Is this an event worth putting on the timeline from a website visit? */
export const isKeyWebEvent = (name: string) => KEY_WEB.has(name) || name === "meeting_booking" || name === "reservation" || name === "purchase" || name === "form_submit";
