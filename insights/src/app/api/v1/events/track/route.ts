import { apiContext, apiError, audit, body, ok } from "@/modules/api/http";
import { eventSchema } from "@/modules/api/schemas";
import { recordEvent } from "@/modules/customers/events";
import { toInput } from "../input";

export async function POST(req: Request) {
  const ctx = await apiContext(req, "write");
  if (ctx instanceof Response) return ctx;
  const b = await body(req, eventSchema);
  if (b instanceof Response) return b;
  try {
    const r = await recordEvent(ctx.clientId, toInput(b));
    if (!r.duplicate) await audit(ctx, "events.track", r.customerId, { type: b.type, source: b.source });
    return ok({ customer_id: r.customerId, duplicate: r.duplicate });
  } catch (e) {
    return apiError(422, "not_recorded", (e as Error).message);
  }
}
