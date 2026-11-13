import { NextResponse } from "next/server";
import { publicUrl } from "@/lib/app-url";
import { db } from "@/lib/db";

/** The client's answer on an approval link: approve, reject or ask for changes. Only a pending request (or one sent back for changes) can be answered. */
export async function POST(req: Request, { params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const form = await req.formData();
  const decision = String(form.get("decision"));
  const status = decision === "approve" ? "approved" : decision === "reject" ? "rejected" : decision === "changes" ? "changes" : null;
  const back = (q: string) => NextResponse.redirect(publicUrl(`/a/${token}?${q}`, req), 303);
  if (!status) return back("e=1");
  const name = String(form.get("name") ?? "").trim().slice(0, 120);
  if (!name) return back("e=name");
  const n = await db.approval.updateMany({
    where: { token, launchedAt: null, status: { in: ["pending", "changes"] } },
    data: { status, contactName: name, comment: String(form.get("comment") ?? "").trim().slice(0, 2000), decidedAt: new Date() },
  });
  return back(n.count ? "done=1" : "e=closed");
}
