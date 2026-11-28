import type { z } from "zod";
import type { eventSchema } from "@/modules/api/schemas";
import type { EventInput } from "@/modules/customers/events";

export function toInput(e: z.infer<typeof eventSchema>): EventInput {
  return {
    eventId: e.event_id, type: e.type, occurredAt: e.occurred_at ? new Date(e.occurred_at) : undefined, source: e.source,
    customerId: e.customer_id, ids: e.identifiers, companyId: e.company_id ?? null, project: e.project, title: e.title,
    value: e.value, currency: e.currency, properties: e.properties ?? undefined,
  };
}
