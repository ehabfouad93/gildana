"use server";

import bcrypt from "bcryptjs";
import type { Role } from "@prisma/client";
import { revalidatePath } from "next/cache";
import { cookies, headers } from "next/headers";
import { redirect } from "next/navigation";
import { z } from "zod";
import { db } from "@/lib/db";
import { randomToken } from "@/lib/crypto";
import { mailEnabled, sendMail } from "@/lib/mail";
import { FEATURE_KEYS, MODULE_KEYS } from "@/lib/modules";
import { createResetToken, passwordProblem } from "@/lib/password-reset";
import { WORKSPACE_COOKIE, canManageClient, requireAdmin, requireGildana, requireViewer, type Viewer } from "@/lib/tenancy";

/**
 * Clients, users and staff. Every action checks who may do it:
 *   Gildana admin    — everything
 *   Account manager  — the clients assigned to them, and those clients' users
 *   Client owner     — their own workspace's users and settings
 * Actions return "ok:…" / "error:…" for the forms, rather than throwing.
 */

export type Result = string | null;
const ok = (m: string) => `ok:${m}`;
const fail = (m: string) => `error:${m}`;

const CLIENT_ROLES: Role[] = ["OWNER", "CLIENT", "VIEWER"];
const GILDANA_ROLES: Role[] = ["ADMIN", "STAFF"];

function refresh(clientId?: string | null) {
  revalidatePath("/admin/clients");
  revalidatePath("/admin/team");
  revalidatePath("/team");
  if (clientId) revalidatePath(`/admin/clients/${clientId}`);
}

/** May `v` manage the users of this client? */
function mayManageClientUsers(v: Viewer, clientId: string): boolean {
  return canManageClient(v, clientId) || (v.role === "OWNER" && v.clientId === clientId);
}

/** May `v` change or remove this user? */
async function editableUser(v: Viewer, userId: string) {
  const u = await db.user.findUnique({ where: { id: userId } });
  if (!u) return { error: "User not found." } as const;
  if (u.clientId ? !mayManageClientUsers(v, u.clientId) : !v.isAdmin) return { error: "You can't manage this user." } as const;
  return { user: u } as const;
}

/** Never leave a client without an owner, or Gildana without an admin. */
async function wouldOrphan(u: { id: string; role: Role; clientId: string | null; active: boolean }): Promise<string | null> {
  if (u.role === "OWNER" && u.clientId && u.active) {
    const others = await db.user.count({ where: { clientId: u.clientId, role: "OWNER", active: true, id: { not: u.id } } });
    if (!others) return "This is the workspace's only owner — make someone else an owner first.";
  }
  if (u.role === "ADMIN" && u.active) {
    const others = await db.user.count({ where: { role: "ADMIN", active: true, id: { not: u.id } } });
    if (!others) return "This is the only Gildana admin — add another admin first.";
  }
  return null;
}

// ───────── clients ─────────

const clientSchema = z.object({
  name: z.string().trim().min(1, "Enter a name").max(160),
  isAgency: z.boolean(),
  reportingCurrency: z.string().trim().length(3, "Currency is a 3-letter code, e.g. USD").toUpperCase(),
  timezone: z.string().trim().min(1).max(64),
});

export async function createClientAction(form: FormData) {
  const v = await requireGildana();
  const d = clientSchema.parse({ name: form.get("name"), isAgency: form.get("isAgency") === "on", reportingCurrency: form.get("currency") || "USD", timezone: form.get("timezone") || "Africa/Cairo" });
  const client = await db.client.create({ data: d });
  // A brand client starts with one company of the same name; agencies add their own.
  if (!d.isAgency) await db.company.create({ data: { clientId: client.id, name: d.name } });
  // An account manager looks after the clients they create.
  if (v.role === "STAFF") await db.user.update({ where: { id: v.id }, data: { clientIds: { push: client.id } } });
  redirect(`/admin/clients/${client.id}`);
}

/** Workspace settings: Gildana staff for the client, or the client's owner. */
export async function updateClientAction(_: Result, form: FormData): Promise<Result> {
  const v = await requireViewer();
  const id = String(form.get("id"));
  if (!mayManageClientUsers(v, id)) return fail("You can't change this workspace's settings.");
  const parsed = clientSchema.safeParse({ name: form.get("name"), isAgency: form.get("isAgency") === "on", reportingCurrency: form.get("currency") || "USD", timezone: form.get("timezone") || "Africa/Cairo" });
  if (!parsed.success) return fail(parsed.error.issues[0].message);
  const days = Math.min(365, Math.max(1, Number(form.get("defaultDays")) || 30));
  await db.client.update({
    where: { id },
    data: {
      ...parsed.data, defaultDays: days, campaignCurrency: form.get("campaignCurrency") === "reporting" ? "reporting" : "account", logoUrl: String(form.get("logoUrl") ?? "").trim().slice(0, 500),
      // Only Gildana can switch a workspace off.
      ...(v.isGildana ? { active: form.get("active") === "on" } : {}),
    },
  });
  refresh(id);
  revalidatePath("/settings");
  return ok("Saved.");
}

export async function deleteClientAction(form: FormData) {
  await requireAdmin();
  if (form.get("confirm") !== "DELETE") throw new Error("Type DELETE to confirm");
  await db.client.delete({ where: { id: String(form.get("id")) } });
  redirect("/admin/clients");
}

export async function openWorkspaceAction(form: FormData) {
  const v = await requireGildana();
  const id = String(form.get("clientId"));
  if (!canManageClient(v, id)) redirect("/admin/clients");
  await db.client.findUniqueOrThrow({ where: { id } });
  (await cookies()).set(WORKSPACE_COOKIE, id, { httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", path: "/" });
  redirect("/overview");
}

export async function exitWorkspaceAction() {
  await requireGildana();
  (await cookies()).delete(WORKSPACE_COOKIE);
  redirect("/admin/clients");
}

// ───────── users ─────────

const newUserSchema = z.object({
  email: z.string().trim().toLowerCase().email("Enter a valid email"),
  name: z.string().trim().max(120),
  role: z.enum(["ADMIN", "STAFF", "OWNER", "CLIENT", "VIEWER"]),
});

/**
 * Adds a user — to a client (clientId set) or to Gildana's team (no clientId,
 * admins only). With a password it is set directly; without one an invitation
 * link is emailed, or returned to copy when email isn't set up.
 */
export async function addUserAction(_: Result, form: FormData): Promise<Result> {
  const v = await requireViewer();
  const clientId = String(form.get("clientId") ?? "") || null;
  const d = newUserSchema.safeParse({ email: form.get("email"), name: form.get("name") ?? "", role: form.get("role") || (clientId ? "CLIENT" : "STAFF") });
  if (!d.success) return fail(d.error.issues[0].message);
  if (clientId ? !mayManageClientUsers(v, clientId) || !CLIENT_ROLES.includes(d.data.role) : !v.isAdmin || !GILDANA_ROLES.includes(d.data.role)) return fail("You can't add this kind of user here.");
  if (await db.user.findUnique({ where: { email: d.data.email } })) return fail("That email already has an account.");

  const password = String(form.get("password") ?? "");
  if (password) {
    const problem = passwordProblem(password);
    if (problem) return fail(problem);
  }
  const grant = clientId ? await clientGrant(clientId, d.data.role, form) : { companyIds: [], accountIds: [], modules: [], featuresOff: [] };
  if (typeof grant === "string") return fail(grant);
  const clientIds = !clientId && d.data.role === "STAFF" ? await validClients(form.getAll("access").map(String)) : [];
  const user = await db.user.create({
    data: { email: d.data.email, name: d.data.name, role: d.data.role, clientId, ...grant, clientIds, passwordHash: await bcrypt.hash(password || randomToken(24), 12) },
  });
  refresh(clientId);
  if (password) return ok(`${user.email} added — they can sign in now.`);
  return invite(user, clientId);
}

const esc = (t: string) => t.replace(/[<>&"]/g, "");

/**
 * Emails a one-time link to set a password: an invitation for someone who has
 * never signed in, a reset link otherwise. Returns the link to copy when email
 * isn't set up or fails.
 */
async function invite(user: { id: string; email: string; name: string; lastLoginAt?: Date | null }, clientId: string | null): Promise<Result> {
  const isInvite = !user.lastLoginAt;
  const ip = ((await headers()).get("x-forwarded-for") ?? "").split(",")[0].trim();
  const token = await createResetToken(user.id, ip, isInvite ? "invite" : "reset");
  const link = `${process.env.APP_URL ?? "http://localhost:3000"}/reset-password?token=${encodeURIComponent(token)}`;
  if (!mailEnabled()) return `link:${link}|Email isn't set up, so send this link yourself (${isInvite ? "valid 7 days" : "valid 1 hour"}):`;
  const where = clientId ? (await db.client.findUnique({ where: { id: clientId }, select: { name: true } }))?.name ?? "your workspace" : "Gildana's team";
  const hello = `Hello${user.name ? ` ${user.name}` : ""},`;
  const body = isInvite
    ? `You've been invited to ${where} on Gildana Insights. Set your password to get started (the link works for 7 days):`
    : "Use this link to choose a new password for Gildana Insights (it works for 1 hour):";
  try {
    await sendMail({
      to: user.email,
      subject: isInvite ? `You're invited to ${where} on Gildana Insights` : "Set a new Gildana Insights password",
      text: `${hello}\n\n${body}\n\n${link}\n\nGildana Insights`,
      html: `<p>${esc(hello)}</p><p>${esc(body)}</p><p><a href="${link}">${isInvite ? "Set my password" : "Choose a new password"}</a></p><p>Gildana Insights</p>`,
    });
    return ok(`${isInvite ? "Invitation" : "Password link"} sent to ${user.email}.`);
  } catch (e) {
    return `link:${link}|Email failed (${(e as Error).message}). Send this link yourself:`;
  }
}

/** Emails (or returns) a link to set a password. */
export async function sendPasswordLinkAction(_: Result, form: FormData): Promise<Result> {
  const v = await requireViewer();
  const r = await editableUser(v, String(form.get("userId")));
  if ("error" in r) return fail(r.error!);
  return invite(r.user, r.user.clientId);
}

async function validCompanies(clientId: string, ids: string[]): Promise<string[]> {
  if (!ids.length) return [];
  return (await db.company.findMany({ where: { clientId, id: { in: ids } }, select: { id: true } })).map((c) => c.id);
}

/**
 * What a client user may see and open, from the Team form: all ad accounts or
 * ticked companies / single accounts, and all sections or ticked ones.
 * Owners always get everything. Returns an error message when nothing is ticked.
 */
async function clientGrant(clientId: string, role: Role, form: FormData): Promise<{ companyIds: string[]; accountIds: string[]; modules: string[]; featuresOff: string[] } | string> {
  if (role === "OWNER") return { companyIds: [], accountIds: [], modules: [], featuresOff: [] };
  let companyIds: string[] = [];
  let accountIds: string[] = [];
  if (form.get("accessMode") === "some") {
    companyIds = await validCompanies(clientId, form.getAll("access").map(String));
    // Accounts inside a ticked company are already covered.
    const picked = await db.adAccount.findMany({ where: { id: { in: form.getAll("accounts").map(String) }, connection: { clientId } }, select: { id: true, companyId: true } });
    accountIds = picked.filter((a) => !a.companyId || !companyIds.includes(a.companyId)).map((a) => a.id);
    if (!companyIds.length && !accountIds.length) return "Tick at least one company or ad account, or choose “All ad accounts”.";
  }
  let modules: string[] = [];
  if (form.get("modulesMode") === "some") {
    modules = form.getAll("modules").map(String).filter((m) => MODULE_KEYS.includes(m));
    if (!modules.length) return "Tick at least one section, or choose “All sections”.";
    if (modules.length === MODULE_KEYS.length) modules = [];
  }
  // Features: the form lists every feature with a tick box; unticked ones are switched off.
  const on = new Set(form.getAll("featureOn").map(String));
  const featuresOff = form.has("featuresShown") ? FEATURE_KEYS.filter((f) => !on.has(f)) : [];
  return { companyIds, accountIds, modules, featuresOff };
}

async function validClients(ids: string[]): Promise<string[]> {
  if (!ids.length) return [];
  return (await db.client.findMany({ where: { id: { in: ids } }, select: { id: true } })).map((c) => c.id);
}

/** Name, role, company access (client users) or assigned clients (account managers), and active. */
export async function updateUserAction(_: Result, form: FormData): Promise<Result> {
  const v = await requireViewer();
  const r = await editableUser(v, String(form.get("userId")));
  if ("error" in r) return fail(r.error!);
  const u = r.user;
  const role = String(form.get("role") || u.role) as Role;
  const allowed = u.clientId ? CLIENT_ROLES : GILDANA_ROLES;
  if (!allowed.includes(role)) return fail("That role isn't available for this user.");
  const active = form.has("activeField") ? form.get("active") === "on" : u.active;
  if (u.id === v.id && (role !== u.role || !active)) return fail("You can't change your own role or switch yourself off.");
  if ((role !== u.role || !active) && (await wouldOrphan(u))) return fail((await wouldOrphan(u))!);
  const grant = u.clientId ? await clientGrant(u.clientId, role, form) : null;
  if (typeof grant === "string") return fail(grant);

  await db.user.update({
    where: { id: u.id },
    data: {
      name: String(form.get("name") ?? u.name).trim().slice(0, 120), role, active,
      ...(grant ?? {}),
      ...(!u.clientId && v.isAdmin ? { clientIds: role === "STAFF" ? await validClients(form.getAll("access").map(String)) : [] } : {}),
    },
  });
  refresh(u.clientId);
  return ok("Saved.");
}

export async function setPasswordAction(_: Result, form: FormData): Promise<Result> {
  const v = await requireViewer();
  const r = await editableUser(v, String(form.get("userId")));
  if ("error" in r) return fail(r.error!);
  const pw = String(form.get("password") ?? "");
  const problem = passwordProblem(pw);
  if (problem) return fail(problem);
  await db.user.update({ where: { id: r.user.id }, data: { passwordHash: await bcrypt.hash(pw, 12) } });
  return ok("Password changed.");
}

export async function deleteUserAction(_: Result, form: FormData): Promise<Result> {
  const v = await requireViewer();
  const r = await editableUser(v, String(form.get("userId")));
  if ("error" in r) return fail(r.error!);
  if (r.user.id === v.id) return fail("You can't delete yourself.");
  const orphan = await wouldOrphan(r.user);
  if (orphan) return fail(orphan);
  await db.user.delete({ where: { id: r.user.id } });
  refresh(r.user.clientId);
  return ok(`${r.user.email} deleted.`);
}
