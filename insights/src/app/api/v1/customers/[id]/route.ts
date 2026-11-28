import { apiContext, apiError, ok } from "@/modules/api/http";
import { getCustomer } from "@/modules/customers/query";

export async function GET(req: Request, { params }: { params: Promise<{ id: string }> }) {
  const ctx = await apiContext(req, "read");
  if (ctx instanceof Response) return ctx;
  const c = await getCustomer(ctx, (await params).id);
  return c ? ok(c) : apiError(404, "not_found", "No such customer in this workspace.");
}
