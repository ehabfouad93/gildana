import { db } from "@/lib/db";
import { apiContext, apiError, audit, body, ok } from "@/modules/api/http";
import { identifySchema } from "@/modules/api/schemas";
import { identify } from "@/modules/identity/service";
import { recordConsent, refreshCustomer } from "@/modules/customers/service";

export async function POST(req: Request) {
  const ctx = await apiContext(req, "write");
  if (ctx instanceof Response) return ctx;
  const b = await body(req, identifySchema);
  if (b instanceof Response) return b;
  if (b.company_id && !(await db.company.findFirst({ where: { id: b.company_id, clientId: ctx.clientId, ...(ctx.companyIds ? { id: { in: ctx.companyIds } } : {}) } })))
    return apiError(400, "invalid_company", "company_id is not a company of this workspace.");
  const r = await identify({ clientId: ctx.clientId, companyId: b.company_id ?? null, ids: b.identifiers, source: b.source, profile: { name: b.profile?.name, city: b.profile?.city, budgetText: b.profile?.budget }, userId: ctx.userId });
  if (!r) return apiError(422, "not_identifying", "These identifiers don't identify anyone yet (an anonymous id alone).");
  if (b.consent) await recordConsent(ctx.clientId, r.customerId, b.consent, b.source);
  await refreshCustomer(r.customerId);
  await audit(ctx, "customers.identify", r.customerId, { created: r.created, merged: r.merged, reviews: r.reviews, types: b.identifiers.map((i) => i.type) });
  return ok({ customer_id: r.customerId, created: r.created, merged: r.merged, reviews: r.reviews });
}
