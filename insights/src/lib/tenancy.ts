import "server-only";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import type { Platform, Prisma } from "@prisma/client";
import { auth } from "@/auth";
import { db } from "@/lib/db";
import { loadSettings } from "@/lib/settings";

export type Viewer = {
  id: string;
  role: "ADMIN" | "CLIENT" | "VIEWER";
  email: string;
  name: string;
  locale: string;
  /** The client whose data is on screen: the user's own, or the one an admin opened. */
  clientId: string | null;
  isAdmin: boolean;
};

export const WORKSPACE_COOKIE = "gi_ws";

export async function getViewer(): Promise<Viewer | null> {
  await loadSettings();
  const s = await auth();
  if (!s?.user?.id) return null;
  const isAdmin = s.user.role === "ADMIN";
  let clientId = s.user.clientId;
  if (isAdmin) {
    const ws = (await cookies()).get(WORKSPACE_COOKIE)?.value ?? null;
    clientId = ws;
  }
  return {
    id: s.user.id, role: s.user.role, email: s.user.email ?? "", name: s.user.name ?? "",
    locale: s.user.locale, clientId, isAdmin,
  };
}

export async function requireViewer(): Promise<Viewer> {
  const v = await getViewer();
  if (!v) redirect("/login");
  return v;
}

export async function requireAdmin(): Promise<Viewer> {
  const v = await requireViewer();
  if (!v.isAdmin) redirect("/overview");
  return v;
}

/** A viewer inside a client workspace. Admins without one are sent to pick a client. */
export async function requireWorkspace(): Promise<Viewer & { clientId: string }> {
  const v = await requireViewer();
  if (!v.clientId) redirect(v.isAdmin ? "/admin/clients" : "/login");
  return v as Viewer & { clientId: string };
}

export function canEdit(v: Viewer): boolean {
  return v.role !== "VIEWER";
}

export type Filters = { companyId?: string; platform?: Platform; accountId?: string; campaignId?: string };

/**
 * The ad accounts a query may touch. Every warehouse read goes through this,
 * so tenancy is enforced in one place rather than in each page.
 */
export function accountWhere(clientId: string, f: Filters = {}): Prisma.AdAccountWhereInput {
  return {
    connection: { clientId },
    enabled: true,
    companyId: f.companyId ? f.companyId : { not: null },
    ...(f.platform ? { platform: f.platform } : {}),
    ...(f.accountId ? { id: f.accountId } : {}),
  };
}

export async function scopedAccountIds(clientId: string, f: Filters = {}): Promise<string[]> {
  const rows = await db.adAccount.findMany({ where: accountWhere(clientId, f), select: { id: true } });
  return rows.map((r) => r.id);
}
