import "server-only";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import type { Platform, Prisma } from "@prisma/client";
import { auth } from "@/auth";
import { db } from "@/lib/db";
import { loadSettings } from "@/lib/settings";

export type RoleName = "ADMIN" | "STAFF" | "OWNER" | "CLIENT" | "VIEWER";

export type Viewer = {
  id: string;
  role: RoleName;
  email: string;
  name: string;
  locale: string;
  /** The client whose data is on screen: the user's own, or the one Gildana staff opened. */
  clientId: string | null;
  /** Full Gildana admin: every client, platforms, staff. */
  isAdmin: boolean;
  /** Any Gildana user (admin or account manager). */
  isGildana: boolean;
  /** Companies this user is limited to inside the client (empty = all). */
  companyIds: string[];
  /** STAFF: the clients they may open. */
  clientIds: string[];
};

export const ROLE_LABEL: Record<RoleName, string> = {
  ADMIN: "Gildana admin", STAFF: "Account manager", OWNER: "Owner", CLIENT: "Manager", VIEWER: "Viewer (read-only)",
};

/** Role choices with what each may do, for the team pages. */
export const CLIENT_ROLE_OPTIONS = [
  { value: "OWNER", label: "Owner", hint: "Everything, plus the team and workspace settings." },
  { value: "CLIENT", label: "Manager", hint: "Connects ad accounts, manages companies, sites and reports." },
  { value: "VIEWER", label: "Viewer (read-only)", hint: "Sees dashboards and reports; can't change anything." },
];
export const GILDANA_ROLE_OPTIONS = [
  { value: "ADMIN", label: "Gildana admin", hint: "Every client, platform keys, staff and settings." },
  { value: "STAFF", label: "Account manager", hint: "Only the clients assigned below: their dashboards, settings and users." },
];

export const WORKSPACE_COOKIE = "gi_ws";

/**
 * The signed-in user, read fresh from the database on every request so a role
 * change, a company restriction, a disabled or deleted user applies at once.
 */
export async function getViewer(): Promise<Viewer | null> {
  await loadSettings();
  const s = await auth();
  if (!s?.user?.id) return null;
  const u = await db.user.findUnique({ where: { id: s.user.id } });
  if (!u || !u.active) return null;
  const isAdmin = u.role === "ADMIN";
  const isGildana = isAdmin || u.role === "STAFF";
  let clientId = u.clientId;
  if (isGildana) {
    const ws = (await cookies()).get(WORKSPACE_COOKIE)?.value ?? null;
    clientId = ws && (isAdmin || u.clientIds.includes(ws)) ? ws : null;
  }
  return {
    id: u.id, role: u.role, email: u.email, name: u.name, locale: u.locale, clientId, isAdmin, isGildana,
    companyIds: isGildana ? [] : u.companyIds, clientIds: u.clientIds,
  };
}

export async function requireViewer(): Promise<Viewer> {
  const v = await getViewer();
  if (!v) redirect("/login");
  return v;
}

/** Full Gildana admins only (platforms, staff, access requests, all clients). */
export async function requireAdmin(): Promise<Viewer> {
  const v = await requireViewer();
  if (!v.isAdmin) redirect(v.isGildana ? "/admin/clients" : "/overview");
  return v;
}

/** Gildana admins and account managers. */
export async function requireGildana(): Promise<Viewer> {
  const v = await requireViewer();
  if (!v.isGildana) redirect("/overview");
  return v;
}

/** Whether a Gildana user may look after this client. */
export function canManageClient(v: Viewer, clientId: string): boolean {
  return v.isAdmin || (v.role === "STAFF" && v.clientIds.includes(clientId));
}

/** A viewer inside a client workspace. Gildana staff without one are sent to pick a client. */
export async function requireWorkspace(): Promise<Viewer & { clientId: string }> {
  const v = await requireViewer();
  if (!v.clientId) redirect(v.isGildana ? "/admin/clients" : "/login");
  return v as Viewer & { clientId: string };
}

export function canEdit(v: Viewer): boolean {
  return v.role !== "VIEWER";
}

/** Team and workspace settings: the client's owners, and Gildana staff looking after it. */
export function canManageTeam(v: Viewer): boolean {
  return v.role === "OWNER" || (!!v.clientId && canManageClient(v, v.clientId));
}

/** Prisma filter for the companies a viewer may see inside their client. */
export function companyWhere(v: Viewer): Prisma.CompanyWhereInput {
  return v.companyIds.length ? { id: { in: v.companyIds } } : {};
}

export type Filters = { companyId?: string; platform?: Platform; accountId?: string; accountIds?: string[]; campaignId?: string; allowedCompanyIds?: string[] };

/**
 * The ad accounts a query may touch. Every warehouse read goes through this,
 * so tenancy is enforced in one place rather than in each page.
 */
export function accountWhere(clientId: string, f: Filters = {}): Prisma.AdAccountWhereInput {
  return {
    connection: { clientId },
    enabled: true,
    // Every connected account is reported; "none" picks the ones not yet put in a company.
    // A user limited to some companies never sees other companies' or unassigned accounts.
    ...(f.allowedCompanyIds?.length
      ? { companyId: f.companyId && f.companyId !== "none" && f.allowedCompanyIds.includes(f.companyId) ? f.companyId : { in: f.allowedCompanyIds } }
      : f.companyId === "none" ? { companyId: null } : f.companyId ? { companyId: f.companyId } : {}),
    ...(f.platform ? { platform: f.platform } : {}),
    ...(f.accountId ? { id: f.accountId } : f.accountIds?.length ? { id: { in: f.accountIds } } : {}),
  };
}

export async function scopedAccountIds(clientId: string, f: Filters = {}): Promise<string[]> {
  const rows = await db.adAccount.findMany({ where: accountWhere(clientId, f), select: { id: true } });
  return rows.map((r) => r.id);
}
