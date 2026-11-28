import { apiContext, apiError, ok } from "@/modules/api/http";
import { getCustomer } from "@/modules/customers/query";

export async function GET(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const ctx = await apiContext(req, "read");
  if (ctx instanceof Response) return ctx;
  const c = await getCustomer(ctx, (await params).id);
  if (!c) return apiError(404, "not_found", "No such customer in this workspace.");
  return ok({
    customerId: c.id, stage: c.stage,
    identityConfidence: { score: c.scores.identity, why: c.why.identity ?? [] },
    purchaseIntent: { score: c.scores.intent, why: c.why.intent ?? [] },
    customerValue: { score: c.scores.value, why: c.why.value ?? [] },
    conversionProbability: { score: null, why: [{ label: "Needs CRM outcomes (meetings, reservations, sales) to be measured from your own history", points: 0 }] },
    method: "rules-v1",
  });
}
