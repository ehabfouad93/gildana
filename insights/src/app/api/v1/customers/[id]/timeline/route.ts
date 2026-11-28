import { apiContext, apiError, ok } from "@/modules/api/http";
import { getCustomer } from "@/modules/customers/query";
import { timeline } from "@/modules/customers/service";

export async function GET(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const ctx = await apiContext(req, "read");
  if (ctx instanceof Response) return ctx;
  const c = await getCustomer(ctx, (await params).id);
  if (!c) return apiError(404, "not_found", "No such customer in this workspace.");
  const limit = Math.min(500, Math.max(1, Number(new URL(req.url).searchParams.get("limit")) || 200));
  return ok({ customerId: c.id, items: await timeline(c.id, limit) });
}
