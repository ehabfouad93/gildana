import { redirect } from "next/navigation";
import { db } from "@/lib/db";
import { getViewer } from "@/lib/tenancy";

export default async function Home() {
  const v = await getViewer();
  if (!v) redirect("/login");
  if (v.isGildana && !v.clientId) redirect("/admin/clients");
  // A client's first visit goes to the setup wizard until they finish it.
  if (!v.isGildana && v.clientId && v.role !== "VIEWER") {
    const c = await db.client.findUnique({ where: { id: v.clientId }, select: { onboardedAt: true, _count: { select: { connections: true } } } });
    if (c && !c.onboardedAt && !c._count.connections) redirect("/welcome");
  }
  redirect("/overview");
}
