import { apiContext, audit, body, ok } from "@/modules/api/http";
import { batchSchema } from "@/modules/api/schemas";
import { recordEvent } from "@/modules/customers/events";
import { refreshCustomer } from "@/modules/customers/service";
import { toInput } from "../input";

export async function POST(req: Request) {
  const ctx = await apiContext(req, "write");
  if (ctx instanceof Response) return ctx;
  const b = await body(req, batchSchema);
  if (b instanceof Response) return b;
  let accepted = 0, duplicates = 0;
  const failed: { index: number; error: string }[] = [];
  const touched = new Set<string>();
  for (const [i, e] of b.events.entries()) {
    try {
      const r = await recordEvent(ctx.clientId, toInput(e), { refresh: false });
      if (r.duplicate) duplicates++; else accepted++;
      touched.add(r.customerId);
    } catch (err) {
      failed.push({ index: i, error: (err as Error).message });
    }
  }
  // Each profile is recalculated once, after all its events are in.
  for (const id of touched) await refreshCustomer(id);
  await audit(ctx, "events.batch", "", { accepted, duplicates, failed: failed.length });
  return ok({ accepted, duplicates, failed });
}
