import { apiContext, apiError, ok } from "@/modules/api/http";
import { listQuery } from "@/modules/api/schemas";
import { listCustomers } from "@/modules/customers/query";

export async function GET(req: Request) {
  const ctx = await apiContext(req, "read");
  if (ctx instanceof Response) return ctx;
  const q = listQuery.safeParse(Object.fromEntries(new URL(req.url).searchParams));
  if (!q.success) return apiError(400, "invalid_query", q.error.issues.map((i) => `${i.path.join(".")}: ${i.message}`).join("; "));
  const { min_intent, ...rest } = q.data;
  return ok(await listCustomers(ctx, { ...rest, minIntent: min_intent }));
}
