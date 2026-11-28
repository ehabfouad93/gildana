import { z } from "zod";
import { batchSchema, eventSchema, identifySchema, listQuery } from "./schemas";

/** The OpenAPI 3.1 document for /api/v1, generated from the same zod schemas that validate requests. */

const json = (s: z.ZodType) => z.toJSONSchema(s, { target: "openapi-3.0", io: "input", unrepresentable: "any" }) as Record<string, unknown>;

const err = { description: "Error", content: { "application/json": { schema: { $ref: "#/components/schemas/Error" } } } };
const okJson = (description: string, ref?: string) => ({ description, content: { "application/json": { schema: ref ? { $ref: `#/components/schemas/${ref}` } : { type: "object" } } } });
const idParam = { name: "id", in: "path", required: true, schema: { type: "string" } };

export function openApi(baseUrl: string) {
  const query = json(listQuery) as { properties: Record<string, Record<string, unknown>> };
  return {
    openapi: "3.1.0",
    info: {
      title: "Gildana Insights API", version: "1.0.0",
      description: "Customer data platform API: unified customer profiles, identity resolution, timelines, scores and server-side events.\n\nAuthenticate with a workspace API key (Settings → API keys): `Authorization: Bearer gk_…`. Keys have scopes `read`, `write` and `pii` (contact details in responses). 600 calls a minute per key.\n\nIdentity is deterministic: records join on CRM ID, account ID, WhatsApp ID, phone or email only — never on IP address or behaviour. Weak matches go to a review queue instead of merging.",
    },
    servers: [{ url: `${baseUrl}/api/v1` }],
    security: [{ bearer: [] }],
    components: {
      securitySchemes: { bearer: { type: "http", scheme: "bearer", description: "Workspace API key (gk_…)" } },
      schemas: {
        Error: { type: "object", properties: { error: { type: "object", properties: { code: { type: "string" }, message: { type: "string" } } } } },
        Identify: json(identifySchema), Event: json(eventSchema), Batch: json(batchSchema),
        Customer: {
          type: "object",
          properties: {
            id: { type: "string" }, name: { type: "string" }, stage: { type: "string" }, phone: { type: "string", nullable: true, description: "Masked unless the key has the pii scope" },
            email: { type: "string", nullable: true }, scores: { type: "object", properties: { identity: { type: "integer" }, intent: { type: "integer" }, value: { type: "integer" } } },
            projectIds: { type: "array", items: { type: "string" } }, sources: { type: "array", items: { type: "string" } }, firstSeenAt: { type: "string", format: "date-time" }, lastSeenAt: { type: "string", format: "date-time" },
          },
        },
      },
    },
    paths: {
      "/customers": {
        get: {
          summary: "List customers", tags: ["Customers"],
          parameters: Object.entries(query.properties).map(([name, schema]) => ({ name, in: "query", required: false, schema, description: (schema as { description?: string }).description })),
          responses: { 200: okJson("A page of customers: { items, nextCursor }"), 401: err, 403: err },
        },
      },
      "/customers/{id}": { get: { summary: "One customer: profile, identifiers with match method, confidence and evidence, merges, consent, score explanations", tags: ["Customers"], parameters: [idParam], responses: { 200: okJson("Customer"), 404: err } } },
      "/customers/{id}/timeline": { get: { summary: "Chronological timeline: ad leads, website visits and key actions, CRM and WhatsApp events", tags: ["Customers"], parameters: [idParam, { name: "limit", in: "query", schema: { type: "integer", maximum: 500 } }], responses: { 200: okJson("{ items }"), 404: err } } },
      "/customers/{id}/scores": { get: { summary: "Identity confidence, purchase intent and value, each with the reasons behind it", tags: ["Customers"], parameters: [idParam], responses: { 200: okJson("Scores"), 404: err } } },
      "/customers/identify": {
        post: {
          summary: "Find or create the customer for these identifiers", description: "Attaches new identifiers, merges profiles only on strong deterministic proof, and queues doubtful matches for review. Needs the write scope.",
          tags: ["Customers"], requestBody: { required: true, content: { "application/json": { schema: { $ref: "#/components/schemas/Identify" } } } },
          responses: { 200: okJson("{ customer_id, created, merged, reviews }"), 400: err, 403: err },
        },
      },
      "/events/track": { post: { summary: "Record one server-side event (CRM stage, meeting, reservation, purchase, WhatsApp…)", tags: ["Events"], requestBody: { required: true, content: { "application/json": { schema: { $ref: "#/components/schemas/Event" } } } }, responses: { 200: okJson("{ customer_id, duplicate }"), 400: err } } },
      "/events/batch": { post: { summary: "Record up to 500 events", tags: ["Events"], requestBody: { required: true, content: { "application/json": { schema: { $ref: "#/components/schemas/Batch" } } } }, responses: { 200: okJson("{ accepted, duplicates, failed: [{ index, error }] }"), 400: err } } },
    },
  };
}
