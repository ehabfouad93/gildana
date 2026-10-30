import { redirect } from "next/navigation";
import { getViewer } from "@/lib/tenancy";

export default async function Home() {
  const v = await getViewer();
  if (!v) redirect("/login");
  redirect(v.isAdmin && !v.clientId ? "/admin/clients" : "/overview");
}
