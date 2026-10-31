"use server";

import bcrypt from "bcryptjs";
import type { Platform } from "@prisma/client";
import { AuthError } from "next-auth";
import { revalidatePath } from "next/cache";
import { headers } from "next/headers";
import { redirect } from "next/navigation";
import { z } from "zod";
import { signIn } from "@/auth";
import { approveClaim, refreshClaimVisibility, rejectClaim, requestClaim } from "@/lib/claims";
import { db } from "@/lib/db";
import { encrypt, randomToken } from "@/lib/crypto";
import { fetchJson } from "@/lib/http";
import { mailEnabled, sendMail } from "@/lib/mail";
import { createResetToken, passwordProblem } from "@/lib/password-reset";
import { PLATFORMS } from "@/lib/platforms";
import { SETTING_KEYS } from "@/lib/platform-setup";
import { flag, loadSettings, saveSettings, setting } from "@/lib/settings";
import { canEdit, requireAdmin, requireWorkspace } from "@/lib/tenancy";
import { meta } from "@/platforms/meta";

const ip = async () => ((await headers()).get("x-forwarded-for") ?? "").split(",")[0].trim();

// ───────── Admin → Platforms ─────────

/** Saves the fields in the form. A blank secret keeps the saved value; "-" clears it. */
export async function savePlatformSettingsAction(form: FormData) {
  await requireAdmin();
  const values: Record<string, string> = {};
  for (const key of SETTING_KEYS) {
    if (!form.has(key)) continue;
    const all = form.getAll(key); // a checkbox after its hidden "false" sends both; the last one wins
    const v = String(all[all.length - 1] ?? "").trim();
    const secret = form.get(`${key}__secret`) === "1";
    if (secret && v === "") continue;
    values[key] = v === "-" ? "" : v;
  }
  for (const key of ["SIGNUP_ENABLED", "DEMO_MODE"]) if (form.has(`${key}__toggle`)) values[key] = form.get(key) === "on" ? "true" : "false";
  await saveSettings(values);
  revalidatePath("/admin/platforms");
}

/** Connects Gildana's Meta Business Manager through a system-user token (never expires). */
export async function connectMetaSystemUserAction(_: string | null, form: FormData): Promise<string> {
  const v = await requireAdmin();
  const token = String(form.get("token") ?? "").trim();
  if (token.length < 50) return "error:Paste the whole system user access token.";
  try {
    const me: { id: string; name: string } = await fetchJson(`https://graph.facebook.com/${setting("META_GRAPH_VERSION") || "v21.0"}/me?fields=id,name&access_token=${encodeURIComponent(token)}`);
    const accounts = await meta.listAccounts(token);
    const conn = await db.adConnection.create({
      data: { clientId: null, isAgency: true, userId: v.id, platform: "META", label: `Gildana · ${me.name} (system user)`, externalUser: me.id, tokenEnc: encrypt(token), scopes: "system_user" },
    });
    await db.adAccount.createMany({ skipDuplicates: true, data: accounts.map((a) => ({ connectionId: conn.id, platform: "META" as Platform, externalId: a.externalId, name: a.name, currency: a.currency, timezone: a.timezone })) });
    await refreshClaimVisibility("META");
    revalidatePath("/admin/platforms");
    return `ok:Connected ${me.name}: ${accounts.length} ad accounts visible.`;
  } catch (e) {
    return `error:Meta refused the token: ${(e as Error).message}`;
  }
}

export async function removeAgencyConnectionAction(form: FormData) {
  await requireAdmin();
  await db.adConnection.deleteMany({ where: { id: String(form.get("id")), isAgency: true } });
  revalidatePath("/admin/platforms");
}

export async function refreshSharedAction(form: FormData) {
  await requireAdmin();
  await refreshClaimVisibility(String(form.get("platform")) as Platform);
  revalidatePath("/admin/access");
  revalidatePath("/admin/platforms");
}

// ───────── Share access with Gildana ─────────

export async function requestClaimAction(_: string | null, form: FormData): Promise<string> {
  const v = await requireWorkspace();
  if (!canEdit(v)) return "error:Read-only users can't add accounts.";
  const platform = String(form.get("platform")) as Platform;
  if (!PLATFORMS.includes(platform)) return "error:Pick a platform.";
  const companyId = String(form.get("companyId") ?? "") || null;
  if (companyId && !(await db.company.findFirst({ where: { id: companyId, clientId: v.clientId } }))) return "error:Pick one of your companies.";
  try {
    const c = await requestClaim(v.clientId, platform, String(form.get("accountId") ?? ""), companyId);
    const fresh = await db.accountClaim.findUniqueOrThrow({ where: { id: c.id } });
    revalidatePath("/connections");
    revalidatePath("/welcome");
    if (setting("CLAIMS_AUTO_APPROVE") === "true" && fresh.visible) {
      await approveClaim(fresh.id);
      return "ok:Added — the data is loading now.";
    }
    return fresh.visible
      ? "ok:Gildana can see this account. It will appear here as soon as Gildana approves it."
      : "ok:Request saved. Gildana can't see the account yet — finish sharing it (steps above); it updates automatically.";
  } catch (e) {
    return `error:${(e as Error).message}`;
  }
}

export async function decideClaimAction(form: FormData) {
  await requireAdmin();
  const id = String(form.get("id"));
  if (form.get("decision") === "approve") await approveClaim(id);
  else await rejectClaim(id, String(form.get("note") ?? ""));
  revalidatePath("/admin/access");
}

// ───────── Sign-up and onboarding ─────────

const signups = new Map<string, number[]>();

const signupSchema = z.object({
  name: z.string().trim().min(1, "Enter your name").max(120),
  workspace: z.string().trim().min(2, "Enter your business name").max(160),
  email: z.string().trim().toLowerCase().email("Enter a valid email"),
  password: z.string(),
});

export async function signupAction(_: string | null, form: FormData): Promise<string | null> {
  await loadSettings();
  if (!flag("SIGNUP_ENABLED", false)) return "Sign-up is closed. Ask Gildana for an invitation.";
  const addr = await ip();
  const now = Date.now();
  const recent = (signups.get(addr) ?? []).filter((t) => now - t < 3600_000);
  if (recent.length >= 5) return "Too many sign-ups from this network. Try again later.";
  signups.set(addr, [...recent, now]);

  const d = signupSchema.safeParse(Object.fromEntries(form));
  if (!d.success) return d.error.issues[0].message;
  const problem = passwordProblem(d.data.password, String(form.get("confirm") ?? ""));
  if (problem) return problem;
  if (await db.user.findUnique({ where: { email: d.data.email } })) return "That email already has an account — sign in, or use Forgot password.";

  const isAgency = form.get("isAgency") === "on";
  await db.$transaction(async (tx) => {
    const client = await tx.client.create({ data: { name: d.data.workspace, isAgency, reportingCurrency: String(form.get("currency") || "USD").slice(0, 3).toUpperCase() } });
    if (!isAgency) await tx.company.create({ data: { clientId: client.id, name: d.data.workspace } });
    await tx.user.create({ data: { email: d.data.email, name: d.data.name, role: "CLIENT", clientId: client.id, passwordHash: await bcrypt.hash(d.data.password, 12) } });
  });
  try {
    await signIn("credentials", { email: d.data.email, password: d.data.password, redirectTo: "/welcome" });
  } catch (e) {
    if (e instanceof AuthError) redirect("/login");
    throw e;
  }
  return null;
}

export async function finishOnboardingAction() {
  const v = await requireWorkspace();
  await db.client.update({ where: { id: v.clientId }, data: { onboardedAt: new Date() } });
  redirect("/overview");
}

// ───────── Invitations ─────────

const inviteSchema = z.object({ email: z.string().trim().toLowerCase().email(), name: z.string().trim().max(120), role: z.enum(["CLIENT", "VIEWER"]) });

/** Creates the user and sends a 7-day link to set a password. Returns the link when email isn't set up. */
export async function inviteUserAction(_: string | null, form: FormData): Promise<string> {
  await requireAdmin();
  const clientId = String(form.get("clientId"));
  const client = await db.client.findUnique({ where: { id: clientId } });
  if (!client) return "error:Client not found.";
  const d = inviteSchema.safeParse({ email: form.get("email"), name: form.get("name") ?? "", role: form.get("role") || "CLIENT" });
  if (!d.success) return "error:Enter a valid email.";
  let user = await db.user.findUnique({ where: { email: d.data.email } });
  if (user && user.clientId !== clientId) return "error:That email already belongs to another workspace.";
  user ??= await db.user.create({ data: { email: d.data.email, name: d.data.name, role: d.data.role, clientId, passwordHash: await bcrypt.hash(randomToken(24), 12) } });
  const token = await createResetToken(user.id, await ip(), "invite");
  const link = `${process.env.APP_URL ?? "http://localhost:3000"}/reset-password?token=${encodeURIComponent(token)}`;
  revalidatePath(`/admin/clients/${clientId}`);
  if (!mailEnabled()) return `link:${link}`;
  try {
    await sendMail({
      to: user.email,
      subject: `You're invited to ${client.name} on Gildana Insights`,
      text: `Hello${d.data.name ? ` ${d.data.name}` : ""},\n\nGildana has set up a dashboard for ${client.name}. Set your password to get started (the link works for 7 days):\n\n${link}\n\nAfter signing in you can connect your ad accounts in a few clicks.\n\nGildana Insights`,
      html: `<p>Hello${d.data.name ? ` ${d.data.name.replace(/[<>&]/g, "")}` : ""},</p><p>Gildana has set up a dashboard for <b>${client.name.replace(/[<>&]/g, "")}</b>. Set your password to get started (the link works for 7 days):</p><p><a href="${link}">Set my password</a></p><p>After signing in you can connect your ad accounts in a few clicks.</p><p>Gildana Insights</p>`,
    });
    return `ok:Invitation sent to ${user.email}.`;
  } catch (e) {
    return `link:${link}|Email failed (${(e as Error).message}). Send this link yourself:`;
  }
}

