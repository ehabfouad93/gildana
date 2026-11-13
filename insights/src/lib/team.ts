import "server-only";
import type { User } from "@prisma/client";
import type { ManagedUser, PickAccount, PickCompany } from "@/components/user-manager";
import { db } from "@/lib/db";
import { FEATURES, MODULES } from "@/lib/modules";
import { PLATFORM_META } from "@/lib/platforms";

/** Users as the team table shows them. */
export function toManagedUsers(users: User[]): ManagedUser[] {
  return users.map((u) => ({
    id: u.id, email: u.email, name: u.name, role: u.role, active: u.active,
    lastLogin: u.lastLoginAt ? u.lastLoginAt.toISOString().slice(0, 16).replace("T", " ") : null,
    companyIds: u.companyIds, accountIds: u.accountIds, modules: u.modules, featuresOff: u.featuresOff, clientIds: u.clientIds,
  }));
}

/** What a client user can be given: companies with their ad accounts, loose accounts, and sections. */
export async function teamPickers(clientId: string): Promise<{ companies: PickCompany[]; unassigned: PickAccount[]; modules: { key: string; label: string }[]; features: { key: string; module: string; label: string }[] }> {
  const [companies, accounts] = await Promise.all([
    db.company.findMany({ where: { clientId }, orderBy: { name: "asc" }, select: { id: true, name: true } }),
    db.adAccount.findMany({ where: { connection: { clientId } }, orderBy: [{ platform: "asc" }, { name: "asc" }], select: { id: true, name: true, platform: true, externalId: true, companyId: true } }),
  ]);
  const pick = (a: (typeof accounts)[number]): PickAccount => ({ id: a.id, name: a.name, platform: PLATFORM_META[a.platform].label, externalId: a.externalId });
  return {
    companies: companies.map((c) => ({ ...c, accounts: accounts.filter((a) => a.companyId === c.id).map(pick) })),
    unassigned: accounts.filter((a) => !a.companyId).map(pick),
    modules: MODULES.map((m) => ({ key: m.key, label: m.label })),
    features: FEATURES.map((f) => ({ key: f.key, module: f.module, label: f.label })),
  };
}
