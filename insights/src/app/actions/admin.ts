"use server";

import bcrypt from "bcryptjs";
import { revalidatePath } from "next/cache";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { z } from "zod";
import { db } from "@/lib/db";
import { WORKSPACE_COOKIE, requireAdmin } from "@/lib/tenancy";

const clientSchema = z.object({
  name: z.string().trim().min(1).max(160),
  isAgency: z.boolean(),
  reportingCurrency: z.string().trim().length(3).toUpperCase(),
  timezone: z.string().trim().min(1).max(64),
});

export async function createClientAction(form: FormData) {
  await requireAdmin();
  const d = clientSchema.parse({ name: form.get("name"), isAgency: form.get("isAgency") === "on", reportingCurrency: form.get("currency") || "USD", timezone: form.get("timezone") || "Africa/Cairo" });
  const client = await db.client.create({ data: d });
  // A brand client starts with one company of the same name; agencies add their own.
  if (!d.isAgency) await db.company.create({ data: { clientId: client.id, name: d.name } });
  redirect(`/admin/clients/${client.id}`);
}

export async function updateClientAction(form: FormData) {
  await requireAdmin();
  const d = clientSchema.parse({ name: form.get("name"), isAgency: form.get("isAgency") === "on", reportingCurrency: form.get("currency") || "USD", timezone: form.get("timezone") || "Africa/Cairo" });
  await db.client.update({ where: { id: String(form.get("id")) }, data: { ...d, active: form.get("active") === "on" } });
  revalidatePath("/admin/clients");
}

export async function deleteClientAction(form: FormData) {
  await requireAdmin();
  if (form.get("confirm") !== "DELETE") throw new Error("Type DELETE to confirm");
  await db.client.delete({ where: { id: String(form.get("id")) } });
  redirect("/admin/clients");
}

const userSchema = z.object({
  email: z.string().trim().toLowerCase().email(),
  name: z.string().trim().max(120),
  password: z.string().min(8, "Password must be at least 8 characters"),
  role: z.enum(["ADMIN", "CLIENT", "VIEWER"]),
});

export async function createUserAction(form: FormData) {
  await requireAdmin();
  const d = userSchema.parse({ email: form.get("email"), name: form.get("name") ?? "", password: form.get("password"), role: form.get("role") || "CLIENT" });
  const clientId = d.role === "ADMIN" ? null : String(form.get("clientId") || "") || null;
  if (d.role !== "ADMIN" && !clientId) throw new Error("Client users need a client");
  await db.user.create({ data: { email: d.email, name: d.name, role: d.role, clientId, passwordHash: await bcrypt.hash(d.password, 12) } });
  revalidatePath("/admin/clients");
}

export async function resetPasswordAction(form: FormData) {
  await requireAdmin();
  const pw = String(form.get("password") ?? "");
  if (pw.length < 8) throw new Error("Password must be at least 8 characters");
  await db.user.update({ where: { id: String(form.get("userId")) }, data: { passwordHash: await bcrypt.hash(pw, 12) } });
  revalidatePath("/admin/clients");
}

export async function toggleUserAction(form: FormData) {
  const me = await requireAdmin();
  const id = String(form.get("userId"));
  if (id === me.id) throw new Error("You cannot disable yourself");
  const u = await db.user.findUniqueOrThrow({ where: { id } });
  await db.user.update({ where: { id }, data: { active: !u.active } });
  revalidatePath("/admin/clients");
}

export async function openWorkspaceAction(form: FormData) {
  await requireAdmin();
  const id = String(form.get("clientId"));
  await db.client.findUniqueOrThrow({ where: { id } });
  (await cookies()).set(WORKSPACE_COOKIE, id, { httpOnly: true, sameSite: "lax", secure: process.env.NODE_ENV === "production", path: "/" });
  redirect("/overview");
}

export async function exitWorkspaceAction() {
  await requireAdmin();
  (await cookies()).delete(WORKSPACE_COOKIE);
  redirect("/admin/clients");
}
