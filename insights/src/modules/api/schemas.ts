import { z } from "zod";
import { ID_TYPES } from "@/modules/identity/rules";
import { STAGES } from "@/modules/customers/profile";

/** Request bodies for /api/v1 — validated at runtime and published in the OpenAPI document. */

export const identifierSchema = z.object({
  type: z.enum(ID_TYPES).describe("crm_id | account_id | whatsapp_id | phone | email | lead_id | anon_id"),
  value: z.string().min(1).max(200),
  verified: z.boolean().optional().describe("The source checked it (e.g. sales called the number). Unverified phones and emails match, but merge only with stronger proof."),
});

export const identifySchema = z.object({
  identifiers: z.array(identifierSchema).min(1).max(20),
  company_id: z.string().optional().describe("Company the record belongs to (needed for agency workspaces)."),
  source: z.string().max(60).default("api"),
  profile: z.object({ name: z.string().max(120).optional(), city: z.string().max(80).optional(), budget: z.string().max(60).optional() }).optional(),
  consent: z.object({ analytics: z.boolean().optional(), ads: z.boolean().optional(), marketing: z.boolean().optional(), whatsapp: z.boolean().optional() }).optional(),
});

export const EVENT_TYPES = [
  "project_view", "unit_view", "price_view", "payment_plan_view", "brochure_download", "video_play", "video_complete", "form_submit", "whatsapp_click", "call_click",
  "meeting_booking", "crm_lead", "qualified", "meeting_booked", "meeting_attended", "meeting_no_show", "reservation", "purchase", "lost", "stage_change",
  "whatsapp_in", "whatsapp_out", "note",
] as const;

export const eventSchema = z.object({
  event_id: z.string().min(1).max(120).describe("Your id for this event. Sending the same id again is ignored (deduplication)."),
  type: z.enum(EVENT_TYPES),
  occurred_at: z.string().datetime({ offset: true }).optional().describe("ISO 8601; defaults to now."),
  source: z.enum(["crm", "website", "whatsapp", "ads", "api"]).default("api"),
  customer_id: z.string().optional().describe("An existing customer id, or send identifiers."),
  identifiers: z.array(identifierSchema).max(20).optional(),
  company_id: z.string().optional(),
  project: z.string().max(120).optional().describe("Project name, slug or alias."),
  title: z.string().max(200).optional(),
  value: z.number().optional().describe("Amount for reservations and purchases."),
  currency: z.string().length(3).optional(),
  properties: z.record(z.string(), z.union([z.string().max(500), z.number(), z.boolean(), z.null()])).optional(),
}).refine((e) => e.customer_id || e.identifiers?.length, { message: "Send customer_id or identifiers." });

export const batchSchema = z.object({ events: z.array(eventSchema).min(1).max(500) });

export const listQuery = z.object({
  q: z.string().max(200).optional().describe("Name, or an exact phone / email."),
  stage: z.enum(STAGES).optional(),
  project: z.string().optional().describe("Project id."),
  min_intent: z.coerce.number().int().min(0).max(100).optional(),
  sort: z.enum(["recent", "intent", "value"]).optional(),
  limit: z.coerce.number().int().min(1).max(200).optional(),
  cursor: z.string().optional(),
});
