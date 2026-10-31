import bcrypt from "bcryptjs";
import { createHash } from "node:crypto";
import { db } from "@/lib/db";
import { randomToken } from "@/lib/crypto";

/** Reset links are single-use and expire after an hour; only a SHA-256 of the token is stored. */
export const RESET_TTL_MS = 60 * 60_000;
export const MIN_PASSWORD = 8;

export const hashToken = (token: string) => createHash("sha256").update(token).digest("hex");

export function isUsable(r: { expiresAt: Date; usedAt: Date | null } | null, now = new Date()): boolean {
  return !!r && !r.usedAt && r.expiresAt > now;
}

export function passwordProblem(pw: string, confirm?: string): string | null {
  if (pw.length < MIN_PASSWORD) return `Use at least ${MIN_PASSWORD} characters.`;
  if (pw.length > 200) return "That password is too long.";
  if (confirm !== undefined && pw !== confirm) return "The two passwords don't match.";
  return null;
}

/** Invitations stay valid for a week; reset links for an hour. */
export const INVITE_TTL_MS = 7 * 24 * 60 * 60_000;

export async function createResetToken(userId: string, ip = "", purpose: "reset" | "invite" = "reset"): Promise<string> {
  const token = randomToken(32);
  await db.$transaction([
    db.passwordReset.deleteMany({ where: { userId, usedAt: null } }),
    db.passwordReset.create({ data: { userId, purpose, tokenHash: hashToken(token), expiresAt: new Date(Date.now() + (purpose === "invite" ? INVITE_TTL_MS : RESET_TTL_MS)), ip: ip.slice(0, 64) } }),
  ]);
  return token;
}

/** The user a valid link belongs to, or null — used to show the form only for good links. */
export async function peekResetToken(token: string) {
  const r = await db.passwordReset.findUnique({ where: { tokenHash: hashToken(token) }, include: { user: { select: { email: true, active: true } } } });
  return isUsable(r) && r!.user.active ? r : null;
}

export async function consumeResetToken(token: string, password: string): Promise<{ ok: true; email: string } | { ok: false; error: string }> {
  const problem = passwordProblem(password);
  if (problem) return { ok: false, error: problem };
  const r = await peekResetToken(token);
  if (!r) return { ok: false, error: "This link has expired or was already used. Ask for a new one." };
  const hash = await bcrypt.hash(password, 12);
  // Marking the link used in the same statement that checks it stops two tabs from using one link.
  const done = await db.$transaction(async (tx) => {
    const claimed = await tx.passwordReset.updateMany({ where: { id: r.id, usedAt: null }, data: { usedAt: new Date() } });
    if (!claimed.count) return false;
    await tx.user.update({ where: { id: r.userId }, data: { passwordHash: hash } });
    await tx.passwordReset.deleteMany({ where: { userId: r.userId, usedAt: null } });
    return true;
  });
  return done ? { ok: true, email: r.user.email } : { ok: false, error: "This link was already used." };
}
