"use server";

import bcrypt from "bcryptjs";
import { headers } from "next/headers";
import { redirect } from "next/navigation";
import { revalidatePath } from "next/cache";
import { db } from "@/lib/db";
import { mailEnabled, sendMail } from "@/lib/mail";
import { loadSettings } from "@/lib/settings";
import { consumeResetToken, createResetToken, passwordProblem } from "@/lib/password-reset";
import { requireAdmin, requireViewer } from "@/lib/tenancy";

// Per-process limits on reset emails: 3 per address and 10 per IP an hour.
const sent = new Map<string, number[]>();
function allowed(key: string, max: number): boolean {
  const now = Date.now();
  const recent = (sent.get(key) ?? []).filter((t) => now - t < 60 * 60_000);
  if (recent.length >= max) { sent.set(key, recent); return false; }
  sent.set(key, [...recent, now]);
  return true;
}

async function clientIp() {
  return ((await headers()).get("x-forwarded-for") ?? "").split(",")[0].trim();
}

async function mailResetLink(user: { id: string; email: string; name: string }, ip: string) {
  const token = await createResetToken(user.id, ip);
  const link = `${process.env.APP_URL ?? "http://localhost:3000"}/reset-password?token=${encodeURIComponent(token)}`;
  await sendMail({
    to: user.email,
    subject: "Reset your Gildana Insights password",
    text: `Hello${user.name ? ` ${user.name}` : ""},\n\nUse this link to choose a new password. It works once and expires in 1 hour:\n\n${link}\n\nIf you didn't ask for this, ignore this email — your password stays the same.\n\nGildana Insights`,
    html: `<p>Hello${user.name ? ` ${user.name.replace(/[<>&]/g, "")}` : ""},</p><p>Use this link to choose a new password. It works once and expires in 1 hour:</p><p><a href="${link}">Choose a new password</a></p><p>If you didn't ask for this, ignore this email — your password stays the same.</p><p>Gildana Insights</p>`,
  });
}

/** Always answers the same way, so the form can't be used to find out which emails have accounts. */
export async function forgotPasswordAction(_: string | null, form: FormData): Promise<string> {
  const done = "If that email has an account, we've sent a link to reset the password. Check your inbox (and spam).";
  await loadSettings();
  if (!mailEnabled()) return done;
  const email = String(form.get("email") ?? "").toLowerCase().trim().slice(0, 190);
  const ip = await clientIp();
  if (!email || !allowed(`e:${email}`, 3) || !allowed(`i:${ip}`, 10)) return done;
  const user = await db.user.findUnique({ where: { email } });
  if (user?.active) {
    try { await mailResetLink(user, ip); } catch (e) { console.error("[reset mail]", (e as Error).message); }
  }
  return done;
}

export async function resetPasswordWithTokenAction(_: string | null, form: FormData): Promise<string | null> {
  const password = String(form.get("password") ?? "");
  const problem = passwordProblem(password, String(form.get("confirm") ?? ""));
  if (problem) return problem;
  const r = await consumeResetToken(String(form.get("token") ?? ""), password);
  if (!r.ok) return r.error;
  redirect("/login?reset=1");
}

/** Signed-in users change their own password and profile. */
export async function changePasswordAction(_: string | null, form: FormData): Promise<string> {
  const v = await requireViewer();
  const user = await db.user.findUniqueOrThrow({ where: { id: v.id } });
  if (!(await bcrypt.compare(String(form.get("current") ?? ""), user.passwordHash))) return "error:Your current password is not correct.";
  const password = String(form.get("password") ?? "");
  const problem = passwordProblem(password, String(form.get("confirm") ?? ""));
  if (problem) return `error:${problem}`;
  await db.user.update({ where: { id: v.id }, data: { passwordHash: await bcrypt.hash(password, 12) } });
  await db.passwordReset.deleteMany({ where: { userId: v.id, usedAt: null } });
  return "ok:Password changed.";
}

export async function updateProfileAction(form: FormData) {
  const v = await requireViewer();
  await db.user.update({
    where: { id: v.id },
    data: { name: String(form.get("name") ?? "").trim().slice(0, 120), locale: form.get("locale") === "ar" ? "ar" : "en" },
  });
  revalidatePath("/account");
}

/** Admin: email a user a reset link. */
export async function sendResetLinkAction(form: FormData) {
  await requireAdmin();
  if (!mailEnabled()) throw new Error("Email is not configured (SMTP_URL)");
  const user = await db.user.findUniqueOrThrow({ where: { id: String(form.get("userId")) } });
  await mailResetLink(user, await clientIp());
  revalidatePath("/admin/clients");
}
