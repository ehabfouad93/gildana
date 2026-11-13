import "server-only";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import type { Platform, Prisma } from "@prisma/client";
import { auth } from "@/auth";
import { db } from "@/lib/db";
import { loadSettings } from "@/lib/settings";
import { hasFeature, hasModule, homePath, type FeatureKey, type ModuleKey } from "@/lib/modules";

export type Access = { companyIds: string[]; accountIds: string[] };

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
  /**
   * What a limited user may see inside the client: whole companies plus single
   * ad accounts. null = everything (owners, Gildana staff, unrestricted users).
   */
  access: Access | null;
  /** Companies the user can see at all (only meaningful when `access` is set). */
  companyIds: string[];
  /** Sections the user may open (empty = all). */
  modules: string[];
  /** Sub-features switched off for the user (see FEATURES). */
  featuresOff: string[];
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
  // Owners and Gildana staff always see the whole workspace and every section.
  const unrestricted = isGildana || u.role === "OWNER";
  const access: Access | null = !unrestricted && (u.companyIds.length || u.accountIds.length) ? { companyIds: u.companyIds, accountIds: u.accountIds } : null;
  let companyIds: string[] = [];
  if (access) {
    const extra = access.accountIds.length
      ? await db.adAccount.findMany({ where: { id: { in: access.accountIds }, companyId: { not: null } }, select: { companyId: true }, distinct: ["companyId"] })
      : [];
    companyIds = [...new Set([...access.companyIds, ...extra.map((a) => a.companyId!)])];
  }
  return {
    id: u.id, role: u.role, email: u.email, name: u.name, locale: u.locale, clientId, isAdmin, isGildana,
    access, companyIds, modules: unrestricted ? [] : u.modules, featuresOff: unrestricted ? [] : u.featuresOff, clientIds: u.clientIds,
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
  return v.access ? { id: { in: v.companyIds } } : {};
}

/** Same, for rows that carry a companyId (sites, budgets, share links). */
export function companyIdWhere(v: Viewer): { companyId?: { in: string[] } } {
  return v.access ? { companyId: { in: v.companyIds } } : {};
}

/** The companies a viewer may see, or null for all — for helpers that take a list. */
export function visibleCompanies(v: Viewer): string[] | null {
  return v.access ? v.companyIds : null;
}

export function canSeeCompany(v: Viewer, companyId: string): boolean {
  return !v.access || v.companyIds.includes(companyId);
}

/** Prisma filter for the ad accounts an access grant covers. */
export function accessWhere(a: Access | null | undefined): Prisma.AdAccountWhereInput {
  if (!a) return {};
  return { OR: [{ companyId: { in: a.companyIds } }, { id: { in: a.accountIds } }] };
}

/** Share links a limited user may see: whole companies they were given, or campaigns in their ad accounts. */
export async function shareWhere(v: Viewer & { clientId: string }): Promise<Prisma.ShareLinkWhereInput> {
  if (!v.access) return { clientId: v.clientId };
  const campaigns = await db.campaign.findMany({ where: { account: accountWhere(v.clientId, { access: v.access }) }, select: { id: true } });
  return { clientId: v.clientId, OR: [{ campaignId: null, companyId: { in: v.access.companyIds } }, { campaignId: { in: campaigns.map((c) => c.id) } }] };
}

/** Whether a viewer may open a section. */
export function canUse(v: Viewer, m: ModuleKey): boolean {
  return hasModule(v.modules, m);
}

/** Whether a sub-feature is switched on for the viewer (its section must be open to them too). */
export function canFeature(v: Viewer, f: FeatureKey): boolean {
  const m = f.split(".")[0];
  return hasFeature(v.featuresOff, f) && (m === "general" || canUse(v, m as ModuleKey));
}

/** A workspace page in section `m`; anyone without it goes to the first section they have. */
export async function requireModule(m: ModuleKey): Promise<Viewer & { clientId: string }> {
  const v = await requireWorkspace();
  if (!canUse(v, m)) redirect(homePath(v.modules));
  return v;
}

export type Filters = { companyId?: string; platform?: Platform; accountId?: string; accountIds?: string[]; campaignId?: string; access?: Access | null };

/**
 * The ad accounts a query may touch. Every warehouse read goes through this,
 * so tenancy is enforced in one place rather than in each page.
 */
export function accountWhere(clientId: string, f: Filters = {}): Prisma.AdAccountWhereInput {
  const and: Prisma.AdAccountWhereInput[] = [];
  // A limited user never sees accounts outside their grant.
  if (f.access) and.push(accessWhere(f.access));
  // Every connected account is reported; "none" picks the ones not yet put in a company.
  if (f.companyId === "none") and.push({ companyId: null });
  else if (f.companyId) and.push({ companyId: f.companyId });
  return {
    connection: { clientId },
    enabled: true,
    ...(f.platform ? { platform: f.platform } : {}),
    ...(f.accountId ? { id: f.accountId } : f.accountIds?.length ? { id: { in: f.accountIds } } : {}),
    ...(and.length ? { AND: and } : {}),
  };
}

export async function scopedAccountIds(clientId: string, f: Filters = {}): Promise<string[]> {
  const rows = await db.adAccount.findMany({ where: accountWhere(clientId, f), select: { id: true } });
  return rows.map((r) => r.id);
}
