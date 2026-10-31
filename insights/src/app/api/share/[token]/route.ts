import bcrypt from "bcryptjs";
import { NextResponse } from "next/server";
import { db } from "@/lib/db";
import { shareCookie, unlockProof } from "@/lib/share";
import { publicUrl } from "@/lib/app-url";

/** Password check for a protected share link. */
export async function POST(req: Request, { params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const link = await db.shareLink.findUnique({ where: { token } });
  if (!link) return new NextResponse("Not found", { status: 404 });
  const pw = String((await req.formData()).get("password") ?? "");
  const ok = !!link.passwordHash && (await bcrypt.compare(pw, link.passwordHash));
  const res = NextResponse.redirect(publicUrl(`/s/${token}${ok ? "" : "?e=1"}`, req), 303);
  if (ok) res.cookies.set(shareCookie(token), unlockProof(link), { httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", path: `/s/${token}`, maxAge: 60 * 60 * 12 });
  return res;
}
